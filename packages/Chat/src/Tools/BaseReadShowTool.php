<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Enums\CustomFieldType;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Support\RecordReferenceResolver;
use Relaticle\CustomFields\Models\CustomFieldValue;
use stdClass;

abstract class BaseReadShowTool implements Tool
{
    /**
     * Recent related records returned per include. Mirrors the limit the
     * old record-summary context builder used, keeping prompt size bounded
     * on accounts with hundreds of notes.
     */
    private const int INCLUDE_LIMIT = 10;

    /**
     * Most business content in this CRM lives in custom fields (note body,
     * task status, opportunity stage). `FormatsCustomFields` serialises an
     * empty object when this chain is not loaded, so included records need
     * it as much as the parent record does.
     */
    private const string CUSTOM_FIELD_RELATION = 'customFieldValues.customField.options';

    /**
     * Custom field types whose values are free-form prose that can run to
     * kilobytes of markup (rich text) or long paragraphs (plain long text).
     * Values on these fields are stripped of markup and capped for included
     * (related) records — everything else (numbers, dates, booleans, choice
     * labels, short text) passes through untouched. Mirrors the old
     * `RecordContextBuilder::stripHtml()` behaviour.
     *
     * @var list<string>
     */
    private const array FREE_TEXT_FIELD_TYPES = [
        CustomFieldType::RICH_EDITOR->value,
        CustomFieldType::MARKDOWN_EDITOR->value,
        CustomFieldType::TEXTAREA->value,
    ];

    private const int FREE_TEXT_LIMIT = 500;

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** @return class-string<JsonResource> */
    abstract protected function resourceClass(): string;

    abstract protected function entityLabel(): string;

    abstract protected function citationType(): string;

    abstract public function description(): string;

    /** @return array<int, string> */
    protected function eagerLoad(): array
    {
        return [self::CUSTOM_FIELD_RELATION];
    }

    /**
     * Related collections this tool can return under `included`, keyed by
     * relation name, valued by the resource used to serialise each item.
     *
     * @return array<string, class-string<JsonResource>>
     */
    protected function availableIncludes(): array
    {
        return [];
    }

    public function schema(JsonSchema $schema): array
    {
        $label = strtolower($this->entityLabel());

        $fields = [
            'id' => $schema->string()->description("The {$label} ID to retrieve.")->required(),
        ];

        $includes = array_keys($this->availableIncludes());

        if ($includes !== []) {
            $valid = implode(', ', $includes);

            $fields['include'] = $schema->array()
                ->items($schema->string())
                ->description(
                    "Related records to return under `included`. Valid values: {$valid}. "
                    .'Request everything you need in ONE call rather than making follow-up list calls. '
                    .'Each collection returns up to '.self::INCLUDE_LIMIT.' most recent items plus a true total.'
                );
        }

        return $fields;
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        $id = $request->string('id');
        $modelClass = $this->modelClass();
        $model = $modelClass::query()
            ->whereBelongsTo($user->currentTeam)
            ->whereKey($id)
            ->first();

        if (! $model instanceof Model) {
            return (string) json_encode(['error' => "{$this->entityLabel()} with ID [{$id}] not found."]);
        }

        if ($user->cannot('view', $model)) {
            return (string) json_encode(['error' => "You do not have permission to view this {$this->entityLabel()}."]);
        }

        $model->loadMissing($this->eagerLoad());

        $requestedIncludes = $this->normalizeIncludes($request['include'] ?? null);

        $unknown = array_diff($requestedIncludes, array_keys($this->availableIncludes()));

        if ($unknown !== []) {
            $valid = implode(', ', array_keys($this->availableIncludes()));

            return (string) json_encode([
                'error' => 'Unknown include(s): '.implode(', ', $unknown).'. Valid values for this tool: '.($valid === '' ? '(none)' : $valid).'.',
            ]);
        }

        /** @var class-string<JsonResource> $resourceClass */
        $resourceClass = $this->resourceClass();

        $payload = $this->flattenResource(new $resourceClass($model));

        $id = (string) $model->getKey();
        $ref = resolve(RecordReferenceResolver::class)->resolve($this->citationType(), $id);

        return (string) json_encode(
            array_merge(
                $payload,
                $this->extraPayload($model),
                ['url' => $ref['url'] ?? null],
                $this->buildIncluded($model, $requestedIncludes, $user),
            ),
            JSON_PRETTY_PRINT,
        );
    }

