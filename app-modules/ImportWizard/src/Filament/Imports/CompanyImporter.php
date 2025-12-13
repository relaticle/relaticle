<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Imports;

use App\Enums\CreationSource;
use App\Models\Company;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;

final class CompanyImporter extends BaseImporter
{
    protected static ?string $model = Company::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('id')
                ->label('Record ID')
                ->guess(['id', 'record_id', 'uuid', 'record id'])
                ->rules(['nullable', 'uuid'])
                ->example('9d3a5f8e-8c7b-4d9e-a1f2-3b4c5d6e7f8g')
                ->helperText('Include existing record IDs to update specific records. Leave empty to create new records.')
                ->fillRecordUsing(function (Model $record, ?string $state, Importer $importer): void {
                    // ID handled in resolveRecord(), skip here
                }),

            ImportColumn::make('name')
                ->label('Name')
                ->requiredMapping()
                ->guess(['name', 'company_name', 'company', 'organization', 'account', 'account_name'])
                ->rules(['required', 'string', 'max:255'])
                ->example('Acme Corporation')
                ->fillRecordUsing(function (Company $record, string $state, Importer $importer): void {
                    $record->name = trim($state);

                    if (! $record->exists) {
                        $record->team_id = $importer->import->team_id;
                        $record->creator_id = $importer->import->user_id;
                        $record->creation_source = CreationSource::IMPORT;
                    }
                }),

            ImportColumn::make('account_owner_email')
                ->label('Account Owner Email')
                ->guess(['account_owner', 'owner_email', 'owner', 'assigned_to', 'account_manager'])
                ->rules(['nullable', 'email'])
                ->example('owner@company.com')
                ->fillRecordUsing(function (Company $record, ?string $state, Importer $importer): void {
                    if (blank($state)) {
                        return;
                    }

                    /** @var BaseImporter $importer */
                    $user = $importer->resolveTeamMemberByEmail($state);

                    if ($user !== null) {
                        $record->account_owner_id = $user->getKey();
                    }
                }),

            ...CustomFields::importer()->forModel(self::getModel())->columns(),
        ];
    }

    public function resolveRecord(): Company
    {
        // ID-based resolution takes absolute precedence
        if ($this->hasIdValue()) {
            return $this->resolveById() ?? new Company;
        }

        // Fall back to name-based duplicate detection
        $name = $this->data['name'] ?? null;

        if (blank($name)) {
            return new Company;
        }

        $existing = Company::query()
            ->where('team_id', $this->import->team_id)
            ->where('name', trim((string) $name))
            ->first();

        $strategy = $this->getDuplicateStrategy();

        return match ($strategy) {
            DuplicateHandlingStrategy::SKIP => $existing ?? new Company,
            DuplicateHandlingStrategy::UPDATE => $existing ?? new Company,
            DuplicateHandlingStrategy::CREATE_NEW => new Company,
        };
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your company import has completed and '.Number::format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if (($failedRowsCount = $import->getFailedRowsCount()) !== 0) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }
}
