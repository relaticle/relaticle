<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Models\CustomField;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * Promote newly-seen values into a field's user-managed option list.
 *
 * Gated to field types that accept arbitrary values AND own an option list
 * (tags-input). Email/phone/link also accept arbitrary values but own no
 * option list, so they are excluded — see CustomField::promotesValuesToOptions().
 */
final readonly class EnsureTagOptionsExist
{
    public function handle(CustomField $field, mixed $values): void
    {
        if (! $field->promotesValuesToOptions()) {
            return;
        }

        $candidates = collect($values instanceof Collection ? $values->all() : (array) $values)
            ->map(fn (mixed $value): string => is_string($value) ? trim($value) : '')
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values();

        if ($candidates->isEmpty()) {
            return;
        }

        $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');

        $options = $field->options()->withoutGlobalScopes()->get(['name', 'sort_order']);

        /** @var array<string, true> $existing */
        $existing = $options
            ->pluck('name')
            ->mapWithKeys(fn (?string $name): array => [mb_strtolower(trim((string) $name)) => true])
            ->all();

        $sortOrder = (int) $options->max('sort_order');

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
