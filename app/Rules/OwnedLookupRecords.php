<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\CrmEntity;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final readonly class OwnedLookupRecords implements ValidationRule
{
    public function __construct(
        private string $teamId,
        private string $lookupType,
        private string $fieldName,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $entity = CrmEntity::tryFrom($this->lookupType);

        if ($entity === null) {
            $fail(__('validation.custom_field.unsupported_lookup', [
                'field' => $this->fieldName,
                'type' => $this->lookupType,
            ]));

            return;
        }

        $ids = collect(is_array($value) ? $value : [$value])
            ->filter(fn (mixed $id): bool => is_string($id) || is_int($id))
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $model = $entity->model();
        $owned = $model::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $ids->all())
            ->where('team_id', $this->teamId)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id);

        $missing = $ids->diff($owned);

        if ($missing->isNotEmpty()) {
            $fail(__('validation.custom_field.foreign_records', [
                'field' => $this->fieldName,
                'ids' => $missing->implode(', '),
            ]));
        }
    }
}
