<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\BoundsToManyIncludes;
use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Mcp\Tools\Concerns\HasReadOnlyToolAnnotations;
use App\Mcp\Tools\Concerns\SerializesRelatedModels;
use App\Models\User;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Spatie\QueryBuilder\Exceptions\InvalidQuery;

abstract class BaseListTool extends Tool
{
    use BoundsToManyIncludes;
    use ChecksTokenAbility;
    use HasReadOnlyToolAnnotations;
    use SerializesRelatedModels;

    private const int MAX_PER_PAGE = 25;

    private const int MAX_PAGE = 1_000_000;

    /** @return class-string */
    abstract protected function actionClass(): string;

    /** @return class-string<JsonResource> */
    abstract protected function resourceClass(): string;

    abstract protected function searchFilterName(): string;

    /**
     * @return array<string, mixed>
     */
    protected function additionalSchema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function additionalFilters(Request $request): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function additionalValidationRules(User $user): array
    {
        return [];
    }

    public function schema(JsonSchema $schema): array
    {
        return array_merge(
            ['search' => $schema->string()->description("Search by {$this->searchFilterName()}.")],
            $this->additionalSchema($schema),
            [
                'created_after' => $schema->string()->description('Only return records created on or after this date (YYYY-MM-DD).'),
                'created_before' => $schema->string()->description('Only return records created on or before this date (YYYY-MM-DD).'),
                'filter' => $schema->object()->description('Filter by custom field values. Keys are field codes, values are operator objects (eq, gt, gte, lt, lte, contains, in, has_any).'),
                'sort' => $schema->object()->description('Sort by field. Properties: field (string), direction (asc|desc).'),
                'include' => $schema->array()->description('Singular relationships or relationship counts to expand. Use a show tool for to-many records.'),
                'per_page' => $schema->integer()->description('Results per page (default 15, max 25).')->default(15),
                'page' => $schema->integer()->description('Page number (max 1,000,000).')->default(1),
            ],
        );
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items($schema->object())->required(),
            'page' => $schema->integer()->required(),
            'per_page' => $schema->integer()->required(),
            'total' => $schema->integer()->required(),
            'has_more' => $schema->boolean()->required(),
            'next_page' => $schema->integer()->nullable()->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($denied = $this->denyIfTokenCannot('read')) instanceof Response) {
            return $denied;
        }

        /** @var User $user */
        $user = auth()->user();

        $validated = $request->validate(array_merge([
            'search' => ['sometimes', 'string', 'max:255'],
            'created_after' => ['sometimes', Rule::date()->format('Y-m-d')],
            'created_before' => ['sometimes', Rule::date()->format('Y-m-d'), 'after_or_equal:created_after'],
            'filter' => ['sometimes', $this->objectRule(allowEmpty: true)],
            'filter.*' => [$this->objectRule(allowEmpty: false)],
            'sort' => ['sometimes', 'array:field,direction', 'required_array_keys:field'],
            'sort.field' => ['string'],
            'sort.direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PAGE],
            'include' => ['sometimes', 'array', 'list', 'max:20'],
            'include.*' => ['string', 'distinct'],
        ], $this->additionalValidationRules($user)));

        $requestedIncludes = $request->get('include');
        $toManyIncludes = is_array($requestedIncludes)
            ? $this->toManyIncludes($requestedIncludes)
            : [];

        if ($toManyIncludes !== []) {
            return Response::error(sprintf(
                'List tools do not expand to-many relationships [%s]. Use a show tool for one record, or request the corresponding Count include.',
                implode(', ', $toManyIncludes),
            ));
        }

        $httpRequest = $this->buildHttpRequest($request);

        try {
            $action = app()->make($this->actionClass());
            $results = $action->execute(
                user: $user,
                perPage: (int) ($validated['per_page'] ?? 15),
                page: (int) ($validated['page'] ?? 1),
                request: $httpRequest,
            );
        } catch (InvalidQuery $e) {
            return Response::error($e->getMessage());
        }

        /** @var class-string<JsonResource> $resourceClass */
        $resourceClass = $this->resourceClass();

        $collection = $resourceClass::collection($results);
        $decoded = json_decode($collection->toJson(JSON_PRETTY_PRINT));
        $items = isset($decoded->data) && is_array($decoded->data)
            ? $decoded->data
            : (is_array($decoded) ? $decoded : []);

        $relationshipMap = null;

        foreach (array_keys($items) as $index) {
            $resultItem = $results[$index] ?? null;

            if ($resultItem === null) {
                continue;
            }

            $model = $resultItem instanceof JsonResource ? $resultItem->resource : $resultItem;

            if (! $model instanceof Model) {
                continue;
            }

            foreach ($model->getRelations() as $relation => $relatedData) {
                if ($relation === 'customFieldValues') {
                    continue;
                }

                $relationshipMap ??= $this->resolveRelationshipMap($resourceClass, $model);

                $items[$index]->{$relation} = $this->serializeRelation($model, $relation, $relationshipMap);
            }
        }

        $response = ['items' => $items];

        if ($results instanceof LengthAwarePaginator) {
            $response = array_merge($response, [
                'page' => $results->currentPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
                'has_more' => $results->hasMorePages(),
                'next_page' => $results->hasMorePages() ? $results->currentPage() + 1 : null,
            ]);
        }

        return Response::structured($response);
    }

    private function objectRule(bool $allowEmpty): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($allowEmpty): void {
            $isObject = is_array($value)
                && ($allowEmpty && $value === [] || $value !== [] && ! array_is_list($value));

            if (! $isObject) {
                $fail("The {$attribute} field must be an object.");
            }
        };
    }

    private function buildHttpRequest(Request $mcpRequest): HttpRequest
    {
        $input = [];

        $nativeFilters = array_filter(array_merge(
            [
                $this->searchFilterName() => $mcpRequest->get('search'),
                'created_after' => $mcpRequest->get('created_after'),
                'created_before' => $mcpRequest->get('created_before'),
            ],
            $this->additionalFilters($mcpRequest),
        ));

        if ($nativeFilters !== []) {
            $input['filter'] = $nativeFilters;
        }

        $customFieldFilters = $mcpRequest->get('filter');

        if (is_array($customFieldFilters) && $customFieldFilters !== []) {
            $input['filter']['custom_fields'] = $customFieldFilters;
        }

        $sort = $mcpRequest->get('sort');

        if (is_array($sort) && isset($sort['field'])) {
            $direction = ($sort['direction'] ?? 'asc') === 'desc' ? '-' : '';
            $input['sort'] = $direction.$sort['field'];
        }

        $include = $mcpRequest->get('include');

        if (is_array($include) && $include !== []) {
            $input['include'] = implode(',', $include);
        }

        return new HttpRequest($input);
    }
}
