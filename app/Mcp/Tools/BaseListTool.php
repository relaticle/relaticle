<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Data\ListQuery;
use App\Enums\CrmEntity;
use App\Mcp\Schema\CustomFieldSchema;
use App\Mcp\Tools\Concerns\BoundsToManyIncludes;
use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Mcp\Tools\Concerns\HasReadOnlyToolAnnotations;
use App\Mcp\Tools\Concerns\SerializesRelatedModels;
use App\Models\User;
use App\Queries\CustomFieldFilterSchema;
use App\Queries\EntityFilters;
use App\Queries\FilterErrors;
use App\Queries\FilterTree;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

    /** @var list<string> */
    private const array ARGUMENTS = ['filter', 'sort', 'include', 'per_page', 'page'];

    abstract protected function entity(): CrmEntity;

    /** @return class-string<JsonResource> */
    abstract protected function resourceClass(): string;

    public function schema(JsonSchema $schema): array
    {
        return [
            'filter' => $schema->object()->description(EntityFilters::grammar($this->entity()).' '.CustomFieldSchema::filterGuide(resolve(GetCrmSchemaTool::class)->name()).' Example: '.CustomFieldFilterSchema::json(EntityFilters::example($this->entity())).'.'),
            'sort' => $schema->object()->description('Sort by field. Properties: field (string), direction (asc|desc).'),
            'include' => $schema->array()->description('Singular relationships or relationship counts to expand. Use a show tool for to-many records.'),
            'per_page' => $schema->integer()->description('Results per page (default 15, max 25).')->default(15),
            'page' => $schema->integer()->description('Page number (max '.number_format(ListQuery::MAX_PAGE).').')->default(1),
        ];
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

        $validated = $request->validate([
            'filter' => ['sometimes', $this->objectRule()],
            'sort' => ['sometimes', 'array:field,direction', 'required_array_keys:field'],
            'sort.field' => ['string'],
            'sort.direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1', 'max:'.ListQuery::MAX_PAGE],
            'include' => ['sometimes', 'array', 'list', 'max:20'],
            'include.*' => ['string', 'distinct'],
        ]);

        $requestedIncludes = $request->get('include');
        $toManyIncludes = is_array($requestedIncludes) ? $this->toManyIncludes($requestedIncludes) : [];

        if ($toManyIncludes !== []) {
            return Response::error(sprintf(
                'List tools do not expand to-many relationships [%s]. Use a show tool for one record, or request the corresponding Count include.',
                implode(', ', $toManyIncludes),
            ));
        }

        FilterTree::rejectUnknownArguments($request->all(), self::ARGUMENTS);

        try {
            $results = resolve($this->entity()->query())->paginate($user, $this->listQuery($request, $validated));
        } catch (ValidationException $exception) {
            return Response::error(FilterErrors::located($exception));
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

    private function objectRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            $isObject = is_array($value) && ($value === [] || ! array_is_list($value));

            if (! $isObject) {
                $fail("The {$attribute} field must be an object.");
            }
        };
    }

    /** @param  array<string, mixed>  $validated */
    private function listQuery(Request $request, array $validated): ListQuery
    {
        return new ListQuery(
            filter: FilterTree::trimmed($request->get('filter')),
            sort: $this->sortExpression($request->get('sort')),
            include: $validated['include'] ?? null,
            perPage: (int) ($validated['per_page'] ?? 15),
            page: (int) ($validated['page'] ?? 1),
        );
    }

    private function sortExpression(mixed $sort): ?string
    {
        if (! is_array($sort) || ! isset($sort['field'])) {
            return null;
        }

        $direction = ($sort['direction'] ?? 'asc') === 'desc' ? '-' : '';

        return $direction.$sort['field'];
    }
}
