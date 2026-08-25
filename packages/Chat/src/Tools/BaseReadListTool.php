<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Models\CustomField;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
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
     * Columns carried by the block: the entity's core name/title column plus up
     * to five custom-field columns, wide enough for the fields a team marks
     * visible and narrow enough for a chat bubble.
     */
    private const int BLOCK_COLUMN_LIMIT = 6;

    /**
     * Characters kept per table cell. Tighter than the record card's cap: a cell
     * is one line of a table row, not a record summary.
     */
    private const int CELL_VALUE_LIMIT = 120;

    /**
     * Related records attached per row under `included` and as a chip column.
     * Tighter than the show tool's INCLUDE_LIMIT: a list block already carries
     * a full page of rows, so each row's own chip column stays to a glance.
     */
    private const int INCLUDE_ITEM_LIMIT = 3;

    /**
     * Page cap for every list call, with or without an include. A `data` record
     * costs roughly 101 tokens on every subsequent turn (it is replayed, unlike
     * the one-turn `display_block`), so page size is a permanent prompt-cache
     * budget, not a UX knob. A full page of rows times every available relation
     * times INCLUDE_ITEM_LIMIT compounds that cost further for an include-bearing
     * call. `total` still reports the real count, so the model can say it is
     * showing a subset, and `has_more`/`next_page` say whether one exists.
     */
    private const int MAX_PER_PAGE = 25;

    /** @return class-string */
    abstract protected function actionClass(): string;

    /** @return class-string<JsonResource> */
    abstract protected function resourceClass(): string;

    abstract protected function searchFilterName(): string;

    abstract protected function citationType(): string;

    abstract public function description(): string;

    /** @return array<string, mixed> */
    protected function additionalSchema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * Related collections rows may carry under `included`, keyed by relation
     * name, valued by the resource the sibling Get*Tool serialises the same
     * relation with. Only the relation names are read here (an allowlist for
     * `include`); the list tool's own chips are lighter than a full resource.
     * Mirrors the sibling Get*Tool's allowlist. Empty = no include support.
     *
     * @return array<string, class-string<JsonResource>>
     */
    protected function availableIncludes(): array
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
        $entityType = $this->citationType();

        $customFieldsDescription = 'Filter by custom field values.';
        $sortable = $this->nativeSorts();

        if ($user instanceof User) {
            $describer = resolve(CustomFieldsFilterDescriber::class);
            $customFieldsDescription = $describer->describe($user, $entityType);
            $sortable = array_merge($sortable, $describer->sortableCodes($user, $entityType));
        }

        $fields = array_merge(
            ['search' => $schema->string()->description("Search by {$this->searchFilterName()}.")],
            $this->additionalSchema($schema),
            [
                'created_after' => $schema->string()->description('Only return records created on or after this date (YYYY-MM-DD).'),
                'created_before' => $schema->string()->description('Only return records created on or before this date (YYYY-MM-DD).'),
                'custom_fields' => $schema->object()->description($customFieldsDescription),
                'sort' => $schema->string()->description(
                    'Sort by one of: '.implode(', ', $sortable).'. Prefix with "-" for descending (e.g. "-created_at").',
                ),
                'per_page' => $schema->integer()->description('Results per page (default 10, max 25).')->default(10),
                'page' => $schema->integer()->description('Page number.')->default(1),
                'lookup' => $schema->boolean()->description('Set true when you call this only to find ids for another tool call (e.g. before an update or delete): the user then sees no table. Leave unset when the user asked to see the records.'),
            ],
        );

        $includes = $this->availableIncludes();

        if ($includes !== []) {
            $valid = implode(', ', array_keys($includes));

            $fields['include'] = $schema->array()
                ->items($schema->string())
                ->description(
                    "Related records to attach per row under `included` and as a chip column in the table. Valid values: {$valid}. "
                    .'Only the '.self::INCLUDE_ITEM_LIMIT.' most recent related records per row are attached; `total` inside each row\'s `included` entry gives the real count.'
                );
        }

        return $fields;
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        $availableIncludes = array_keys($this->availableIncludes());
        $requestedIncludes = $this->normalizeIncludes($request['include'] ?? null);
        $unknown = array_diff($requestedIncludes, $availableIncludes);

        if ($unknown !== []) {
            $valid = implode(', ', $availableIncludes);

            return (string) json_encode([
                'error' => 'Unknown include(s): '.implode(', ', $unknown).'. Valid values for this tool: '.($valid === '' ? '(none)' : $valid).'.',
            ], JSON_UNESCAPED_SLASHES);
        }

        try {
            $httpRequest = $this->buildHttpRequest($user, $request);
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        }

        try {
            $action = app()->make($this->actionClass());
            $results = $action->execute(
                user: $user,
                perPage: $this->perPageFor($request),
                page: isset($request['page']) ? (int) $request['page'] : null,
                request: $httpRequest,
            );
        } catch (InvalidQuery $e) {
            return (string) json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
        }

        // Loaded onto the paginator's own model instances (not a copy), so the
        // display block below and the `included` payload further down read the
        // same eager-loaded relations without a second query.
        if ($requestedIncludes !== [] && $results instanceof LengthAwarePaginator) {
            $this->loadIncludes($results, $requestedIncludes, $user);
        }

        // Captured before the resource collection is built: wrapping a paginator
        // in a resource collection replaces its items with resource instances,
        // and the display block reads stored custom-field values off the models.
        $block = $results instanceof LengthAwarePaginator && ! $this->isLookup($request)
            ? $this->buildDisplayBlock($user, $results, $request, $requestedIncludes)
            : null;

        // Same reason as the block above: this has to be read before the
        // resource collection replaces the paginator's items with resource
        // instances, or `$models[$index] instanceof Model` below is always
        // false and `included` silently never attaches.
        $models = array_values($results->items());

        /** @var class-string<JsonResource> $resourceClass */
        $resourceClass = $this->resourceClass();
        $collection = $resourceClass::collection($results);

        $items = json_decode($collection->toJson(), true);

        if (! is_array($items)) {
            return $collection->toJson(JSON_UNESCAPED_SLASHES);
        }

        $citationType = $this->citationType();
        $resolver = resolve(RecordReferenceResolver::class);
        $linkable = in_array($citationType, RecordReferenceResolver::CHIP_TYPES, true);

        $items = array_map(function (mixed $item, int $index) use ($citationType, $resolver, $linkable, $models, $requestedIncludes): mixed {
            if (! is_array($item)) {
                return $item;
            }

            $id = isset($item['id']) && (is_string($item['id']) || is_int($item['id']))
                ? (string) $item['id']
                : null;

            // resolve() was called here purely as a null check while referenceUrl()
            // supplied the value, and resolve() issues a label query per call. At
            // MAX_PER_PAGE that is 25 discarded SELECTs per list call. Whether a type
            // is linkable is a constant, so it is decided once outside the loop.
            $item['url'] = $id !== null && $linkable
                ? $resolver->referenceUrl($citationType, $id)
                : null;

            if ($requestedIncludes !== [] && isset($models[$index]) && $models[$index] instanceof Model) {
                $item['included'] = $this->includedFor($models[$index], $requestedIncludes, $resolver);
            }

            return $item;
        }, $items, array_keys($items));

        $payload = [
            'data' => $items,
            'total' => $results->total(),
            'showing' => count($items),
            'has_more' => $results instanceof LengthAwarePaginator && $results->hasMorePages(),
            'next_page' => $results instanceof LengthAwarePaginator && $results->hasMorePages()
                ? $results->currentPage() + 1
                : null,
        ];

        if ($block !== null) {
            $payload['display_block'] = $block;
        }

        return (string) json_encode($this->localiseDatetimes($payload, $user), JSON_UNESCAPED_SLASHES);
    }

    private function perPageFor(Request $request): int
    {
        return max(1, min((int) ($request['per_page'] ?? 10), self::MAX_PER_PAGE));
    }

    /**
     * @return list<string>
     */
    private function normalizeIncludes(mixed $include): array
    {
        if (! is_array($include)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $include),
            static fn (string $value): bool => $value !== '',
        )));
    }

    /**
     * Eager-loads each requested relation across the whole result page in one
     * query per relation, scoped to the user's team and ordered by recency.
     *
     * The `->limit()` inside the closure is per PARENT, not per result set:
     * a *Many relation compiles it through Builder::groupLimit(), which emits
     * `row_number() over (partition by <fk>)`, so every row gets its own slice.
     * Without it a company with 500 notes would hydrate all 500 just to count
     * them. The true count comes from loadCount(), which is a separate
     * aggregate and therefore unaffected by the slice.
     *
     * @param  LengthAwarePaginator<int, Model>  $results
     * @param  list<string>  $includes
     */
    private function loadIncludes(LengthAwarePaginator $results, array $includes, User $user): void
    {
        $models = new Collection(array_values($results->items()));

        if ($models->isEmpty()) {
            return;
        }

        $team = $user->currentTeam;

        foreach ($includes as $relation) {
            $models->load([$relation => function (Relation $query) use ($team): void {
                $orderColumn = $query->getRelated()->getQualifiedCreatedAtColumn();

                $query->whereBelongsTo($team)->latest($orderColumn)->limit(self::INCLUDE_ITEM_LIMIT);
            }]);

            // Counted with the same team scope as the load above: an unscoped
            // count would describe a cross-team related record the items list
            // omits, so one relation would report two different totals.
            $models->loadCount([$relation => $this->scopeToTeam($team)]);
        }
    }

    /**
     * Team-scoping constraint shared by the include load and its count.
     *
     * Typed `mixed` deliberately: load() hands the callback a Relation and
     * loadCount() hands it an Eloquent Builder, and both accept whereBelongsTo.
     */
    private function scopeToTeam(?Team $team): callable
    {
        return static function (mixed $query) use ($team): void {
            $query->whereBelongsTo($team);
        };
    }

    /**
     * The lightweight `included` entry a list row carries per relation: an id,
     * a name, and a url per related record, plus the true total. Unlike the
     * show tool's `included` (a full serialised record, because that tool
     * answers "show me everything about this one record"), a list row is
     * already one of many and only needs enough to link out.
     *
     * @param  list<string>  $includes
     * @return array<string, array{total: int, showing: int, items: list<array{id: string, name: string, url: string}>}>
     */
    private function includedFor(Model $model, array $includes, RecordReferenceResolver $resolver): array
    {
        $included = [];

        foreach ($includes as $relation) {
            if (! $model->relationLoaded($relation)) {
                continue;
            }

            $citationType = $this->includeCitationType($relation);

            /** @var Collection<int, Model> $related */
            $related = $model->getRelation($relation);

            $items = array_values($related->take(self::INCLUDE_ITEM_LIMIT)->map(static fn (Model $m): array => [
                'id' => (string) $m->getKey(),
                'name' => (string) ($m->getAttribute('name') ?? $m->getAttribute('title') ?? ''),
                'url' => $resolver->referenceUrl($citationType, (string) $m->getKey()),
            ])->all());

            // From loadCount(), never $related->count(): the eager load is
            // sliced to INCLUDE_ITEM_LIMIT per row, so counting the loaded
            // collection would cap every total at the slice size.
            $included[$relation] = [
                'total' => (int) $model->getAttribute(Str::snake($relation).'_count'),
                'showing' => count($items),
                'items' => $items,
            ];
        }

        return $included;
    }

    /**
     * Maps a relation name to the citation type its chips and reference urls
     * use. Most relation names already match the corresponding citation type;
     * this only exists for the few that don't (a plural relation name versus
     * a singular record type).
     */
    private function includeCitationType(string $relation): string
    {
        return match ($relation) {
            'people' => 'people',
            'opportunities' => 'opportunity',
            'tasks' => 'task',
            'notes' => 'note',
            'companies', 'company' => 'company',
            default => $relation,
        };
    }

    private function isLookup(Request $request): bool
    {
        return filter_var($request['lookup'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * The presentation envelope the chat UI renders as a real table. Kept
     * alongside the model-facing `data` rows rather than replacing them, and
     * stripped from the replayed history (see SupersededAwareConversationStore)
     * because the model reasons over `data`, never over this.
     *
     * @param  LengthAwarePaginator<int, Model>  $results
     * @param  list<string>  $includes
     * @return array<string, mixed>|null
     */
    private function buildDisplayBlock(User $user, LengthAwarePaginator $results, Request $request, array $includes): ?array
    {
        $records = array_values($results->items());

        if ($records === []) {
            return null;
        }

        $team = $user->currentTeam;
        $entityType = $this->citationType();
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
        // Include (chip) columns count against the same budget as the field columns:
        // they were previously spread in unbounded, so a tool allowing four includes
        // could emit ten columns in a chat bubble the constant exists to keep narrow.
        $includeBudget = min(count($includes), max(0, self::BLOCK_COLUMN_LIMIT - 1));
        $fieldBudget = max(0, self::BLOCK_COLUMN_LIMIT - 1 - $includeBudget);

        $promoted = array_slice($promoted, 0, $fieldBudget);
        $derived = array_slice($derived, 0, $fieldBudget - count($promoted));
        $includes = array_slice($includes, 0, $includeBudget);

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
                ...$this->includeColumns($includes),
            ],
            'rows' => $this->blockRows($records, [...$promoted, ...$derived], $coreKey, $includes),
            'total' => $results->total(),
            // The rows are this PAGE's, not the result set's. Without the offset
            // the footer renders "Showing 25 of 200" over records 101-125.
            'from' => $results->firstItem() ?? 0,
            ...$this->openUrlFor($user, $results),
        ];
    }

    /**
     * `open_url` only earns a place in the block when there is somewhere further
     * to send the user: a next page exists and the entity has a list page to
     * send them to. Returned as a spreadable array rather than a nullable value
     * so the key is omitted entirely rather than carried as `null`, which the
     * partial reads defensively.
     *
     * @param  LengthAwarePaginator<int, Model>  $results
     * @return array{open_url?: string}
     */
    private function openUrlFor(User $user, LengthAwarePaginator $results): array
    {
        if (! $results->hasMorePages()) {
            return [];
        }

        $url = resolve(RecordReferenceResolver::class)->indexUrlFor($this->citationType(), $user->currentTeam);

        return $url === null ? [] : ['open_url' => $url];
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
     * @param  list<string>  $includes
     * @return list<array{key: string, label: string}>
     */
    private function includeColumns(array $includes): array
    {
        return array_map(
            static fn (string $relation): array => ['key' => '_include_'.$relation, 'label' => Str::headline($relation)],
            $includes,
        );
    }

    /**
     * @param  list<Model>  $records
     * @param  list<CustomField>  $fields
     * @param  list<string>  $includes
     * @return list<array{id: string, url: string, cells: array<string, mixed>}>
     */
    private function blockRows(array $records, array $fields, string $coreKey, array $includes): array
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

            foreach ($includes as $relation) {
                $cells['_include_'.$relation] = $this->includeChips($record, $relation, $resolver);
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
     * The chip list a `_include_<relation>` cell carries: up to
     * INCLUDE_ITEM_LIMIT related records, each a label/url/type triple the
     * table partial renders as a `chat-chip` link, matching the core column's
     * own chip markup.
     *
     * @return list<array{label: string, url: string, type: string}>
     */
    private function includeChips(Model $record, string $relation, RecordReferenceResolver $resolver): array
    {
        if (! $record->relationLoaded($relation)) {
            return [];
        }

        $citationType = $this->includeCitationType($relation);

        /** @var Collection<int, Model> $related */
        $related = $record->getRelation($relation);

        return array_values($related->take(self::INCLUDE_ITEM_LIMIT)->map(fn (Model $m): array => [
            'label' => (string) ($m->getAttribute('name') ?? $m->getAttribute('title') ?? ''),
            'url' => $resolver->referenceUrl($citationType, (string) $m->getKey()),
            'type' => $citationType,
        ])->all());
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
        $customFields = $request['custom_fields'] ?? null;

        $codes = is_array($customFields) ? array_map(strval(...), array_keys($customFields)) : [];

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
            ->translate($user, $this->citationType(), $request['custom_fields'] ?? null);

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
