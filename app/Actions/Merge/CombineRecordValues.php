<?php

declare(strict_types=1);

namespace App\Actions\Merge;

use App\Data\MergedValues;
use App\Enums\CustomFieldType;
use App\Filament\CustomFields\DomainFieldType;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use App\Support\EmailAddress;

final readonly class CombineRecordValues
{
    private const array LIST_TYPES = [
        CustomFieldType::MULTI_SELECT,
        CustomFieldType::CHECKBOX_LIST,
        CustomFieldType::TAGS_INPUT,
    ];

    public function execute(Company|People $kept, Company|People $duplicate): MergedValues
    {
        $kept->loadMissing(['workspace', 'customFieldValues.customField']);
        $duplicate->loadMissing('customFieldValues.customField');

        $customFields = [];
        $overLimit = [];

        foreach ($this->activeFields($kept) as $field) {
            $value = $this->combine($field, $kept->getCustomFieldValue($field), $duplicate->getCustomFieldValue($field));

            $customFields[$field->code] = $value;

            $limit = $this->limit($field);

            if ($limit !== null && is_array($value) && count($value) > $limit) {
                $overLimit[$field->code] = ['label' => $field->name, 'limit' => $limit];
            }
        }

        return new MergedValues($this->attributes($kept, $duplicate), $customFields, $overLimit);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Company|People $kept, Company|People $duplicate): array
    {
        $attributes = ['name' => $this->pick($kept->name, $duplicate->name)];

        if ($kept instanceof Company && $duplicate instanceof Company) {
            $attributes['account_owner_id'] = $this->pick($kept->account_owner_id, $this->currentMemberId($kept, $duplicate->account_owner_id));
        }

        if ($kept instanceof People && $duplicate instanceof People) {
            $attributes['company_id'] = $this->pick($kept->company_id, $duplicate->company_id);
        }

        return $attributes;
    }

    private function currentMemberId(Company $kept, ?string $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $isMember = User::query()->memberOf($kept->workspace)->whereKey($userId)->exists();

        return $isMember ? $userId : null;
    }

    /**
     * @return iterable<CustomField>
     */
    private function activeFields(Company|People $record): iterable
    {
        return CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $record->workspace_id)
            ->where('entity_type', $record->getMorphClass())
            ->where('active', true)
            ->orderBy('sort_order')
            ->get();
    }

    private function combine(CustomField $field, mixed $kept, mixed $duplicate): mixed
    {
        if ($this->isList($field) && is_array($kept) && is_array($duplicate)) {
            return $this->unique($field, [...$kept, ...$duplicate]);
        }

        return $this->pick($kept, $duplicate);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<mixed>
     */
    private function unique(CustomField $field, array $values): array
    {
        $unique = [];

        foreach ($values as $value) {
            $unique[$this->normalize($field, $value)] ??= $value;
        }

        return array_values($unique);
    }

    private function normalize(CustomField $field, mixed $value): string|int
    {
        if (! is_string($value)) {
            return is_int($value) ? $value : (string) json_encode($value);
        }

        return match ($field->type) {
            CustomFieldType::DOMAIN->value => (new DomainFieldType)->setValue($value),
            CustomFieldType::EMAIL->value => EmailAddress::canonicalize($value),
            default => $value,
        };
    }

    private function isList(CustomField $field): bool
    {
        return in_array(CustomFieldType::tryFrom($field->type), self::LIST_TYPES, true)
            || $field->settings->allow_multiple;
    }

    private function limit(CustomField $field): ?int
    {
        if ($field->type === CustomFieldType::RECORD->value || ! $field->settings->allow_multiple) {
            return null;
        }

        return $field->settings->max_values;
    }

    private function pick(mixed $kept, mixed $duplicate): mixed
    {
        return $this->isFilled($kept) ? $kept : $duplicate;
    }

    private function isFilled(mixed $value): bool
    {
        return ! in_array($value, [null, '', []], true);
    }
}
