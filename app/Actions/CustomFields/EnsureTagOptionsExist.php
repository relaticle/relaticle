<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use BackedEnum;
use Illuminate\Support\Collection;

/**
 * Promote newly-seen tags-input values into the field's CustomFieldOption list.
 *
 * Gated strictly to the `tags-input` field type — email/phone are also arbitrary
 * multi-choice fields but must NOT grow an option list.
 */
final class EnsureTagOptionsExist
{
    public function handle(CustomField $field, mixed $values): void
    {
        if ($this->fieldType($field) !== CustomFieldType::TAGS_INPUT->value) {
            return;
        }

        $candidates = collect($values instanceof Collection ? $values->all() : (array) $values)
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->unique()
            ->values();

        if ($candidates->isEmpty()) {
            return;
        }

        $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');

        $existing = $field->options()
            ->withoutGlobalScopes()
            ->pluck('name')
            ->map(fn (?string $name): string => mb_strtolower(trim((string) $name)))
            ->flip();

        $sortOrder = (int) $field->options()
            ->withoutGlobalScopes()
            ->max('sort_order');

        foreach ($candidates as $value) {
            $key = mb_strtolower($value);

            if ($existing->has($key)) {
                continue;
            }

            $field->options()->create([
                $tenantKey => $field->{$tenantKey},
                'name' => $value,
                'sort_order' => ++$sortOrder,
            ]);

            $existing->put($key, true);
        }
    }

    private function fieldType(CustomField $field): string
    {
        return $field->type instanceof BackedEnum ? $field->type->value : (string) $field->type;
    }
}
