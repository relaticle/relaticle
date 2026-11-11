<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use App\Enums\CrmEntity;
use App\Enums\CustomFields\CompanyField;
use App\Enums\CustomFields\PeopleField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\People;
use Illuminate\Contracts\Database\Query\Builder;
use Relaticle\EmailIntegration\Jobs\RelinkRecordHistoryJob;
use Relaticle\ImportWizard\Events\CustomFieldValuesImported;

final class QueueRecordHistoryRelink
{
    /** @var array<string, array<string, string>> */
    private array $identityFieldIds = [];

    /** @var array<string, bool> */
    private array $workspaceRelinks = [];

    public function forIdentityValue(CustomFieldValue $value): void
    {
        if (! $value->wasRecentlyCreated && ! $value->wasChanged()) {
            return;
        }

        $record = $value->entity;

        if (! $record instanceof People && ! $record instanceof Company) {
            return;
        }

        if (! $this->isIdentityField((string) $record->workspace_id, (string) $value->entity_type, (string) $value->custom_field_id)) {
            return;
        }

        // A value row restamps updated_at and source on every write, so only its value column says the addresses moved.
        if ($value->wasRecentlyCreated || $value->wasChanged(CustomFieldValue::getValueColumn($value->customField->type))) {
            $this->queue($record);
        }
    }

    public function forImportedValues(CustomFieldValuesImported $event): void
    {
        $recordIds = [];

        foreach ($event->values as $value) {
            if ($this->isIdentityField($value['tenant_id'], $value['entity_type'], $value['custom_field_id'])) {
                $recordIds[$value['entity_type']][$value['entity_id']] = true;
            }
        }

        foreach ([CrmEntity::People->value => People::class, CrmEntity::Company->value => Company::class] as $entityType => $model) {
            if (! isset($recordIds[$entityType])) {
                continue;
            }

            $model::query()->whereKey(array_keys($recordIds[$entityType]))->each(fn (People|Company $record) => $this->queue($record));
        }
    }

    private function queue(People|Company $record): void
    {
        $workspaceId = (string) $record->workspace_id;
        $this->workspaceRelinks[$workspaceId] ??= RelinkRecordHistoryJob::shouldRelink($workspaceId);

        if ($this->workspaceRelinks[$workspaceId]) {
            dispatch(new RelinkRecordHistoryJob($record))->afterCommit();
        }
    }

    private function isIdentityField(string $tenantId, string $entityType, string $customFieldId): bool
    {
        $this->identityFieldIds[$tenantId] ??= CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $people): Builder => $people->where('entity_type', CrmEntity::People->value)->where('code', PeopleField::EMAILS->value))
                ->orWhere(fn (Builder $company): Builder => $company->where('entity_type', CrmEntity::Company->value)->where('code', CompanyField::DOMAINS->value)))
            ->pluck('entity_type', 'id')
            ->map(fn (mixed $type): string => (string) $type)
            ->all();

        return ($this->identityFieldIds[$tenantId][$customFieldId] ?? null) === $entityType;
    }
}
