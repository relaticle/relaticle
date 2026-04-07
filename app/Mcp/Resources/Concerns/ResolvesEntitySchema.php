<?php

declare(strict_types=1);

namespace App\Mcp\Resources\Concerns;

use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\CustomField;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Relaticle\CustomFields\Models\CustomFieldOption;

trait ResolvesEntitySchema
{
    /**
     * @return array<string, array<string, mixed>>
     */
    protected function resolveCustomFields(User $user, string $entityType): array
    {
        $teamId = $user->currentTeam->getKey();
        $cacheKey = "custom_fields_schema_{$teamId}_{$entityType}";

        return Cache::remember($cacheKey, 60, function () use ($teamId, $entityType): array {
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

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function resolveFilterableFields(User $user, string $entityType): array
    {
        return (new CustomFieldFilterSchema)->build($user, $entityType);
    }

    private const CHOICE_TYPES = ['select', 'radio', 'multi_select', 'checkbox_list', 'tags'];

    /**
     * @param  Collection<int, CustomField>  $fields
     * @return array<string, array<string, mixed>>
     */
    private function formatCustomFields(Collection $fields): array
    {
        $result = [];

        foreach ($fields as $field) {
            $required = ($field->validation_rules ?? collect())
                ->contains('name', 'required');

            $entry = [
                'name' => $field->name,
                'type' => $field->type,
                'required' => $required,
            ];

            $formatHint = $this->fieldFormatHint($field->type);

            if ($formatHint !== null) {
                $entry['input_format'] = $formatHint['format'];
                $entry['example'] = $formatHint['example'];
            }

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
     * @return array{format: string, example: mixed}|null
     */
    private function fieldFormatHint(string $type): ?array
    {
        return match ($type) {
            'link' => ['format' => 'array of URL strings', 'example' => ['https://example.com']],
            'email' => ['format' => 'array of email strings', 'example' => ['user@example.com']],
            'phone' => ['format' => 'array of phone strings', 'example' => ['+1234567890']],
            'select', 'radio' => ['format' => 'option ID string (see options)', 'example' => 'option-id-here'],
            'multi_select', 'checkbox_list', 'tags' => ['format' => 'array of option ID strings', 'example' => ['option-id-1', 'option-id-2']],
            'toggle' => ['format' => 'boolean', 'example' => true],
            'date_time' => ['format' => 'ISO 8601 datetime string', 'example' => '2025-01-15T10:30:00Z'],
            'number' => ['format' => 'numeric value', 'example' => 42],
            'currency' => ['format' => 'numeric value (amount)', 'example' => 15000.00],
            default => null,
        };
    }
}
