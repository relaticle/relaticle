<?php

declare(strict_types=1);

namespace App\Mcp\Resources\Concerns;

use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Mcp\Schema\McpSchemaCache;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Relaticle\CustomFields\Services\ValidationService;

trait ResolvesEntitySchema
{
    abstract protected function entity(): CrmEntity;

    protected function resolveCustomFields(User $user): object
    {
        $teamId = $user->currentTeam->getKey();
        $entityType = $this->entity()->value;
        $cacheKey = McpSchemaCache::entitySchemaKey($teamId, $entityType);

        return (object) Cache::remember($cacheKey, McpSchemaCache::TTL, function () use ($teamId, $entityType): array {
            $fields = CustomField::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $teamId)
                ->where('entity_type', $entityType)
                ->active()
                ->select('id', 'code', 'name', 'type', 'validation_rules')
                ->with(['options:id,custom_field_id,name'])
                ->get();

            return $this->formatCustomFields($fields);
        });
    }

    protected function resolveFilterableFields(User $user): object
    {
        return (object) (new CustomFieldFilterSchema)->build($user, $this->entity()->value);
    }

    private const CHOICE_TYPES = ['select', 'radio', 'multi-select', 'checkbox-list', 'tags-input', 'toggle-buttons'];

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

            $formatHint = $this->fieldFormatHint($field->type);
            $entry['input_format'] = $formatHint['format'];
            $entry['example'] = $formatHint['example'];

            if (in_array($field->type, self::CHOICE_TYPES, true) && $field->options->isNotEmpty()) {
                $entry['options'] = $field->options->map(fn (CustomFieldOption $option): array => [
                    'id' => $option->id,
                    'label' => $option->name,
                ])->all();
            }

            $result[$field->code] = $entry;
        }

        return $result;
    }

    /**
     * @return array{format: string, example: mixed}
     */
    private function fieldFormatHint(string $type): array
    {
        return match (CustomFieldType::from($type)) {
            CustomFieldType::TEXT, CustomFieldType::TEXTAREA => ['format' => 'string', 'example' => 'Acme renewal'],
            CustomFieldType::NUMBER => ['format' => 'numeric value', 'example' => 42],
            CustomFieldType::CURRENCY => ['format' => 'numeric value (amount)', 'example' => 15000.00],
            CustomFieldType::EMAIL => ['format' => 'array of email strings', 'example' => ['user@example.com']],
            CustomFieldType::PHONE => ['format' => 'array of phone strings', 'example' => ['+1234567890']],
            CustomFieldType::LINK => ['format' => 'array of URL strings', 'example' => ['https://example.com']],
            CustomFieldType::CHECKBOX, CustomFieldType::TOGGLE => ['format' => 'boolean', 'example' => true],
            CustomFieldType::SELECT, CustomFieldType::RADIO, CustomFieldType::TOGGLE_BUTTONS => ['format' => 'option label or option ID (see options)', 'example' => 'In progress'],
            CustomFieldType::MULTI_SELECT, CustomFieldType::CHECKBOX_LIST => ['format' => 'array of option labels or IDs (see options)', 'example' => ['Enterprise', 'EU']],
            CustomFieldType::TAGS_INPUT => ['format' => 'array of arbitrary string values', 'example' => ['priority', 'customer']],
            CustomFieldType::RICH_EDITOR => ['format' => 'markdown, or HTML when the value starts with <; stored and returned as HTML', 'example' => "## Notes\n- first call done"],
            CustomFieldType::MARKDOWN_EDITOR => ['format' => 'markdown', 'example' => '**Follow up** Friday'],
            CustomFieldType::COLOR_PICKER => ['format' => 'hex color string', 'example' => '#0A80EA'],
            CustomFieldType::DATE => ['format' => 'ISO 8601 date', 'example' => '2026-09-10'],
            CustomFieldType::DATE_TIME => ['format' => 'ISO 8601 datetime string', 'example' => '2025-01-15T10:30:00Z'],
            CustomFieldType::FILE_UPLOAD => ['format' => 'path returned by the upload-file tool', 'example' => 'uploads/custom-fields/01J.../report.pdf'],
            CustomFieldType::RECORD => ['format' => 'array of record IDs of the lookup entity; records must belong to this workspace', 'example' => ['01J...']],
        };
    }
}
