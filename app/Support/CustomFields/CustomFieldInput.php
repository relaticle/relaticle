<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use Illuminate\Validation\ValidationException;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Spatie\LaravelMarkdown\MarkdownRenderer;

final readonly class CustomFieldInput
{
    public function __construct(private CustomFieldOptionMap $optionMap, private MarkdownRenderer $markdown) {}

    /**
     * @param  array<array-key, mixed>  $customFields
     * @return array<array-key, mixed>
     */
    public function normalize(string $teamId, string $entityType, array $customFields): array
    {
        if ($customFields === []) {
            return [];
        }

        $fields = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $teamId)
            ->where('entity_type', $entityType)
            ->active()
            ->whereIn('code', array_keys($customFields))
            ->with('options')
            ->get()
            ->keyBy('code');

        $optionMap = $this->optionMap->fromFields($fields);
        $normalized = [];

        foreach ($customFields as $code => $value) {
            $field = $fields->get($code);

            if (! $field instanceof CustomField || $value === null) {
                $normalized[$code] = $value;

                continue;
            }

            $normalized[$code] = $this->normalizeValue($field, $value, $optionMap[(string) $code] ?? ['ids' => [], 'labels' => []]);
        }

        return $normalized;
    }

    /**
     * @param  array{ids: array<string, list<string>>, labels: list<string>}  $entry
     */
    private function normalizeValue(CustomField $field, mixed $value, array $entry): mixed
    {
        return match (CustomFieldType::from($field->type)) {
            CustomFieldType::SELECT,
            CustomFieldType::RADIO,
            CustomFieldType::TOGGLE_BUTTONS => $this->singleOption($field, $value, $entry),
            CustomFieldType::MULTI_SELECT,
            CustomFieldType::CHECKBOX_LIST => $this->optionList($field, $value, $entry),
            CustomFieldType::RICH_EDITOR => $this->richText($value),
            CustomFieldType::TEXT,
            CustomFieldType::NUMBER,
            CustomFieldType::EMAIL,
            CustomFieldType::PHONE,
            CustomFieldType::LINK,
            CustomFieldType::TEXTAREA,
            CustomFieldType::CHECKBOX,
            CustomFieldType::TAGS_INPUT,
            CustomFieldType::COLOR_PICKER,
            CustomFieldType::TOGGLE,
            CustomFieldType::CURRENCY,
            CustomFieldType::DATE,
            CustomFieldType::DATE_TIME,
            CustomFieldType::FILE_UPLOAD,
            CustomFieldType::RECORD => $value,
        };
    }

    /**
     * @param  array{ids: array<string, list<string>>, labels: list<string>}  $entry
     */
    private function singleOption(CustomField $field, mixed $value, array $entry): mixed
    {
        if ($this->skipsOptionTranslation($field)) {
            return $value;
        }

        if ($this->isBlankString($value)) {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            $this->fail($field, __('validation.custom_field.single_option', ['field' => $field->name]));
        }

        return $this->resolveOption($field, (string) $value, $entry);
    }

    /**
     * @param  array{ids: array<string, list<string>>, labels: list<string>}  $entry
     */
    private function optionList(CustomField $field, mixed $value, array $entry): mixed
    {
        if ($this->skipsOptionTranslation($field)) {
            return $value;
        }

        if ($this->isBlankString($value)) {
            return null;
        }

        if (! is_array($value)) {
            $this->fail($field, __('validation.custom_field.option_list', ['field' => $field->name]));
        }

        return array_values(array_map(
            function (mixed $item) use ($field, $entry): string {
                if (! is_string($item) && ! is_int($item)) {
                    $this->fail($field, __('validation.custom_field.option_list', ['field' => $field->name]));
                }

                return $this->resolveOption($field, (string) $item, $entry);
            },
            $value,
        ));
    }

    /**
     * @param  array{ids: array<string, list<string>>, labels: list<string>}  $entry
     */
    private function resolveOption(CustomField $field, string $value, array $entry): string
    {
        $id = $this->optionMap->idFor($entry, $value);

        if ($id !== null) {
            return $id;
        }

        if ($this->optionMap->isAmbiguous($entry, $value)) {
            $this->fail($field, __('validation.custom_field.ambiguous_option', ['field' => $field->name, 'value' => $value]));
        }

        $this->fail($field, __('validation.custom_field.unknown_option', [
            'field' => $field->name,
            'value' => $value,
            'labels' => implode(', ', $entry['labels']),
        ]));
    }

    private function richText(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        if (str_starts_with(ltrim($value), '<')) {
            return $value;
        }

        return $this->markdown->toHtml($value);
    }

    private function isBlankString(mixed $value): bool
    {
        return is_string($value) && blank($value);
    }

    private function skipsOptionTranslation(CustomField $field): bool
    {
        $typeData = CustomFieldsType::getFieldType($field->type);

        return $typeData === null || $typeData->acceptsArbitraryValues || $field->lookup_type !== null;
    }

    private function fail(CustomField $field, string $message): never
    {
        throw ValidationException::withMessages(["custom_fields.{$field->code}" => $message]);
    }
}
