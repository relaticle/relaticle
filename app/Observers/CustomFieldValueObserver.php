<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\CustomFields\EnsureTagOptionsExist;
use App\Models\CustomFieldValue;
use Illuminate\Support\Carbon;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Relaticle\CustomFields\FieldTypeSystem\BaseFieldType;
use Relaticle\CustomFields\Models\CustomField;
use Relaticle\CustomFields\Models\CustomFieldOption;

final readonly class CustomFieldValueObserver
{
    public function __construct(
        private EnsureTagOptionsExist $ensureTagOptionsExist,
    ) {}

    public function saved(CustomFieldValue $value): void
    {
        // Only multi-value fields (tags-input et al.) store an array in json_value;
        // scalar-typed fields leave it blank. Short-circuit before loading the
        // customField relation so ordinary custom-field saves incur no extra query.
        if (blank($value->json_value)) {
            return;
        }

        $field = $value->customField;

        // @phpstan-ignore identical.alwaysFalse (the customField relation can resolve to null for an orphaned value row)
        if ($field === null) {
            return;
        }

        $this->ensureTagOptionsExist->execute($field, $value->json_value);
    }

    public function created(CustomFieldValue $value): void
    {
        $this->log($value, old: null);
    }

    public function updated(CustomFieldValue $value): void
    {
        $column = CustomFieldValue::getValueColumn($value->customField->type);

        if (! $value->wasChanged($column)) {
            return;
        }

        $old = $value->getOriginal($column);

        // A normalization-only rewrite (e.g. a link field stripping its URL scheme on
        // save) is not a user edit — comparing the field-type-normalized old and new
        // values keeps the timeline from attributing a change the user never made.
        if ($this->normalize($value->customField, $old) === $this->normalize($value->customField, $value->getValue())) {
            return;
        }

        $this->log($value, old: $old);
    }

    private function log(CustomFieldValue $value, mixed $old): void
    {
        $entity = $value->entity;
        $new = $value->getValue();

        if ($this->isEmpty($old) && $this->isEmpty($new)) {
            return;
        }

        activity((string) config('activitylog.default_log_name'))
            ->performedOn($entity)
            ->causedBy(auth()->user())
            ->withProperties([
                'custom_field_changes' => [[
                    'code' => $value->customField->code,
                    'label' => $value->customField->name,
                    'type' => $value->customField->type,
                    'old' => $this->describe($value->customField, $old),
                    'new' => $this->describe($value->customField, $new),
                ]],
            ])
            ->event('custom_field_changes')
            ->log('custom_field_changes');
    }

    /**
     * @return array{value: mixed, label: string}
     */
    private function describe(CustomField $field, mixed $value): array
    {
        if ($this->isEmpty($value)) {
            return ['value' => null, 'label' => '—'];
        }

        $dataType = CustomFieldsType::getFieldType($field->type)->dataType;

        $label = match ($dataType) {
            FieldDataType::SINGLE_CHOICE => $this->optionLabel($field, $value) ?? (string) $value,
            FieldDataType::MULTI_CHOICE => $this->multiOptionLabels($field, $value),
            FieldDataType::BOOLEAN => $value ? 'Yes' : 'No',
            FieldDataType::DATE => $value instanceof Carbon ? $value->toDateString() : (string) $value,
            FieldDataType::DATE_TIME => $value instanceof Carbon ? $value->toDateTimeString() : (string) $value,
            default => (string) $value,
        };

        return ['value' => $value, 'label' => $label];
    }

    private function normalize(CustomField $field, mixed $value): string
    {
        $type = CustomFieldsType::getFieldTypeInstance($field->type);

        return collect(is_iterable($value) ? $value : [$value])
            ->filter(fn (mixed $item): bool => filled($item))
            ->map(fn (mixed $item): string => $type instanceof BaseFieldType
                ? $type->setValue((string) $item)
                : (string) $item)
            ->values()
            ->implode("\n");
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
            // option IDs, so no option matches — fall back to the value itself instead
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
