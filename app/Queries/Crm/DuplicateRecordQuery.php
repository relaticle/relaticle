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

final readonly class DuplicateRecordQuery
{
    private const int LIMIT = 10;

    public function __construct(private EntitiesByFieldValueQuery $entitiesByFieldValue) {}

    /**
     * @return array<string, array{record: Company|People, value: string}>
     */
    public function get(User $user, Company|People $record): array
    {
        $field = $this->identityField($record);

        if (! $field instanceof CustomField || ! $user->belongsToWorkspaceId((string) $record->workspace_id)) {
            return [];
        }

        return collect(CanonicalValue::each($field, $record->loadMissing('customFieldValues.customField')->getCustomFieldValue($field)))
            ->flatMap(fn (string $value): array => $this->entitiesByFieldValue
                ->get($record::class, $field, CanonicalValue::spellings($field, $value), self::LIMIT + 1)
                ->reject(fn (Company|People $match): bool => $match->is($record))
                ->map(fn (Company|People $match): array => ['record' => $match, 'value' => $value])
                ->all())
            ->unique(fn (array $match): string => (string) $match['record']->getKey())
            ->take(self::LIMIT)
            ->keyBy(fn (array $match): string => (string) $match['record']->getKey())
            ->all();
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
