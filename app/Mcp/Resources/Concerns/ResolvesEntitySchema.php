<?php

declare(strict_types=1);

namespace App\Mcp\Resources\Concerns;

use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Mcp\Schema\McpSchemaCache;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Relaticle\CustomFields\Models\CustomFieldRelationship;
use Relaticle\CustomFields\Services\ValidationService;

trait ResolvesEntitySchema
{
    protected function resolveCustomFields(User $user, string $entityType): object
    {
        $teamId = $user->currentTeam->getKey();
        $cacheKey = McpSchemaCache::entitySchemaKey($teamId, $entityType);

        return (object) Cache::remember($cacheKey, McpSchemaCache::TTL, function () use ($teamId, $entityType): array {
            $fields = CustomField::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $teamId)
                ->where('entity_type', $entityType)
                ->active()
                ->select('id', 'code', 'name', 'type', 'validation_rules')
                ->with(['options:id,custom_field_id,name,settings'])
                ->get();

            return $this->formatCustomFields($fields);
        });
    }

    protected function resolveFilterableFields(User $user, string $entityType): object
    {
        return (object) (new CustomFieldFilterSchema)->build($user, $entityType);
    }

    private const CHOICE_TYPES = ['select', 'status', 'radio', 'multi-select', 'checkbox-list', 'tags-input', 'toggle-buttons'];

    /**
     * @param  Collection<int, CustomField>  $fields
     * @return array<string, array<string, mixed>>
     */
    private function formatCustomFields(Collection $fields): array
    {
        $result = [];

        foreach ($fields as $field) {
            // The package owns this predicate. Three hand-rolled copies of it
            // existed and only some were right: validation_rules casts to a
            // key-value collection (['required' => true]), so the older
            // ['name' => 'required'] scan matched nothing and told every agent
            // no custom field was ever required.
            $required = resolve(ValidationService::class)->isRequired($field);

            $entry = [
                'name' => $field->name,
                'type' => $field->type,
                'required' => $required,
            ];

            $definition = $field->relationshipDefinition();

            if ($definition instanceof CustomFieldRelationship) {
                $result[$field->code] = [...$entry, ...$this->linkVocabulary($field, $definition)];

                continue;
            }

            $formatHint = $this->fieldFormatHint($field->type);

            if ($formatHint !== null) {
                $entry['input_format'] = $formatHint['format'];
                $entry['example'] = $formatHint['example'];
            }

            if (in_array($field->type, self::CHOICE_TYPES, true) && $field->options->isNotEmpty()) {
                $entry['options'] = $field->options->map(fn (CustomFieldOption $option): array => [
                    'id' => $option->id,
                    'label' => $option->name,
                    'category' => $option->settings->category?->value,
                ])->all();
            }

            $result[$field->code] = $entry;
        }

        return $result;
    }

    /**
     * What a link field points at, said in the relationship's own vocabulary so a client
     * can reason about both ends: the entity, how many records this side holds, and the
     * definition the far end reads the same edges through.
     *
     * @return array<string, mixed>
     */
    private function linkVocabulary(CustomField $field, CustomFieldRelationship $definition): array
    {
        $farFieldId = $definition->directionFor($field) === CustomFieldRelationship::DIRECTION_FROM
            ? $definition->to_field_id
            : $definition->from_field_id;

        $farField = $farFieldId === null || (string) $farFieldId === (string) $field->getKey()
            ? null
            : CustomField::query()->withoutGlobalScopes()->find($farFieldId);

        return [
            'input_format' => $field->allowsMultipleRecords()
                ? 'array of record IDs'
                : 'array holding at most one record ID',
            'example' => ['01JJXYZ123ABC456DEF789GHI'],
            'target_entity' => $definition->targetEntityTypeFor($field),
            'multiple' => $field->allowsMultipleRecords(),
            'relationship' => [
                'code' => $definition->code,
                'cardinality' => $definition->cardinality->value,
                'symmetric' => $definition->is_symmetric,
                'from_entity' => $definition->from_entity_type,
                'to_entity' => $definition->to_entity_type,
                'other_end_field' => $farField?->code,
            ],
        ];
    }

    /**
     * @return array{format: string, example: mixed}|null
     */
    private function fieldFormatHint(string $type): ?array
    {
        return match ($type) {
            'link' => ['format' => 'array of URL strings', 'example' => ['https://example.com']],
            'email' => ['format' => 'array of email strings', 'example' => ['user@example.com']],
            'phone' => ['format' => 'array of phone strings', 'example' => ['+1234567890']],
            'select', 'status', 'radio', 'toggle-buttons' => ['format' => 'option ID string (see options)', 'example' => 'option-id-here'],
            'multi-select', 'checkbox-list' => ['format' => 'array of option ID strings', 'example' => ['option-id-1', 'option-id-2']],
            'tags-input' => ['format' => 'array of arbitrary string values', 'example' => ['priority', 'customer']],
            'toggle' => ['format' => 'boolean', 'example' => true],
            'date-time' => ['format' => 'ISO 8601 datetime string', 'example' => '2025-01-15T10:30:00Z'],
            'number' => ['format' => 'numeric value', 'example' => 42],
            'currency' => ['format' => 'numeric value (amount)', 'example' => 15000.00],
            default => null,
        };
    }
}
