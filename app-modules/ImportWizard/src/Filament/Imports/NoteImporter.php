<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Imports;

use App\Models\Note;
use Filament\Actions\Imports\ImportColumn;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\ImportWizard\Filament\Imports\Concerns\HasPolymorphicEntityAttachment;

final class NoteImporter extends BaseImporter
{
    use HasPolymorphicEntityAttachment;

    protected static ?string $model = Note::class;

    protected static bool $skipUniqueIdentifierWarning = true;

    public static function getColumns(): array
    {
        return [
            self::buildIdColumn(),

            ImportColumn::make('title')
                ->label('Title')
                ->requiredMapping()
                ->guess(['title', 'note_title', 'subject', 'name', 'heading'])
                ->rules(['required', 'string', 'max:255'])
                ->example('Meeting Notes - Q1 Review')
                ->fillRecordUsing(function (Note $record, string $state, NoteImporter $importer): void {
                    $record->title = trim($state);
                    $importer->initializeNewRecord($record);
                }),

            ImportColumn::make('company_name')
                ->label('Company Name')
                ->guess(['company_name', 'company', 'organization', 'account', 'related_company'])
                ->rules(['nullable', 'string', 'max:255'])
                ->example('Acme Corporation')
                ->fillRecordUsing(function (): void {
                    // Relationship attached in afterSave()
                }),

            ImportColumn::make('person_name')
                ->label('Person Name')
                ->guess(['person_name', 'contact_name', 'contact', 'person', 'related_contact'])
                ->rules(['nullable', 'string', 'max:255'])
                ->example('John Doe')
                ->fillRecordUsing(function (): void {
                    // Relationship attached in afterSave()
                }),

            ImportColumn::make('opportunity_name')
                ->label('Opportunity Name')
                ->guess(['opportunity_name', 'opportunity', 'deal', 'deal_name', 'related_deal'])
                ->rules(['nullable', 'string', 'max:255'])
                ->example('Enterprise License Deal')
                ->fillRecordUsing(function (): void {
                    // Relationship attached in afterSave()
                }),

            ...CustomFields::importer()->forModel(self::getModel())->columns(),
        ];
    }

    public function resolveRecord(): Note
    {
        // ID-based resolution takes absolute precedence
        if ($this->hasIdValue()) {
            /** @var Note|null $record */
            $record = $this->resolveById();

            return $record ?? new Note;
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

        /** @var Note */
        return $this->applyDuplicateStrategy($existing);
    }

    /**
     * Attach polymorphic relationships after the note is saved.
     */
    protected function afterSave(): void
    {
        parent::afterSave();

        $this->attachRelatedEntities();
    }

    public static function getEntityName(): string
    {
        return 'note';
    }
}
