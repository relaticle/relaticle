<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Enums\CreationSource;
use App\Enums\DuplicateHandlingStrategy;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;
use Relaticle\CustomFields\Facades\CustomFields;

final class OpportunityImporter extends BaseImporter
{
    protected static ?string $model = Opportunity::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('name')
                ->requiredMapping()
                ->guess(['name', 'opportunity_name', 'title'])
                ->rules(['required', 'string', 'max:255'])
                ->example('Q1 Sales Opportunity')
                ->fillRecordUsing(function (Opportunity $record, string $state, Importer $importer): void {
                    $record->name = $state;

                    // Set team and creator for new records
                    if (! $record->exists) {
                        $record->team_id = $importer->import->team_id;
                        $record->creator_id = $importer->import->user_id;
                        $record->creation_source = CreationSource::IMPORT;
                    }
                }),

            ImportColumn::make('company_name')
                ->label('Company Name')
                ->guess(['company_name', 'company', 'account'])
                ->rules(['nullable', 'string', 'max:255'])
                ->example('Acme Corporation')
                ->fillRecordUsing(function (Opportunity $record, ?string $state, Importer $importer): void {
                    if (in_array($state, [null, '', '0'], true)) {
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
                    if (in_array($state, [null, '', '0'], true)) {
                        $record->contact_id = null;

                        return;
                    }

                    if (! $importer->import->team_id) {
                        throw new \RuntimeException('Team ID is required for import');
                    }

                    try {
                        // First try to find existing contact
                        $contact = People::query()
                            ->where('team_id', $importer->import->team_id)
                            ->where('name', trim($state))
                            ->first();

                        if (! $contact) {
                            // Create new contact if not found
                            $contact = People::create([
                                'name' => trim($state),
                                'team_id' => $importer->import->team_id,
                                'creator_id' => $importer->import->user_id,
                                'creation_source' => CreationSource::IMPORT,
                            ]);
                        }

                        $record->contact_id = $contact->getKey();
                    } catch (\Exception $e) {
                        report($e);
                        throw $e; // Re-throw to fail the import for this row
                    }
                }),

            ...CustomFields::importer()->forModel(self::getModel())->columns(),
        ];
    }

    public function resolveRecord(): Opportunity
    {
        $name = $this->data['name'] ?? null;

        if (blank($name)) {
            return new Opportunity;
        }

        $existing = Opportunity::query()
            ->where('team_id', $this->import->team_id)
            ->where('name', trim($name))
            ->first();

        $strategy = $this->getDuplicateStrategy();

        return match ($strategy) {
            DuplicateHandlingStrategy::SKIP => $existing ?? new Opportunity,
            DuplicateHandlingStrategy::UPDATE => $existing ?? new Opportunity,
            DuplicateHandlingStrategy::CREATE_NEW => new Opportunity,
        };
    }

    protected function afterSave(): void
    {
        CustomFields::importer()->forModel($this->record)->saveValues();
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your opportunities import has completed and '.Number::format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if (($failedRowsCount = $import->getFailedRowsCount()) !== 0) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }
}
