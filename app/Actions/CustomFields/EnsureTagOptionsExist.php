<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * Promote newly-seen tags-input values into the field's CustomFieldOption list.
 *
 * Gated strictly to the `tags-input` field type — email/phone are also arbitrary
 * multi-choice fields but must NOT grow an option list.
 */
final readonly class EnsureTagOptionsExist
{
    public function handle(CustomField $field, mixed $values): void
    {
        if ($field->type !== CustomFieldType::TAGS_INPUT->value) {
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

        /** @var array<string, true> $existing */
        $existing = $field->options()
            ->withoutGlobalScopes()
            ->pluck('name')
            ->mapWithKeys(fn (?string $name): array => [mb_strtolower(trim((string) $name)) => true])
            ->all();

        $sortOrder = (int) $field->options()
            ->withoutGlobalScopes()
            ->max('sort_order');

        foreach ($candidates as $value) {
            $key = mb_strtolower($value);

            if (isset($existing[$key])) {
                continue;
            }

            try {
                $field->options()->create([
                    $tenantKey => $field->{$tenantKey},
                    'name' => $value,
                    'sort_order' => ++$sortOrder,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent import/edit created this option first — the option
                // now exists, so treat it as a no-op rather than failing the row.
            }

            $existing[$key] = true;
        }
    }
}
