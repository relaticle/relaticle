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
use Illuminate\Database\Eloquent\Model;

final readonly class DuplicateRecordQuery
{
    public function __construct(private EntitiesByFieldValueQuery $entitiesByFieldValue) {}

    /**
     * @return array{record: Company|People, value: string}|null
     */
    public function first(User $user, Company|People $record): ?array
    {
        $field = $this->identityField($record);

        if (! $field instanceof CustomField || ! $user->belongsToWorkspaceId((string) $record->workspace_id)) {
            return null;
        }

        foreach (CanonicalValue::each($field, $record->getCustomFieldValue($field)) as $value) {
            $duplicate = $this->entitiesByFieldValue
                ->get($record::class, $field, CanonicalValue::spellings($field, $value), 2)
                ->first(fn (Model $match): bool => ! $match->is($record));

            if ($duplicate instanceof Company || $duplicate instanceof People) {
                return ['record' => $duplicate, 'value' => $value];
            }
        }

        return null;
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
