<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Imports;

use App\Enums\CreationSource;
use App\Models\Note;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;

final class NoteImporter extends BaseImporter
{
    protected static ?string $model = Note::class;

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
                ->example('Acme Corporation'),

            ImportColumn::make('person_name')
                ->label('Person Name')
                ->guess(['person_name', 'contact_name', 'contact', 'person', 'related_contact'])
                ->rules(['nullable', 'string', 'max:255'])
                ->example('John Doe'),

            ImportColumn::make('opportunity_name')
                ->label('Opportunity Name')
                ->guess(['opportunity_name', 'opportunity', 'deal', 'deal_name', 'related_deal'])
                ->rules(['nullable', 'string', 'max:255'])
                ->example('Enterprise License Deal'),

            ...CustomFields::importer()->forModel(self::getModel())->columns(),
        ];
    }

    public function resolveRecord(): Note
    {
        // ID-based resolution takes absolute precedence
        if ($this->hasIdValue()) {
            return $this->resolveById() ?? new Note;
        }

        // Fall back to title-based duplicate detection
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

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your note import has completed and '.Number::format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if (($failedRowsCount = $import->getFailedRowsCount()) !== 0) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }
}
