<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services\Tools;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\Workspace;
use App\Support\CustomFields\WorkspaceCustomFields;
use Relaticle\Chat\Support\PromptText;
use Relaticle\CustomFields\Enums\OptionCategory;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;
use Relaticle\CustomFields\Models\CustomFieldOption;
use Relaticle\CustomFields\Models\CustomFieldRelationship;

final readonly class CustomFieldsSchemaDescriber
{
    public function __construct(
        private WorkspaceCustomFields $customFields,
    ) {}

    /**
     * Build the per-tenant description for the chat tool's `custom_fields`
     * schema slot. The LLM sees this string and uses it to pick valid codes
     * and value shapes without a separate discovery round-trip.
     */
    public function describe(Workspace $workspace, string $entityType): string
    {
        $fields = $this->customFields->forEntity($workspace, $entityType)
            ->sortBy([['active', 'desc'], ['code', 'asc']]);

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
        return "{$field->code} ({$field->type})";
    }

    private function describeField(CustomField $field): string
    {
        $definition = $field->relationshipDefinition();

        if ($definition instanceof CustomFieldRelationship) {
            return "{$field->code} (".$this->describeLink($field, $definition).')';
        }

        $type = CustomFieldType::tryFrom($field->type);
        $parts = [$field->type];

        if ($type === null) {
            return "{$field->code} (".implode(', ', $parts).')';
        }

        if ($type->isChoice() && $field->options->isNotEmpty()) {
            // Option names are tenant-authored free text and land inside the tool
            // definition, which is the one prompt region NOT wrapped in the untrusted-data
            // framing the system prompt applies. Newlines and quotes would let a label
            // forge extra schema lines, so they go through the same sanitizer every
            // label in the system prompt already uses.
            $parts[] = 'one of: '.$field->options
                ->map(fn (CustomFieldOption $option): string => $this->describeOption($option))
                ->implode(', ');
        }

        $parts[] = $type->inputFormat();

        $example = $type->example();

        if (! $type->isChoice() && $example !== null) {
            $parts[] = 'e.g. '.json_encode($example, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return "{$field->code} (".implode(', ', $parts).')';
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
}
