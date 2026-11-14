<?php

declare(strict_types=1);

namespace App\Support\ActivityLog;

use App\Support\CustomFields\CanonicalValue;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Relaticle\CustomFields\Models\CustomField;
use Relaticle\CustomFields\Models\CustomFieldOption;

final readonly class CustomFieldChangeLog
{
    public function record(Model $entity, CustomField $field, mixed $old, mixed $new): void
    {
        $change = $this->change($entity, $field, $old, $new);

        if ($change !== null) {
            LabelledChangeLog::write(...$change);
        }
    }

    /**
     * @param  iterable<array{0: Model, 1: mixed, 2: mixed}>  $values  entity, old value, new value
     */
    public function recordMany(CustomField $field, iterable $values): void
    {
        $changes = [];

        foreach ($values as [$entity, $old, $new]) {
            $change = $this->change($entity, $field, $old, $new);

            if ($change !== null) {
                $changes[] = $change;
            }
        }

        LabelledChangeLog::writeMany($changes);
    }

    /**
     * @return ?array{record: Model, code: string, label: string, type: string, old: array{value: mixed, label: string}, new: array{value: mixed, label: string}}
     */
    private function change(Model $entity, CustomField $field, mixed $old, mixed $new): ?array
    {
        if ($this->isEmpty($old) && $this->isEmpty($new)) {
            return null;
        }

        // A normalization-only rewrite (a link field stripping its scheme) is not a user edit.
        // A first value skips the check: nothing was rewritten, and `false` normalizes to empty.
        if ($old !== null && CanonicalValue::each($field, $old) === CanonicalValue::each($field, $new)) {
            return null;
        }

        $dataType = CustomFieldsType::getFieldType($field->type)->dataType;

        return [
            'record' => $entity,
            'code' => $field->code,
            'label' => $field->name,
            'type' => $field->type,
            'old' => $this->describe($field, $dataType, $old),
            'new' => $this->describe($field, $dataType, $new),
        ];
    }

    /**
     * @return array{value: mixed, label: string}
     */
    private function describe(CustomField $field, FieldDataType $dataType, mixed $value): array
    {
        if ($this->isEmpty($value)) {
            return ['value' => null, 'label' => ActivityValue::EMPTY];
        }

        if ($field->settings->encrypted) {
            return ['value' => ActivityValue::REDACTED, 'label' => ActivityValue::REDACTED];
        }

        $label = match ($dataType) {
            FieldDataType::SINGLE_CHOICE => $this->optionLabel($field, $value) ?? (string) $value,
            FieldDataType::MULTI_CHOICE => $this->multiOptionLabels($field, $value),
            FieldDataType::BOOLEAN => $value ? 'Yes' : 'No',
            FieldDataType::DATE => $value instanceof CarbonInterface ? $value->toDateString() : (string) $value,
            FieldDataType::DATE_TIME => $value instanceof CarbonInterface ? $value->toDateTimeString() : (string) $value,
            default => (string) $value,
        };

        return ['value' => $value, 'label' => $label];
    }

    private function optionLabel(CustomField $field, mixed $value): ?string
    {
        return $field->options->first(fn (CustomFieldOption $option): bool => (string) $option->getKey() === (string) $value)?->name;
    }

    private function multiOptionLabels(CustomField $field, mixed $value): string
    {
        $ids = is_iterable($value) ? collect($value) : collect();

        $labels = $ids
            // Arbitrary-value fields (link, tags-input) store raw strings rather than
            // option IDs, so no option matches. Fall back to the value itself instead
            // of leaking escaped JSON.
            ->map(fn (mixed $id): string => $this->optionLabel($field, $id) ?? (string) $id)
            ->filter(fn (string $label): bool => $label !== '')
            ->all();

        return implode(', ', $labels);
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (is_iterable($value)) {
            return collect($value)->isEmpty();
        }

        return false;
    }
}
