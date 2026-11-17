<?php

declare(strict_types=1);

namespace App\Actions\Merge;

use App\Data\MergedValues;
use App\Enums\CustomFieldType;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Support\CustomFields\CanonicalValue;
use App\Support\CustomFields\WorkspaceCustomFields;
use App\Support\EmailAddress;
use App\Support\Media\MediaLookup;

final readonly class CombineRecordValues
{
    public function __construct(
        private MediaLookup $mediaLookup,
        private WorkspaceCustomFields $workspaceFields,
    ) {}

    public function execute(Company|People $kept, Company|People $duplicate): MergedValues
    {
        $kept->loadMissing('customFieldValues.customField');
        $duplicate->loadMissing('customFieldValues.customField');

        $customFields = [];
        $overLimit = [];
        $attachmentUuids = [];

        foreach ($this->activeFields($kept) as $field) {
            $value = $this->combine(
                $field,
                $this->swapDuplicateForKept(field: $field, value: $kept->getCustomFieldValue($field), kept: $kept, duplicate: $duplicate),
                $this->swapDuplicateForKept(field: $field, value: $duplicate->getCustomFieldValue($field), kept: $kept, duplicate: $duplicate),
            );

            $customFields[$field->code] = $value;

            array_push($attachmentUuids, ...$this->mediaLookup->referencedUuids($field->type, $value));

            $limit = $this->limit($field);

            if ($limit !== null && is_array($value) && count($value) > $limit) {
                $overLimit[$field->code] = __('filament/record-page.merge.over_limit', ['field' => $field->name, 'max' => $limit]);
            }
        }

        return new MergedValues($this->attributes($kept, $duplicate), $customFields, $overLimit, array_values(array_unique($attachmentUuids)));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Company|People $kept, Company|People $duplicate): array
    {
        $attributes = ['name' => $this->pick($kept->name, $duplicate->name)];

        if ($kept instanceof Company && $duplicate instanceof Company) {
            $attributes['account_owner_id'] = $this->currentMemberId($kept, $kept->account_owner_id) ?? $this->currentMemberId($kept, $duplicate->account_owner_id);
        }

        if ($kept instanceof People && $duplicate instanceof People) {
            $attributes['company_id'] = $this->liveCompanyId($kept, $kept->company_id) ?? $this->liveCompanyId($kept, $duplicate->company_id);
        }

        return $attributes;
    }

    private function currentMemberId(Company $kept, ?string $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        return $kept->loadMissing('workspace')->workspace->hasMemberNow($userId) ? $userId : null;
    }

    private function liveCompanyId(People $kept, ?string $companyId): ?string
    {
        if ($companyId === null) {
            return null;
        }

        $isLive = Company::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $kept->workspace_id)
            ->whereKey($companyId)
            ->exists();

        return $isLive ? $companyId : null;
    }

    /**
     * @return iterable<CustomField>
     */
    private function activeFields(Company|People $record): iterable
    {
        return $this->workspaceFields
            ->forEntity($record->loadMissing('workspace')->workspace, $record->getMorphClass())
            ->where('active', true);
    }

    private function swapDuplicateForKept(CustomField $field, mixed $value, Company|People $kept, Company|People $duplicate): mixed
    {
        if (! $this->linksToSameEntity($field, $kept) || ! is_array($value)) {
            return $value;
        }

        $linkedIds = array_map(
            fn (mixed $linkedId): mixed => $linkedId === $duplicate->getKey() ? $kept->getKey() : $linkedId,
            $value,
        );

        return array_values(array_unique($linkedIds, SORT_REGULAR));
    }

    private function linksToSameEntity(CustomField $field, Company|People $record): bool
    {
        return $field->type === CustomFieldType::RECORD->value
            && $field->lookup_type === $record->getMorphClass();
    }

    private function combine(CustomField $field, mixed $keptValue, mixed $duplicateValue): mixed
    {
        if ($this->isList($field) && is_array($keptValue) && is_array($duplicateValue)) {
            return $this->unique($field, [...$keptValue, ...$duplicateValue]);
        }

        return $this->pick($keptValue, $duplicateValue);
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
            CustomFieldType::DOMAIN->value => CanonicalValue::of($field, $value),
            CustomFieldType::EMAIL->value => EmailAddress::canonicalize($value),
            CustomFieldType::TAGS_INPUT->value => mb_strtolower(trim($value)),
            default => $value,
        };
    }

    private function isList(CustomField $field): bool
    {
        return CustomFieldType::tryFrom($field->type)?->holdsList() === true || $field->settings->allow_multiple;
    }

    private function limit(CustomField $field): ?int
    {
        if ($field->type === CustomFieldType::RECORD->value || ! $field->settings->allow_multiple) {
            return null;
        }

        return $field->settings->max_values;
    }

    private function pick(mixed $keptValue, mixed $duplicateValue): mixed
    {
        return $this->isFilled($keptValue) ? $keptValue : $duplicateValue;
    }

    private function isFilled(mixed $value): bool
    {
        return ! in_array($value, [null, '', []], true);
    }
}
