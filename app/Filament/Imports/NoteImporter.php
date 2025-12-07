<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Enums\CreationSource;
use App\Enums\DuplicateHandlingStrategy;
use App\Models\Note;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;
use Relaticle\CustomFields\Facades\CustomFields;

final class NoteImporter extends BaseImporter
{
    protected static ?string $model = Note::class;

    /**
     * Store original data for afterSave() to handle relationships.
     *
     * @var array<string, mixed>
     */
    private array $originalImportData = [];

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('title')
                ->label('Title')
                ->requiredMapping()
                ->guess(['title', 'note_title', 'subject', 'name', 'heading'])
                ->rules(['required', 'string', 'max:255'])
                ->example('Meeting Notes - Q1 Review')
                ->fillRecordUsing(function (Note $record, string $state, Importer $importer): void {
                    $record->title = trim($state);

                    if (! $record->exists) {
                        $record->team_id = $importer->import->team_id;
                        $record->creator_id = $importer->import->user_id;
                        $record->creation_source = CreationSource::IMPORT;
                    }
                }),

            ImportColumn::make('company_name')
                ->label('Company Name')
                ->guess(['company_name', 'company', 'organization', 'account', 'related_company'])
                ->rules(['nullable', 'string', 'max:255'])
                ->example('Acme Corporation')
                ->fillRecordUsing(function (Note $record, ?string $state, NoteImporter $importer): void {
                    // Store for afterSave() - companies use MorphToMany
                    $importer->originalImportData['company_name'] = $state;
                }),

            ImportColumn::make('person_name')
                ->label('Person Name')
                ->guess(['person_name', 'contact_name', 'contact', 'person', 'related_contact'])
                ->rules(['nullable', 'string', 'max:255'])
                ->example('John Doe')
                ->fillRecordUsing(function (Note $record, ?string $state, NoteImporter $importer): void {
                    // Store for afterSave() - people use MorphToMany
                    $importer->originalImportData['person_name'] = $state;
                }),

            ImportColumn::make('opportunity_name')
                ->label('Opportunity Name')
                ->guess(['opportunity_name', 'opportunity', 'deal', 'deal_name', 'related_deal'])
                ->rules(['nullable', 'string', 'max:255'])
                ->example('Enterprise License Deal')
                ->fillRecordUsing(function (Note $record, ?string $state, NoteImporter $importer): void {
                    // Store for afterSave() - opportunities use MorphToMany
                    $importer->originalImportData['opportunity_name'] = $state;
                }),

            ...CustomFields::importer()->forModel(self::getModel())->columns(),
        ];
    }

    public function resolveRecord(): Note
    {
        $title = $this->data['title'] ?? null;

        if (blank($title)) {
            return new Note;
        }

        $existing = Note::query()
            ->where('team_id', $this->import->team_id)
            ->where('title', trim((string) $title))
            ->first();

        $strategy = $this->getDuplicateStrategy();

        return match ($strategy) {
            DuplicateHandlingStrategy::SKIP => $existing ?? new Note,
            DuplicateHandlingStrategy::UPDATE => $existing ?? new Note,
            DuplicateHandlingStrategy::CREATE_NEW => new Note,
        };
    }

    protected function afterSave(): void
    {
        /** @var Note $note */
        $note = $this->record;

        // Handle company association (MorphToMany via noteables)
        $companyName = $this->originalImportData['company_name'] ?? null;
        if (filled($companyName)) {
            $company = $this->resolveCompanyByName($companyName);
            if ($company instanceof \App\Models\Company) {
                $note->companies()->syncWithoutDetaching([$company->getKey()]);
            }
        }

        // Handle person association (MorphToMany via noteables)
        $personName = $this->originalImportData['person_name'] ?? null;
        if (filled($personName)) {
            $person = $this->resolvePersonByName($personName);
            if ($person instanceof \App\Models\People) {
                $note->people()->syncWithoutDetaching([$person->getKey()]);
            }
        }

        // Handle opportunity association (MorphToMany via noteables)
        $opportunityName = $this->originalImportData['opportunity_name'] ?? null;
        if (filled($opportunityName)) {
            $opportunity = $this->resolveOpportunityByName($opportunityName);
            if ($opportunity instanceof \App\Models\Opportunity) {
                $note->opportunities()->syncWithoutDetaching([$opportunity->getKey()]);
            }
        }

        // Save custom field values
        CustomFields::importer()->forModel($note)->saveValues();

        // Clear stored data for next row
        $this->originalImportData = [];
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your note import has completed and '.Number::format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if (($failedRowsCount = $import->getFailedRowsCount()) !== 0) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }
}
