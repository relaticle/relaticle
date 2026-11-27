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
use App\Support\CustomFieldSettingsSchema;
use App\Support\EmailAddress;
use App\Support\Media\MediaLookup;

final readonly class CombineRecordValues
{
    public function __construct(
        private MediaLookup $mediaLookup,
        private WorkspaceCustomFields $workspaceFields,
    ) {}

    public function execute(Company|People $priority, Company|People $other, Company|People|null $merged = null): MergedValues
    {
        $priority->loadMissing('customFieldValues.customField');
        $other->loadMissing('customFieldValues.customField');

        $customFields = [];
        $inactiveCustomFields = [];
        $overLimit = [];
        $raises = [];
        $attachmentUuids = [];

        foreach ($this->fields($priority) as $field) {
            if (! $field->isActive()) {
                $this->bindInactiveField($field, $priority, $other);
            }

            $value = $this->combine(
                $field,
                $this->swapOriginalsForMerged($field, $priority->getCustomFieldValue($field), $priority, $other, $merged),
                $this->swapOriginalsForMerged($field, $other->getCustomFieldValue($field), $priority, $other, $merged),
            );

            if ($field->isActive()) {
                $customFields[$field->code] = $value;
            } else {
                $inactiveCustomFields[] = ['field' => $field, 'value' => $value];
            }

            array_push($attachmentUuids, ...$this->mediaLookup->referencedUuids($field->type, $value));

            $limit = $this->limit($field);

            if ($limit === null || ! is_array($value) || count($value) <= $limit) {
                continue;
            }

            if (count($value) > CustomFieldSettingsSchema::MAX_MAX_VALUES) {
                $overLimit[$field->code] = __('filament/record-page.merge.over_limit', ['field' => $field->name, 'max' => CustomFieldSettingsSchema::MAX_MAX_VALUES]);

                continue;
            }

            $raises[$field->code] = count($value);
        }

        return new MergedValues($this->attributes($priority, $other), $customFields, $inactiveCustomFields, $overLimit, $raises, array_values(array_unique($attachmentUuids)));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Company|People $priority, Company|People $other): array
    {
        $attributes = ['name' => $this->pick($priority->name, $other->name)];

        if ($priority instanceof Company && $other instanceof Company) {
            $attributes['account_owner_id'] = $this->currentMemberId($priority, $priority->account_owner_id) ?? $this->currentMemberId($priority, $other->account_owner_id);
        }

        if ($priority instanceof People && $other instanceof People) {
            $attributes['company_id'] = $this->liveCompanyId($priority, $priority->company_id) ?? $this->liveCompanyId($priority, $other->company_id);
        }

        return $attributes;
    }

    private function currentMemberId(Company $record, ?string $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        return $record->loadMissing('workspace')->workspace->hasMemberNow($userId) ? $userId : null;
    }

    private function liveCompanyId(People $record, ?string $companyId): ?string
    {
        if ($companyId === null) {
            return null;
        }

        $isLive = Company::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $record->workspace_id)
            ->whereKey($companyId)
            ->exists();

        return $isLive ? $companyId : null;
    }

    /**
     * @return iterable<CustomField>
     */
    private function fields(Company|People $record): iterable
    {
        return $this->workspaceFields->forEntity($record->loadMissing('workspace')->workspace, $record->getMorphClass());
    }

    private function bindInactiveField(CustomField $field, Company|People ...$records): void
    {
        // The package scopes a value's field relation to active fields, so its value reads as null until the field is bound.
        foreach ($records as $record) {
            $record->customFieldValues->where('custom_field_id', $field->getKey())->each->setRelation('customField', $field);
        }
    }

    private function swapOriginalsForMerged(CustomField $field, mixed $value, Company|People $priority, Company|People $other, Company|People|null $merged): mixed
    {
        if ($merged === null || ! $this->linksToSameEntity($field, $merged) || ! is_array($value)) {
            return $value;
        }

        $originalIds = [$priority->getKey(), $other->getKey()];

        $linkedIds = array_map(
            fn (mixed $linkedId): mixed => in_array($linkedId, $originalIds, true) ? $merged->getKey() : $linkedId,
            $value,
        );

        return array_values(array_unique($linkedIds, SORT_REGULAR));
    }

    private function linksToSameEntity(CustomField $field, Company|People $record): bool
    {
        return $field->type === CustomFieldType::RECORD->value
            && $field->lookup_type === $record->getMorphClass();
    }

    private function combine(CustomField $field, mixed $priorityValue, mixed $otherValue): mixed
    {
        if ($this->isList($field) && is_array($priorityValue) && is_array($otherValue)) {
            return $this->unique($field, [...$priorityValue, ...$otherValue]);
        }

        return $this->pick($priorityValue, $otherValue);
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

    private function pick(mixed $priorityValue, mixed $otherValue): mixed
    {
        return $this->isFilled($priorityValue) ? $priorityValue : $otherValue;
    }

    private function isFilled(mixed $value): bool
    {
        return ! in_array($value, [null, '', []], true);
    }
}
