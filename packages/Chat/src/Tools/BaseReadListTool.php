<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Models\CustomField;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Services\Tools\CustomFieldsDisplayFormatter;
use Relaticle\Chat\Services\Tools\CustomFieldsFilterDescriber;
use Relaticle\Chat\Services\Tools\CustomFieldsFilterTranslator;
use Relaticle\Chat\Services\Tools\DisplayFieldSelector;
use Relaticle\Chat\Support\RecordReferenceResolver;
use Relaticle\Chat\Tools\Concerns\LocalisesDatetimes;
use Relaticle\Chat\Tools\Concerns\NormalizesToolInput;
use Relaticle\Chat\Tools\Concerns\ReportsValidationFailures;
use Spatie\QueryBuilder\Exceptions\InvalidQuery;

abstract class BaseReadListTool implements Tool
{
    use LocalisesDatetimes;
    use NormalizesToolInput;
    use ReportsValidationFailures;

    /**
     * Rows carried by the `records_table` display block. The model still sees
     * the full page under `data`; the block is what the user reads, and a chat
     * bubble that scrolls past ten rows stops being a summary.
     */
    private const int BLOCK_ROW_LIMIT = 10;

    /**
     * Columns carried by the block: the entity's core name/title column plus up
     * to five custom-field columns, wide enough for the fields a team marks
     * visible and narrow enough for a chat bubble.
     */
    private const int BLOCK_COLUMN_LIMIT = 6;

    /**
     * Characters kept per table cell. Tighter than the record card's cap: a cell
     * is one line of a ten-row table, not a record summary.
     */
    private const int CELL_VALUE_LIMIT = 120;

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

        // Captured before the resource collection is built: wrapping a paginator
        // in a resource collection replaces its items with resource instances,
        // and the display block reads stored custom-field values off the models.
        $block = $results instanceof LengthAwarePaginator
            ? $this->buildDisplayBlock($user, $results, $request)
            : null;

        /** @var class-string<JsonResource> $resourceClass */
        $resourceClass = $this->resourceClass();
        $collection = $resourceClass::collection($results);

        $items = json_decode($collection->toJson(), true);

        if (! is_array($items)) {
            return $collection->toJson(JSON_PRETTY_PRINT);
        }

        $citationType = $this->citationType();
        $resolver = resolve(RecordReferenceResolver::class);
        $items = array_map(function (mixed $item) use ($citationType, $resolver): mixed {
            if (! is_array($item)) {
                return $item;
            }

            $id = isset($item['id']) && (is_string($item['id']) || is_int($item['id']))
                ? (string) $item['id']
                : null;

            $item['url'] = null;

            if ($id !== null && $resolver->resolve($citationType, $id) !== null) {
                $item['url'] = $resolver->referenceUrl($citationType, $id);
            }

            return $item;
        }, $items);

        $payload = ['data' => $items];

        if ($block !== null) {
            $payload['display_block'] = $block;
        }

