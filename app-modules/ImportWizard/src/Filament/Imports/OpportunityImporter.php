<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Imports;

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Relaticle\CustomFields\Facades\CustomFields;

final class OpportunityImporter extends BaseImporter
{
    protected static ?string $model = Opportunity::class;

    protected static array $uniqueIdentifierColumns = ['id'];

    protected static string $missingUniqueIdentifiersMessage = 'For Opportunities, map a Record ID column';

    public static function getColumns(): array
    {
        return [
            self::buildIdColumn(),

            ImportColumn::make('name')
                ->label('Name')
                ->requiredMapping()
                ->guess(['name', 'opportunity_name', 'title'])
                ->rules(['required', 'string', 'max:255'])
                ->example('Q1 Sales Opportunity')
                ->fillRecordUsing(function (Opportunity $record, string $state, OpportunityImporter $importer): void {
                    $record->name = $state;
                    $importer->initializeNewRecord($record);
                }),

            ImportColumn::make('company_id')
                ->label('Company Record ID')
                ->guess(['company_id', 'company_record_id'])
                ->rules(['nullable', 'string', 'ulid'])
                ->example('01HQWX...')
                ->fillRecordUsing(function (Opportunity $record, ?string $state, OpportunityImporter $importer): void {
                    if (blank($state) || ! $importer->import->team_id) {
                        return;
                    }

                    $companyId = trim($state);

                    // Verify company exists in current team
                    $company = Company::query()
                        ->where('id', $companyId)
                        ->where('team_id', $importer->import->team_id)
                        ->first();

                    if ($company) {
                        $record->company_id = $company->getKey();
                    }
                }),

            ImportColumn::make('company_name')
                ->label('Company Name')
                ->guess(['company_name', 'company', 'account'])
                ->rules(['nullable', 'string', 'max:255'])
                ->example('Acme Corporation')
                ->fillRecordUsing(function (Opportunity $record, ?string $state, Importer $importer): void {
                    // Skip if company already set by company_id column
                    if ($record->company_id) {
                        return;
                    }

                    if (blank($state)) {
                        $record->company_id = null;

                        return;
                    }

                    if (! $importer->import->team_id) {
                        throw new \RuntimeException('Team ID is required for import');
                    }

                    try {
                        $company = Company::firstOrCreate(
                            [
                                'name' => trim($state),
                                'team_id' => $importer->import->team_id,
                            ],
                            [
                                'creator_id' => $importer->import->user_id,
                                'creation_source' => CreationSource::IMPORT,
                            ]
                        );

                        $record->company_id = $company->getKey();
                    } catch (\Exception $e) {
                        report($e);
                        throw $e; // Re-throw to fail the import for this row
                    }
                }),

            ImportColumn::make('contact_name')
                ->label('Contact Name')
                ->guess(['contact_name', 'contact', 'person'])
                ->rules(['nullable', 'string', 'max:255'])
                ->example('John Doe')
                ->fillRecordUsing(function (Opportunity $record, ?string $state, Importer $importer): void {
                    if (blank($state)) {
                        $record->contact_id = null;

                        return;
                    }

                    if (! $importer->import->team_id) {
                        throw new \RuntimeException('Team ID is required for import');
                    }

                    try {
                        $contact = People::firstOrCreate(
                            [
                                'name' => trim($state),
                                'team_id' => $importer->import->team_id,
                            ],
                            [
                                'creator_id' => $importer->import->user_id,
                                'creation_source' => CreationSource::IMPORT,
                            ]
                        );

                        $record->contact_id = $contact->getKey();
                    } catch (\Exception $e) {
                        report($e);
                        throw $e;
                    }
                }),

            ...CustomFields::importer()->forModel(self::getModel())->columns(),
        ];
    }

    public function resolveRecord(): Opportunity
    {
        // ID-based matching only
        if ($this->hasIdValue()) {
            /** @var Opportunity|null $record */
            $record = $this->resolveById();

            return $record ?? new Opportunity;
        }

        // No match found - create new opportunity
        return new Opportunity;
    }

    public static function getEntityName(): string
    {
        return 'opportunities';
    }
}
