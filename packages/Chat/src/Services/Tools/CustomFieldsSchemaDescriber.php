<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services\Tools;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\Workspace;
use Relaticle\Chat\Support\PromptText;
use Relaticle\CustomFields\Models\CustomFieldOption;
use Relaticle\CustomFields\Models\Scopes\CustomFieldsActivableScope;

final readonly class CustomFieldsSchemaDescriber
{
    /**
     * Build the per-tenant description for the chat tool's `custom_fields`
     * schema slot. The LLM sees this string and uses it to pick valid codes
     * and value shapes without a separate discovery round-trip.
     */
    public function describe(Workspace $workspace, string $entityType): string
    {
        $fields = CustomField::query()
            ->withoutGlobalScope(CustomFieldsActivableScope::class)
            ->where('tenant_id', $workspace->getKey())
            ->where('entity_type', $entityType)
            ->orderByDesc('active')
            ->orderBy('code')
            ->with(['options:id,custom_field_id,name'])
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
                ->map(fn (CustomFieldOption $option): string => '"'.PromptText::sanitize($option->name, 120).'"')
                ->implode(', ');
        }

        $parts[] = $type->inputFormat();

        $example = $type->example();

        if (! $type->isChoice() && $example !== null) {
            $parts[] = 'e.g. '.json_encode($example, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return "{$field->code} (".implode(', ', $parts).')';
    }
}
