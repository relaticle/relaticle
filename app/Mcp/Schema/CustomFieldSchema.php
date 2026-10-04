<?php

declare(strict_types=1);

namespace App\Mcp\Schema;

use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\User;
use App\Queries\CustomFieldFilterSchema;
use App\Queries\EntityFilters;
use App\Queries\FilterVocabulary;
use App\Support\CustomFields\CustomFieldOptionMap;
use App\Support\CustomFields\CustomFieldSchemaCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Relaticle\CustomFields\Models\CustomFieldRelationship;
use Relaticle\CustomFields\Services\ValidationService;
use stdClass;

/**
 * The writable custom fields of one entity, as the MCP schema publishes them.
 *
 * The read-path twin is {@see CustomFieldFilterSchema}. Per-type wording lives on
 * {@see CustomFieldType} so the chat tool schemas describe a field the same way.
 */
final readonly class CustomFieldSchema
{
    public function __construct(private FilterVocabulary $vocabulary) {}

    public static function usage(CrmEntity $entity): string
    {
        return implode(' ', [
            'Pass custom field values in the "custom_fields" object using field codes as keys.',
            CustomFieldOptionMap::choiceRule(),
            'Filter list tools with the "filter" param. Names, operands and options are listed in filterable_fields.',
            EntityFilters::CUSTOM_FIELDS_RULE,
            self::filterGuide('This schema'),
            EntityFilters::limits(),
            CustomFieldFilterSchema::generalRules(),
            'Filter example: '.CustomFieldFilterSchema::json(EntityFilters::example($entity)).'.',
        ]);
    }

    public static function filterGuide(string $source): string
    {
        return "Native fields and relations sit at the top level of filter. {$source} lists those codes with their type and options under filterable_fields.custom_fields, and the operators, matching rule and example of each type under filterable_fields.types.";
    }

    public function fields(User $user, CrmEntity $entity): stdClass
    {
        $workspaceId = $user->currentWorkspace->getKey();
        $cacheKey = CustomFieldSchemaCache::entitySchemaKey($workspaceId, $entity->value);

        return (object) Cache::remember($cacheKey, CustomFieldSchemaCache::TTL, function () use ($workspaceId, $entity): array {
            $fields = CustomField::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $workspaceId)
                ->where('entity_type', $entity->value)
                ->active()
                ->select('id', 'code', 'name', 'type', 'validation_rules')
                ->with(['options:id,custom_field_id,name,settings'])
                ->get();

            return $this->format($fields);
        });
    }

    public function filterableFields(User $user, CrmEntity $entity): stdClass
    {
        $vocabulary = $this->vocabulary->for($user, $entity);
        $vocabulary['types'] = (object) $vocabulary['types'];
        $vocabulary['custom_fields'] = (object) $vocabulary['custom_fields'];

        return (object) $vocabulary;
    }

    /**
     * @param  Collection<int, CustomField>  $fields
     * @return array<string, array<string, mixed>>
     */
    private function format(Collection $fields): array
    {
        $result = [];

        foreach ($fields as $field) {
            $type = CustomFieldType::tryFrom($field->type);

            // A retired type keeps its stored rows but loses its case, so it has no
            // write vocabulary to publish. Skipping beats throwing the whole schema away.
            if (! $type instanceof CustomFieldType) {
                continue;
            }

            // validation_rules casts to ['required' => true], so a ['name' => 'required']
            // scan matches nothing and tells every agent no field was ever required.
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

            $entry['input_format'] = $type->inputFormat();
            $entry['example'] = $type->example();

            if ($type->isChoice() && $field->options->isNotEmpty()) {
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
}
