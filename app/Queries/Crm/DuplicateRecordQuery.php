<?php

declare(strict_types=1);

namespace App\Queries\Crm;

use App\Enums\CrmEntity;
use App\Enums\RecordIdentity;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use App\Queries\CustomFields\EntitiesByFieldValueQuery;
use App\Support\CustomFields\CanonicalValue;
use Illuminate\Database\Eloquent\Relations\Relation;

final readonly class DuplicateRecordQuery
{
    public function __construct(private EntitiesByFieldValueQuery $entitiesByFieldValue) {}

    /**
     * @return array<string, array{record: Company|People, value: string}>
     */
    public function get(User $user, Company|People $record, int $limit): array
    {
        $field = $this->identityField($record);

        if (! $field instanceof CustomField || ! $user->belongsToWorkspaceId((string) $record->workspace_id)) {
            return [];
        }

        $values = CanonicalValue::each($field, $record->loadMissing('customFieldValues.customField')->getCustomFieldValue($field));

        if ($values === []) {
            return [];
        }

        $spellings = collect($values)->flatMap(fn (string $value): array => CanonicalValue::spellings($field, $value))->unique()->values()->all();

        return $this->entitiesByFieldValue->get($record::class, $field, $spellings, $limit + 1)
            ->reject(fn (Company|People $match): bool => $match->is($record))
            ->load(['customFieldValues' => fn (Relation $values): Relation => $values->where('custom_field_id', $field->getKey()), 'customFieldValues.customField'])
            ->map(fn (Company|People $match): array => ['record' => $match, 'value' => $this->sharedValue($field, $values, $match)])
            ->sortBy(fn (array $match): int => (int) array_search($match['value'], $values, true))
            ->take($limit)
            ->keyBy(fn (array $match): string => (string) $match['record']->getKey())
            ->all();
    }

    /**
     * @param  non-empty-array<int, string>  $values
     */
    private function sharedValue(CustomField $field, array $values, Company|People $match): string
    {
        $matchValues = array_map(mb_strtolower(...), CanonicalValue::each($field, $match->getCustomFieldValue($field)));

        return collect($values)->first(fn (string $value): bool => in_array(mb_strtolower($value), $matchValues, true)) ?? $values[0];
    }

    private function identityField(Company|People $record): ?CustomField
    {
        $identity = CrmEntity::tryFromModel($record)?->identity();

        if (! $identity instanceof RecordIdentity) {
            return null;
        }

        return CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $record->workspace_id)
            ->where('entity_type', $record->getMorphClass())
            ->where('code', $identity->fieldCode())
            ->active()
            ->first();
    }
}
