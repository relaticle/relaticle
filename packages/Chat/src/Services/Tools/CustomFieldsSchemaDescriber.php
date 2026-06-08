<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services\Tools;

use App\Models\CustomField;
use App\Models\Team;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Relaticle\CustomFields\Models\CustomFieldOption;

final readonly class CustomFieldsSchemaDescriber
{
    /**
     * Build the per-tenant description for the chat tool's `custom_fields`
     * schema slot. The LLM sees this string and uses it to pick valid codes
     * and value shapes without a separate discovery round-trip.
     */
    public function describe(Team $team, string $entityType): string
    {
        $fields = CustomField::query()
            ->where('tenant_id', $team->getKey())
            ->where('entity_type', $entityType)
            ->active()
            ->orderBy('code')
            ->with(['options:id,custom_field_id,name'])
            ->get();

        if ($fields->isEmpty()) {
            return 'No custom fields are defined for this entity type.';
        }

        $lines = [
            'Available custom fields for this entity. Keys MUST be one of these codes. Values MUST match the documented format.',
            '',
        ];

        foreach ($fields as $field) {
            $lines[] = '- '.$this->describeField($field);
        }

        $lines[] = '';
        $lines[] = 'Only include codes you want to set. Omit fields you do not want to change.';

        return implode("\n", $lines);
    }

    private function describeField(CustomField $field): string
    {
        $typeData = CustomFieldsType::getFieldType($field->type);
        $dataType = $typeData?->dataType;

        $base = "{$field->code} (".$this->humanType($dataType, $field->type);

        if ($dataType?->isChoiceField() && $field->options->isNotEmpty()) {
            $labels = $field->options
                ->map(fn (CustomFieldOption $opt): string => '"'.$opt->name.'"')
                ->implode(', ');
            $base .= ", one of: {$labels}";
        }

        $hint = $this->formatHint($dataType, $field->type);
        if ($hint !== null) {
            $base .= ", {$hint}";
        }

        return $base.')';
    }

    private function humanType(?FieldDataType $dataType, string $rawType): string
    {
        return match ($dataType) {
            FieldDataType::STRING => 'string',
            FieldDataType::TEXT => 'rich-text',
            FieldDataType::NUMERIC => 'integer',
            FieldDataType::FLOAT => 'number',
            FieldDataType::DATE => 'date',
            FieldDataType::DATE_TIME => 'date-time',
            FieldDataType::BOOLEAN => 'boolean',
            FieldDataType::SINGLE_CHOICE => 'single-choice',
            FieldDataType::MULTI_CHOICE => 'multi-choice',
            FieldDataType::FILE => 'file (read-only via chat)',
            null => $rawType,
        };
    }

    private function formatHint(?FieldDataType $dataType, string $rawType): ?string
    {
        return match ($dataType) {
            FieldDataType::DATE => 'YYYY-MM-DD',
            FieldDataType::DATE_TIME => 'ISO 8601, e.g. "2026-05-20T14:00:00Z"',
            FieldDataType::TEXT => 'plain text is fine, will be wrapped as HTML on save',
            FieldDataType::MULTI_CHOICE => 'array of label strings',
            default => match ($rawType) {
                'email' => 'array of email strings',
                'phone' => 'array of phone strings',
                'link' => 'array of URL strings',
                'currency' => 'numeric amount',
                default => null,
            },
        };
    }
}