        return (string) json_encode($this->localiseDatetimes($payload, $user), JSON_PRETTY_PRINT);
    }

    /**
     * The presentation envelope the chat UI renders as a real table. Kept
     * alongside the model-facing `data` rows rather than replacing them, and
     * stripped from the replayed history (see SupersededAwareConversationStore)
     * because the model reasons over `data`, never over this.
     *
     * @param  LengthAwarePaginator<int, Model>  $results
     * @return array<string, mixed>|null
     */
    private function buildDisplayBlock(User $user, LengthAwarePaginator $results, Request $request): ?array
    {
        $records = array_slice(array_values($results->items()), 0, self::BLOCK_ROW_LIMIT);

        if ($records === []) {
            return null;
        }

        $team = $user->currentTeam;
        $entityType = $this->entityType();
        $coreKey = $this->searchFilterName();

        // Nothing stops a team from coding a custom field `name` or `title`.
        // Left in, it would emit the core column twice and its cell would
        // overwrite the record's own name, which is the cell the row links from.
        $promoted = array_values(array_filter(
            $this->promotedFields($team, $entityType, $this->promotedCodes($request)),
            static fn (CustomField $field): bool => $field->code !== $coreKey,
        ));
        $promotedCodes = array_map(static fn (CustomField $field): string => $field->code, $promoted);

        $derived = array_values(array_filter(
            resolve(DisplayFieldSelector::class)->listFields($team, $entityType),
            static fn (CustomField $field): bool => $field->code !== $coreKey
                && ! in_array($field->code, $promotedCodes, true),
        ));

        // The core column always survives the cap, so the promoted half is
        // trimmed first and the derived half fills whatever is left.
        $promoted = array_slice($promoted, 0, self::BLOCK_COLUMN_LIMIT - 1);
        $derived = array_slice($derived, 0, self::BLOCK_COLUMN_LIMIT - 1 - count($promoted));

        return [
            'block' => 'records_table',
            'title' => Str::plural(Str::headline($this->citationType())),
            'type' => $this->citationType(),
            // Named, not positional: query-aware promotion moves a filtered or
            // sorted field to the FRONT, so the first column is routinely not
            // the record's own name. This is the column the row links from.
            'core' => $coreKey,
            'columns' => [
                ...$this->fieldColumns($promoted),
                ['key' => $coreKey, 'label' => Str::headline($coreKey)],
                ...$this->fieldColumns($derived),
            ],
            'rows' => $this->blockRows($records, [...$promoted, ...$derived], $coreKey),
            'total' => $results->total(),
        ];
    }

    /**
     * @param  list<CustomField>  $fields
     * @return list<array{key: string, label: string}>
     */
    private function fieldColumns(array $fields): array
    {
        return array_map(
            static fn (CustomField $field): array => ['key' => $field->code, 'label' => $field->name],
            $fields,
        );
    }

    /**
     * @param  list<Model>  $records
     * @param  list<CustomField>  $fields
     * @return list<array{id: string, url: string, cells: array<string, string>}>
     */
    private function blockRows(array $records, array $fields, string $coreKey): array
    {
        $resolver = resolve(RecordReferenceResolver::class);
        $formatter = resolve(CustomFieldsDisplayFormatter::class);
        $citationType = $this->citationType();

        $rows = [];

        foreach ($records as $record) {
            $id = (string) $record->getKey();
            $coreValue = $record->getAttribute($coreKey);
            $cells = [$coreKey => is_scalar($coreValue) ? (string) $coreValue : ''];

            foreach ($formatter->formatStored($record, $fields, self::CELL_VALUE_LIMIT) as $row) {
                $cells[$row['code']] = $row['value'];
            }

            $rows[] = [
                'id' => $id,
                'url' => $resolver->referenceUrl($citationType, $id),
                'cells' => $cells,
            ];
        }

        return $rows;
    }

    /**
     * Field codes this call filtered or sorted on, in the order the tool parsed
     * them. Relevance follows the question: ask for deals closing this month
     * over $50k and close_date/amount lead the table, whatever the team marked
     * visible. Native sorts and codes from another tenant drop out in
     * promotedFields(), which only resolves this team's own fields.
     *
     * @return list<string>
     */
    private function promotedCodes(Request $request): array
    {
        $codes = [];

        $customFields = $request['custom_fields'] ?? null;

        if (is_array($customFields)) {
            foreach (array_keys($customFields) as $code) {
                $codes[] = (string) $code;
            }
        }

        $sort = $request['sort'] ?? null;

        if (is_string($sort) && $sort !== '') {
            $codes[] = ltrim($sort, '-');
        }

        return array_values(array_unique($codes));
    }

    /**
     * Promoted fields are resolved without any visibility filter: a field the
     * team hid from its table is exactly the field the user just asked about.
     *
     * @param  list<string>  $codes
     * @return list<CustomField>
     */
    private function promotedFields(Team $team, string $entityType, array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $fields = CustomField::query()
            ->where('tenant_id', $team->getKey())
            ->where('entity_type', $entityType)
            ->active()
            ->whereIn('code', $codes)
            ->with('options')
            ->get()
            ->keyBy('code');

        $ordered = [];

        foreach ($codes as $code) {
            $field = $fields->get($code);

            if ($field instanceof CustomField) {
                $ordered[] = $field;
            }
        }

        return $ordered;
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
