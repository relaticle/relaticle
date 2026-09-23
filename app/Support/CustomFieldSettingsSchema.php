<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CustomField;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Data\FieldTypeData;
use Relaticle\CustomFields\Data\Settings\CurrencyFieldSettingsData;
use Relaticle\CustomFields\Enums\CustomFieldsFeature;
use Relaticle\CustomFields\Enums\DescriptionPosition;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Relaticle\CustomFields\FeatureSystem\FeatureManager;
use Relaticle\CustomFields\Support\CurrencyProvider;

final readonly class CustomFieldSettingsSchema
{
    private const array OPTION_COLOR_TYPES = ['select', 'multi_select', 'tags-input'];

    private const array CURRENCY_DISPLAY_TYPES = ['symbol', 'code'];

    private const array CURRENCY_DECIMAL_PLACES = [0, 2, 3, 4];

    private const int MIN_MAX_VALUES = 1;

    private const int MAX_MAX_VALUES = 20;

    private const array LABEL_KEYS = [
        'visible_in_list' => 'custom-fields::custom-fields.field.form.visible_in_list',
        'visible_in_view' => 'custom-fields::custom-fields.field.form.visible_in_view',
        'list_toggleable_hidden' => 'custom-fields::custom-fields.field.form.list_toggleable_hidden',
        'searchable' => 'custom-fields::custom-fields.field.form.searchable',
        'enable_option_colors' => 'custom-fields::custom-fields.field.form.enable_option_colors',
        'allow_multiple' => 'custom-fields::custom-fields.field.form.allow_multiple',
        'max_values' => 'custom-fields::custom-fields.field.form.max_values',
        'unique_per_entity_type' => 'custom-fields::custom-fields.field.form.unique_per_entity_type',
        'description' => 'custom-fields::custom-fields.field.form.description',
        'description_position' => 'custom-fields::custom-fields.field.form.description_position',
        'currency_code' => 'custom-fields::custom-fields.currency.currency',
        'display_type' => 'custom-fields::custom-fields.currency.display',
        'decimal_places' => 'custom-fields::custom-fields.currency.decimal_places',
    ];

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, array<int, mixed>>
     */
    public static function rules(CustomField $field, array $incoming = []): array
    {
        $type = CustomFieldsType::getFieldType($field->type);
        $resulting = array_merge(self::values($field), $incoming);

        $rules = [
            'visible_in_list' => ['boolean'],
            'visible_in_view' => ['boolean'],
        ];

        if (FeatureManager::isEnabled(CustomFieldsFeature::UI_TOGGLEABLE_COLUMNS) && (bool) $resulting['visible_in_list']) {
            $rules['list_toggleable_hidden'] = ['boolean'];
        }

        if ($type?->searchable === true && ! $field->settings->encrypted) {
            $rules['searchable'] = ['boolean'];
        }

        if (FeatureManager::isEnabled(CustomFieldsFeature::FIELD_OPTION_COLORS) && in_array($field->type, self::OPTION_COLOR_TYPES, true)) {
            $rules['enable_option_colors'] = ['boolean'];
        }

        if (FeatureManager::isEnabled(CustomFieldsFeature::FIELD_MULTI_VALUE) && $type?->supportsMultiValue === true) {
            $rules['allow_multiple'] = ['boolean'];

            if (! $type->requiresLookupType && (bool) $resulting['allow_multiple']) {
                $rules['max_values'] = ['integer', 'min:'.self::MIN_MAX_VALUES, 'max:'.self::MAX_MAX_VALUES];
            }
        }

        if (FeatureManager::isEnabled(CustomFieldsFeature::FIELD_UNIQUE_VALUE) && $type?->supportsUniqueConstraint === true && ! $field->isSystemDefined()) {
            $rules['unique_per_entity_type'] = ['boolean'];
        }

        if (FeatureManager::isEnabled(CustomFieldsFeature::FIELD_DESCRIPTION)) {
            $rules['description'] = ['nullable', 'string', 'max:'.(int) config('custom-fields.fields.description_max_length', 255)];

            if (FeatureManager::isEnabled(CustomFieldsFeature::FIELD_DESCRIPTION_POSITION) && filled($resulting['description'])) {
                $rules['description_position'] = ['nullable', Rule::enum(DescriptionPosition::class)];
            }
        }

        return [...$rules, ...self::typeRules($type)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function current(CustomField $field): array
    {
        return array_intersect_key(self::values($field), self::rules($field));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function apply(CustomField $field, array $settings): CustomFieldSettingsData
    {
        $data = clone $field->settings;
        $typeKeys = array_keys(self::typeRules(CustomFieldsType::getFieldType($field->type)));
        $touchesTypeSettings = array_intersect_key($settings, array_flip($typeKeys)) !== [];

        // Pinning every type default keeps the decimals when only the currency changes.
        $additional = $touchesTypeSettings
            ? [...$data->additional, ...self::typeValues($field)]
            : $data->additional;

        foreach ($settings as $key => $value) {
            if (in_array($key, $typeKeys, true)) {
                $additional[$key] = $value;

                continue;
            }

            if ($key === 'description_position') {
                $data->descriptionPosition = $value === null ? null : DescriptionPosition::from($value);

                continue;
            }

            $data->{$key} = $value;
        }

        $data->additional = $additional;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function withImpliedChanges(CustomField $field, array $settings): array
    {
        $enablesMultiple = ($settings['allow_multiple'] ?? null) === true;
        $maxValuesAllowed = array_key_exists('max_values', self::rules($field, $settings));

        if ($enablesMultiple && $maxValuesAllowed && ! array_key_exists('max_values', $settings) && (int) self::values($field)['max_values'] < 2) {
            $settings['max_values'] = 2;
        }

        return $settings;
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        $maxValuesRange = 'max_values must be between '.self::MIN_MAX_VALUES.' and '.self::MAX_MAX_VALUES.'.';

        return [
            'settings.currency_code.in' => 'currency_code must be an uppercase ISO 4217 code the panel offers, such as USD, EUR or JPY.',
            'settings.display_type.in' => 'display_type must be '.Arr::join(self::CURRENCY_DISPLAY_TYPES, ', ', ' or ').'.',
            'settings.decimal_places.in' => 'decimal_places must be '.Arr::join(self::CURRENCY_DECIMAL_PLACES, ', ', ' or ').'.',
            'settings.max_values.min' => $maxValuesRange,
            'settings.max_values.max' => $maxValuesRange,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, mixed>
     */
    public static function cast(array $settings, array $rules): array
    {
        foreach ($settings as $key => $value) {
            $settings[$key] = match (true) {
                in_array('boolean', $rules[$key] ?? [], true) => (bool) $value,
                in_array('integer', $rules[$key] ?? [], true) => (int) $value,
                default => $value,
            };
        }

        return $settings;
    }

    public static function label(string $key): string
    {
        $translationKey = self::LABEL_KEYS[$key] ?? null;

        return $translationKey === null ? Str::headline($key) : (string) __($translationKey);
    }

    public static function displayValue(string $key, mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? (string) __('Yes') : (string) __('No'),
            $value === null || $value === '' => (string) __('None'),
            $key === 'display_type' => (string) __("custom-fields::custom-fields.currency.display_options.{$value}"),
            default => (string) $value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function values(CustomField $field): array
    {
        $settings = $field->settings;

        return [
            'visible_in_list' => $settings->visible_in_list,
            'visible_in_view' => $settings->visible_in_view,
            'list_toggleable_hidden' => $settings->list_toggleable_hidden,
            'searchable' => $settings->searchable,
            'enable_option_colors' => $settings->enable_option_colors,
            'allow_multiple' => $settings->allow_multiple,
            'max_values' => $settings->max_values,
            'unique_per_entity_type' => $settings->unique_per_entity_type,
            'description' => $settings->description,
            'description_position' => $settings->descriptionPosition?->value,
            ...self::typeValues($field),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function typeValues(CustomField $field): array
    {
        $class = CustomFieldsType::getFieldType($field->type)?->settingsDataClass;

        if ($class === null || ! class_exists($class) || ! method_exists($class, 'fromAdditional')) {
            return [];
        }

        return $class::fromAdditional($field->settings->additional)->toArray();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private static function typeRules(?FieldTypeData $type): array
    {
        return match ($type?->settingsDataClass) {
            CurrencyFieldSettingsData::class => [
                'currency_code' => ['string', Rule::in(array_keys(CurrencyProvider::getOptions()))],
                'display_type' => ['string', Rule::in(self::CURRENCY_DISPLAY_TYPES)],
                'decimal_places' => ['integer', Rule::in(self::CURRENCY_DECIMAL_PLACES)],
            ],
            default => [],
        };
    }
}
