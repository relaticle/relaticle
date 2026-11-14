<?php

declare(strict_types=1);

namespace App\Mcp\Schema;

use App\Enums\CrmEntity;
use App\Enums\CustomFields\TaskField;
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
    private const string RICH_TEXT_READ_FORMAT = 'stored and returned as HTML';

    public function __construct(private FilterVocabulary $vocabulary) {}

    public static function usage(CrmEntity $entity): string
    {
        return implode(' ', [
            'Pass custom attribute (custom field) values in the "custom_fields" object using field codes as keys.',
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
                ->select('id', 'entity_type', 'code', 'name', 'type', 'validation_rules')
                ->with(['options:id,custom_field_id,name'])
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
                'input_format' => $this->inputFormat($field, $type),
                'example' => $type->example(),
            ];

            if ($type->isChoice() && $field->options->isNotEmpty()) {
                $entry['options'] = $field->options->map(fn (CustomFieldOption $option): array => [
                    'id' => $option->id,
                    'label' => $option->name,
                ])->all();
            }

            $result[$field->code] = $entry;
        }

        return $result;
    }

    private function inputFormat(CustomField $field, CustomFieldType $type): string
    {
        $parts = [$type->inputFormat()];

        if ($type === CustomFieldType::RICH_EDITOR) {
            $parts[] = self::RICH_TEXT_READ_FORMAT;
        }

        if ($field->holdsChecklists()) {
            $parts = [...$parts, TaskField::CHECKLIST_INPUT_FORMAT, TaskField::CHECKLIST_HTML_READ_FORMAT];
        }

        return implode('; ', $parts);
    }
}