    /**
     * Fields the JSON:API resource omits on this non-HTTP path (it only
     * renders relationships requested via an `include` query param, which the
     * chat tools never send). Merged on top of the resource payload.
     *
     * @return array<string, mixed>
     */
    protected function extraPayload(Model $model): array
    {
        return [];
    }

    /**
     * `JsonApiResource::resolve()` nests the resource object under a `data`
     * key. Flatten it so `id`, `type`, and `attributes` sit alongside the
     * tool's own `url` and `included` keys, giving the whole payload one
     * shape. Resources that do not wrap are returned untouched.
     *
     * @return array<string, mixed>
     */
    private function flattenResource(JsonResource $resource): array
    {
        /** @var array<string, mixed> $resolved */
        $resolved = $resource->resolve();

        $data = $resolved['data'] ?? null;

        if (! is_array($data)) {
            return $resolved;
        }

        /** @var array<string, mixed> $data */
        return $data;
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
     * @param  list<string>  $includes
     * @return array<string, array<string, array{total: int, showing: int, items: list<mixed>}>>
     */
    private function buildIncluded(Model $model, array $includes, User $user): array
    {
        if ($includes === []) {
            return [];
        }

        $available = $this->availableIncludes();
        $included = [];

        foreach ($includes as $relationName) {
            $model->loadCount($relationName);
            $model->load([$relationName => function (Relation $query) use ($user): void {
                $orderColumn = $query->getRelated()->getQualifiedCreatedAtColumn();

                $query->whereBelongsTo($user->currentTeam)
                    ->with(self::CUSTOM_FIELD_RELATION)
                    ->latest($orderColumn)
                    ->limit(self::INCLUDE_LIMIT);
            }]);

            /** @var class-string<JsonResource> $resourceClass */
            $resourceClass = $available[$relationName];

            /** @var iterable<int, Model> $related */
            $related = $model->getRelation($relationName);

            $items = [];

            foreach ($related as $item) {
                $items[] = $this->stripFreeTextCustomFields(
                    $this->flattenResource(new $resourceClass($item)),
                    $item,
                );
            }

            $totalAttribute = $model->getAttribute(Str::snake($relationName).'_count');

            $included[$relationName] = [
                'total' => is_numeric($totalAttribute) ? (int) $totalAttribute : count($items),
                'showing' => count($items),
                'items' => $items,
            ];
        }

        return ['included' => $included];
    }

    /**
     * Strip HTML and cap the length of free-text custom field values on an
     * included (related) record. Ten related notes with kilobytes of
     * rich-text body each would otherwise blow up the tool payload that gets
     * persisted to `tool_results` and replayed on every subsequent turn.
     *
     * Only applied to included records, not the primary record a tool call
     * targets — a direct "get this note" call is the user asking for that
     * note's full content, and should return it verbatim.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stripFreeTextCustomFields(array $payload, Model $model): array
    {
        $attributes = $payload['attributes'] ?? null;

        if (! $attributes instanceof stdClass) {
            return $payload;
        }

        $customFields = $attributes->custom_fields ?? null;

        if (! $customFields instanceof stdClass) {
            return $payload;
        }

        if (! $model->relationLoaded('customFieldValues')) {
            return $payload;
        }

        /** @var iterable<int, CustomFieldValue> $customFieldValues */
        $customFieldValues = $model->getRelation('customFieldValues');

        foreach ($customFieldValues as $fieldValue) {
            if (! isset($fieldValue->getRelations()['customField'])) {
                continue;
            }

            if (! in_array($fieldValue->customField->type, self::FREE_TEXT_FIELD_TYPES, true)) {
                continue;
            }

            $code = $fieldValue->customField->code;

            if (! isset($customFields->{$code}) || ! is_string($customFields->{$code})) {
                continue;
            }

            $customFields->{$code} = $this->truncateFreeText($customFields->{$code});
        }

        return $payload;
    }

    private function truncateFreeText(string $html): string
    {
        $text = strip_tags($html);

        return Str::limit(trim((string) preg_replace('/\s+/', ' ', $text)), self::FREE_TEXT_LIMIT);
    }
}
