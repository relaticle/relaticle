<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services\Tools;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Relaticle\CustomFields\Models\CustomFieldOption;
use Relaticle\CustomFields\Models\CustomFieldValue;

final readonly class CustomFieldsDisplayFormatter
{
    /**
     * Build proposal-card rows for the chat UI from a validated custom_fields
     * payload. Values are already in their canonical form (option IDs for
     * choice fields, ISO strings for dates).
     *
     * @param  array<string, mixed>  $cleanFields
     * @return list<array{label: string, old?: string|null, new: string|null, type: string, values?: list<string>}>
     */
    public function format(User $user, string $entityType, array $cleanFields, ?Model $oldModel): array
    {
        if ($cleanFields === []) {
            return [];
        }

        $teamId = $user->currentTeam->getKey();
        $fields = CustomField::query()
            ->where('tenant_id', $teamId)
            ->where('entity_type', $entityType)
            ->active()
            ->whereIn('code', array_keys($cleanFields))
            ->with('options')
            ->get()
            ->keyBy('code');

        $rows = [];
        foreach ($cleanFields as $code => $newValue) {
            $field = $fields->get($code);
            if (! $field instanceof CustomField) {
                continue;
            }

            $dataType = CustomFieldsType::getFieldType($field->type)?->dataType;

            $row = [
                'label' => $field->name,
                'new' => $this->renderValue($field, $newValue),
                'type' => $this->displayType($field, $dataType),
            ];

            if ($dataType === FieldDataType::MULTI_CHOICE && $field->type !== CustomFieldType::LINK->value && is_array($newValue)) {
                $row['values'] = $this->optionNames($field, $newValue);
            }

            if ($field->type === CustomFieldType::LINK->value && is_array($newValue)) {
                $row['values'] = $this->optionNames($field, $newValue);
            }

            if ($dataType === FieldDataType::SINGLE_CHOICE) {
                $name = $this->renderSingleChoice($field, $newValue);
                $row['values'] = $name === null || $name === '' ? [] : [$name];
            }

            if ($oldModel instanceof Model) {
                $oldValue = $this->lookupCurrentValue($field, $oldModel);
                $row['old'] = $oldValue !== null ? $this->renderValue($field, $oldValue) : null;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function renderValue(CustomField $field, mixed $value): ?string
    {
        if (in_array($value, [null, '', []], true)) {
            return null;
        }

        $dataType = CustomFieldsType::getFieldType($field->type)?->dataType;

        return match ($dataType) {
            FieldDataType::SINGLE_CHOICE => $this->renderSingleChoice($field, $value),
            FieldDataType::MULTI_CHOICE => $this->renderMultiChoice($field, $value),
            FieldDataType::DATE, FieldDataType::DATE_TIME => $this->renderDate($value),
            FieldDataType::TEXT => trim(strip_tags((string) $value)),
            FieldDataType::BOOLEAN => $value ? 'Yes' : 'No',
            default => is_array($value) ? implode(', ', array_map(strval(...), $value)) : (string) $value,
        };
    }

    private function renderSingleChoice(CustomField $field, mixed $value): ?string
    {
        $option = $field->options->firstWhere('id', (string) $value);

        return $option instanceof CustomFieldOption ? $option->name : (string) $value;
    }

    private function renderMultiChoice(CustomField $field, mixed $value): string
    {
        if (! is_array($value)) {
            return (string) $value;
        }

        return implode(', ', $this->optionNames($field, $value));
    }

    private function displayType(CustomField $field, ?FieldDataType $dataType): string
    {
        if ($field->type === CustomFieldType::LINK->value) {
            return 'link';
        }

        return match ($dataType) {
            FieldDataType::SINGLE_CHOICE, FieldDataType::MULTI_CHOICE => 'badges',
            FieldDataType::BOOLEAN => 'boolean',
            default => 'text',
        };
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return list<string>
     */
    private function optionNames(CustomField $field, array $ids): array
    {
        $byId = $field->options->keyBy('id');

        return array_values(array_map(function (mixed $id) use ($byId): string {
            $option = $byId->get((string) $id);

            return $option instanceof CustomFieldOption ? $option->name : (string) $id;
        }, $ids));
    }

    private function renderDate(mixed $value): string
    {
        $carbon = $value instanceof DateTimeInterface ? Date::instance($value) : Date::parse((string) $value);

        return $carbon->isoFormat('MMM D, YYYY');
    }

    /**
     * Read the current value of a custom field on a model via the
     * directly-loaded customFieldValues relation.
     */
    private function lookupCurrentValue(CustomField $field, Model $model): mixed
    {
        if (! method_exists($model, 'customFieldValues')) {
            return null;
        }

        $row = $model->customFieldValues()
            ->where('custom_field_id', $field->getKey())
            ->first();

        if (! $row instanceof CustomFieldValue) {
            return null;
        }

        $column = CustomFieldValue::getValueColumn($field->type);

        return $row->{$column};
    }
}
