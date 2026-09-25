<?php

declare(strict_types=1);

namespace App\Filament\Support\InlineField;

use App\Enums\CustomFieldType;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\People;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Relaticle\CustomFields\Services\Phone\CountryPhoneService;

final readonly class FieldState
{
    public function __construct(private Model $record) {}

    /**
     * @return array<string, mixed>
     */
    public function formState(InlineField $field): array
    {
        if (! $field->isCustom()) {
            return [$field->code => $this->record->getAttribute($field->code)];
        }

        if (! $this->record instanceof HasCustomFields) {
            return [];
        }

        $customField = $this->customField($field);

        if (! $customField instanceof CustomField) {
            return ['custom_fields' => [$field->code => null]];
        }

        $value = $this->record->getCustomFieldValue($customField);

        if ($value instanceof Collection) {
            $value = $value->all();
        }

        return ['custom_fields' => [$field->code => $this->hydrateCustomFieldValue($customField, $value)]];
    }

    public function displayValue(InlineField $field, string $empty): string
    {
        if (! $field->isCustom()) {
            return $this->nativeDisplayValue($field, $empty);
        }

        if (! $this->record instanceof HasCustomFields) {
            return $empty;
        }

        $customField = CustomField::query()
            ->forEntity($this->record::class)
            ->where('code', $field->code)
            ->with('options')
            ->first();

        if (! $customField instanceof CustomField) {
            return $empty;
        }

        $value = $this->record->getCustomFieldValue($customField);

        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (blank($value)) {
            return $empty;
        }

        if ($field->type === CustomFieldType::RICH_EDITOR && is_string($value)) {
            $text = trim(html_entity_decode(strip_tags($value), ENT_QUOTES, 'UTF-8'));

            return $text !== '' ? $text : $empty;
        }

        $option = $customField->options->firstWhere('id', $value)
            ?? $customField->options->firstWhere('name', $value);

        if (is_object($option) && filled($option->name ?? null)) {
            return (string) $option->name;
        }

        if (is_array($value)) {
            $labels = array_map(static function (mixed $item) use ($customField): string {
                $option = $customField->options->firstWhere('id', $item)
                    ?? $customField->options->firstWhere('name', $item);

                if (is_object($option) && filled($option->name ?? null)) {
                    return (string) $option->name;
                }

                return is_scalar($item) ? (string) $item : '';
            }, $value);

            $labels = array_values(array_filter($labels, static fn (string $label): bool => $label !== ''));

            return $labels === [] ? $empty : implode(', ', $labels);
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return $empty;
    }

    /**
     * @return array<string, mixed>
     */
    public function booleanPayload(InlineField $field, bool $value): array
    {
        if ($field->isCustom()) {
            return ['custom_fields' => [$field->code => $value]];
        }

        return [$field->code => $value];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function payloadFromState(InlineField $field, array $state): array
    {
        if (! $field->isCustom()) {
            return Arr::only($state, [$field->code]);
        }

        return ['custom_fields' => [$field->code => data_get($state, $field->valuePath())]];
    }

    /**
     * @return list<string>|null
     */
    public function normalizedLinkOrEmailList(InlineField $field, mixed $value): ?array
    {
        if (! $field->isCustom()) {
            return null;
        }

        $customField = $this->customField($field);

        if (! $customField instanceof CustomField) {
            return null;
        }

        $type = CustomFieldType::tryFrom($customField->type);

        if (! in_array($type, [CustomFieldType::LINK, CustomFieldType::EMAIL], true)) {
            return null;
        }

        return $this->normalizeStringList($value);
    }

    private function customField(InlineField $field): ?CustomField
    {
        return CustomField::query()
            ->forEntity($this->record::class)
            ->where('code', $field->code)
            ->first();
    }

    private function nativeDisplayValue(InlineField $field, string $empty): string
    {
        $record = $this->record;

        if ($field->code === 'account_owner_id' && $record instanceof Company) {
            $record->loadMissing('accountOwner');

            return filled($record->accountOwner?->name)
                ? (string) $record->accountOwner->name
                : $empty;
        }

        if ($field->code === 'company_id' && ($record instanceof People || $record instanceof Opportunity)) {
            $record->loadMissing('company');

            return filled($record->company?->name)
                ? (string) $record->company->name
                : $empty;
        }

        if ($field->code === 'contact_id' && $record instanceof Opportunity) {
            $record->loadMissing('contact');

            return filled($record->contact?->name)
                ? (string) $record->contact->name
                : $empty;
        }

        $value = $record->getAttribute($field->code);

        return filled($value) && is_scalar($value) ? (string) $value : $empty;
    }

    private function hydrateCustomFieldValue(CustomField $customField, mixed $value): mixed
    {
        $type = CustomFieldType::tryFrom($customField->type);

        if (in_array($type, [CustomFieldType::LINK, CustomFieldType::EMAIL], true)) {
            return $this->normalizeStringList($value);
        }

        if ($customField->type !== CustomFieldType::PHONE->value) {
            return $value;
        }

        $service = resolve(CountryPhoneService::class);
        $defaultCountry = $service->detectCountryFromLocale();

        if (! is_array($value) || $value === []) {
            return [['country' => $defaultCountry, 'number' => '']];
        }

        return array_values(array_map(
            fn (mixed $entry): array => is_string($entry)
                ? $service->parseE164($entry, $defaultCountry)
                : (is_array($entry) ? $entry : ['country' => $defaultCountry, 'number' => '']),
            $value,
        ));
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $item = trim($item);

            if ($item === '') {
                continue;
            }

            $items[] = $item;
        }

        return $items;
    }
}
