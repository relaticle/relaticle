<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Services\Tools\CustomFieldsFilterDescriber;
use Relaticle\Chat\Services\Tools\CustomFieldsFilterTranslator;
use Relaticle\Chat\Support\RecordReferenceResolver;
use Relaticle\Chat\Tools\Concerns\NormalizesToolInput;
use Relaticle\Chat\Tools\Concerns\ReportsValidationFailures;
use Spatie\QueryBuilder\Exceptions\InvalidQuery;

abstract class BaseReadListTool implements Tool
{
    use NormalizesToolInput;
    use ReportsValidationFailures;

    /** @return class-string */
    abstract protected function actionClass(): string;

    /** @return class-string<JsonResource> */
    abstract protected function resourceClass(): string;

    abstract protected function searchFilterName(): string;

    abstract protected function citationType(): string;

    abstract public function description(): string;

    /**
     * The custom-fields entity alias, which is also the citation/morph alias.
     */
    protected function entityType(): string
    {
        return $this->citationType();
    }

    /** @return array<string, mixed> */
    protected function additionalSchema(JsonSchema $schema): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function additionalFilters(Request $request): array
    {
        return [];
    }

    /**
     * Native columns every entity can be sorted by; custom-field codes are added
     * per tenant.
     *
     * @return list<string>
     */
    protected function nativeSorts(): array
    {
        return [$this->searchFilterName(), 'created_at', 'updated_at'];
    }

    public function schema(JsonSchema $schema): array
    {
        $user = auth()->user();
        $entityType = $this->entityType();

        $customFieldsDescription = 'Filter by custom field values.';
        $sortable = $this->nativeSorts();

        if ($user instanceof User) {
            $describer = resolve(CustomFieldsFilterDescriber::class);
            $customFieldsDescription = $describer->describe($user, $entityType);
            $sortable = array_merge($sortable, $describer->sortableCodes($user, $entityType));
        }

        return array_merge(
            ['search' => $schema->string()->description("Search by {$this->searchFilterName()}.")],
            $this->additionalSchema($schema),
            [
                'created_after' => $schema->string()->description('Only return records created on or after this date (YYYY-MM-DD).'),
                'created_before' => $schema->string()->description('Only return records created on or before this date (YYYY-MM-DD).'),
                'custom_fields' => $schema->object()->description($customFieldsDescription),
                'sort' => $schema->string()->description(
                    'Sort by one of: '.implode(', ', $sortable).'. Prefix with "-" for descending (e.g. "-created_at").',
                ),
                'per_page' => $schema->integer()->description('Results per page (default 15, max 50).')->default(15),
                'page' => $schema->integer()->description('Page number.')->default(1),
            ],
        );
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        try {
            $httpRequest = $this->buildHttpRequest($user, $request);
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        }

        try {
            $action = app()->make($this->actionClass());
            $results = $action->execute(
                user: $user,
                perPage: max(1, min((int) ($request['per_page'] ?? 15), 50)),
                page: isset($request['page']) ? (int) $request['page'] : null,
                request: $httpRequest,
            );
        } catch (InvalidQuery $e) {
            return (string) json_encode(['error' => $e->getMessage()]);
        }

        /** @var class-string<JsonResource> $resourceClass */
        $resourceClass = $this->resourceClass();
        $collection = $resourceClass::collection($results);

        $items = json_decode($collection->toJson(), true);

        if (! is_array($items)) {
            return $collection->toJson(JSON_PRETTY_PRINT);
        }

        $citationType = $this->citationType();
        $items = array_map(function (mixed $item) use ($citationType): mixed {
            if (! is_array($item)) {
                return $item;
            }

            $id = isset($item['id']) && (is_string($item['id']) || is_int($item['id']))
                ? (string) $item['id']
                : null;

            $ref = $id !== null
                ? resolve(RecordReferenceResolver::class)->resolve($citationType, $id)
                : null;

            $item['url'] = $ref['url'] ?? null;

            return $item;
        }, $items);

        return (string) json_encode($items, JSON_PRETTY_PRINT);
    }

    /**
     * @throws ValidationException
     */
    private function buildHttpRequest(User $user, Request $request): HttpRequest
    {
        $input = [];

        $nativeFilters = $this->dropNull(array_merge(
            [
                $this->searchFilterName() => $request['search'] ?? null,
                'created_after' => $request['created_after'] ?? null,
                'created_before' => $request['created_before'] ?? null,
            ],
            $this->additionalFilters($request),
        ));

        $customFields = resolve(CustomFieldsFilterTranslator::class)
            ->translate($user, $this->entityType(), $request['custom_fields'] ?? null);

        if ($customFields !== []) {
            $nativeFilters['custom_fields'] = $customFields;
        }

        if ($nativeFilters !== []) {
            $input['filter'] = $nativeFilters;
        }

        $sort = $request['sort'] ?? null;

        if (is_string($sort) && $sort !== '') {
            $input['sort'] = $sort;
        }

        return new HttpRequest($input);
    }
}
