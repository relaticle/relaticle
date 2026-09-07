<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services\Tools;

use App\Models\CustomField;
use App\Models\Team;
use Relaticle\Chat\Support\PromptText;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\CustomFields\Enums\OptionCategory;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;
use Relaticle\CustomFields\Models\CustomFieldOption;
use Relaticle\CustomFields\Models\CustomFieldRelationship;
use Relaticle\CustomFields\Models\Scopes\CustomFieldsActivableScope;

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
            ->withoutGlobalScope(CustomFieldsActivableScope::class)
            ->where('tenant_id', $team->getKey())
            ->where('entity_type', $entityType)
            ->orderByDesc('active')
            ->orderBy('code')
            ->with(['options:id,custom_field_id,name,settings'])
            ->get();

        if ($fields->isEmpty()) {
            return 'No custom fields are defined for this entity type.';
        }

        [$activeFields, $inactiveFields] = $fields->partition(fn (CustomField $field): bool => $field->active);

        $lines = [
            'Available custom fields for this entity. Keys MUST be one of these codes. Values MUST match the documented format.',
            '',
        ];

        foreach ($activeFields as $field) {
            $lines[] = '- '.$this->describeField($field);
        }

        $lines[] = '';
        $lines[] = 'A category in square brackets after an option label, such as [completed], is what that '
            .'option means, never part of its value: write the label exactly as quoted, brackets excluded.';
        $lines[] = '';
        $lines[] = 'Only include codes you want to set. Omit fields you do not want to change. '
            .'To clear a value, pass null (for a multi-value field, null or []). '
            .'If a field is required the write is rejected with a validation error naming it, '
            .'so never claim a field cannot be cleared without attempting it.';

        if ($inactiveFields->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Also defined on this entity but INACTIVE. These codes are NOT valid keys and a write using '
                .'one is rejected. They exist and hold stored values, so never tell the user the field does not exist:';

            foreach ($inactiveFields as $field) {
                $lines[] = '- '.$this->describeInactiveField($field);
            }
        }

        return implode("\n", $lines);
    }

    private function describeInactiveField(CustomField $field): string
    {
        $typeData = CustomFieldsType::getFieldType($field->type);

        return "{$field->code} (".$this->humanType($typeData?->dataType, $field->type).')';
    }

    private function describeField(CustomField $field): string
    {
        $definition = $field->relationshipDefinition();

        if ($definition instanceof CustomFieldRelationship) {
            return "{$field->code} (".$this->describeLink($field, $definition).')';
        }

        $typeData = CustomFieldsType::getFieldType($field->type);
        $dataType = $typeData?->dataType;

        $base = "{$field->code} (".$this->humanType($dataType, $field->type);

        if ($dataType?->isChoiceField() && $field->options->isNotEmpty()) {
            // Option names are tenant-authored free text and land inside the tool
            // definition, which is the one prompt region NOT wrapped in the untrusted-data
            // framing the system prompt applies. Newlines and quotes would let a label
            // forge extra schema lines, so they go through the same sanitizer every
            // label in the system prompt already uses.
            $labels = $field->options
                ->map(fn (CustomFieldOption $opt): string => $this->describeOption($opt))
                ->implode(', ');
            $base .= ", one of: {$labels}";
        }

        $hint = $this->formatHint($dataType, $field->type);
        if ($hint !== null) {
            $base .= ", {$hint}";
        }

        return $base.')';
    }

    /**
     * A field that links records. The value is always a list of record ids, so the
     * assistant is told what it points at, how many it may hold, and, where the far end
     * holds one record at a time, the flag that confirms taking it from its current
     * holder. Without that last part the write comes back as a validation error the
     * model cannot act on.
     */
    private function describeLink(CustomField $field, CustomFieldRelationship $definition): string
    {
        $target = $definition->targetEntityTypeFor($field);

        $parts = [$field->allowsMultipleRecords()
            ? "links to {$target} records, an array of record ids"
            : "links to one {$target} record, an array holding at most one record id"];

        $parts[] = 'ids only, never names: look the record up first (SearchCrmTool or a list tool with lookup: true), '
            .'or reference a record proposed earlier in this same turn as "$ref:<pending_action_id>"';

        $farField = $this->farField($definition, $field);

        if ($farField instanceof BaseCustomField) {
            $parts[] = 'the same link reads back on the '.$target.' as "'.PromptText::sanitize($farField->name, 120).'"';
        }

        $parts[] = 'relationship "'.PromptText::sanitize($definition->code, 120).'", '.$definition->cardinality->value;

        if ($this->farSideHoldsOne($definition, $field)) {
            $parts[] = "a {$target} already linked to another record is only moved when you send "
                .'{"ids": ["<id>"], "replace": true}';
        }

        return implode(', ', $parts);
    }

    /**
     * The slot the far end renders, when the relationship is paired. A one-way link has
     * none, and its target shows nothing at all.
     */
    private function farField(CustomFieldRelationship $definition, CustomField $field): ?BaseCustomField
    {
        $farId = $definition->directionFor($field) === CustomFieldRelationship::DIRECTION_FROM
            ? $definition->to_field_id
            : $definition->from_field_id;

        if ($farId === null || (string) $farId === (string) $field->getKey()) {
            return null;
        }

        return CustomField::query()->withoutGlobalScopes()->find($farId);
    }

    private function farSideHoldsOne(CustomFieldRelationship $definition, CustomField $field): bool
    {
        return $definition->directionFor($field) === CustomFieldRelationship::DIRECTION_FROM
            ? $definition->cardinality->toSideIsSingle()
            : $definition->cardinality->fromSideIsSingle();
    }

    /**
     * The category is what the option means, so the assistant can pick the finished
     * or cancelled state of a workflow without reading the tenant's wording.
     */
    private function describeOption(CustomFieldOption $option): string
    {
        $label = '"'.PromptText::sanitize($option->name, 120).'"';
        $category = $option->settings->category;

        return $category instanceof OptionCategory ? "{$label} [{$category->value}]" : $label;
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
