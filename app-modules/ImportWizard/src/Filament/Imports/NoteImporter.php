<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Imports;

use App\Models\Note;
use Filament\Actions\Imports\ImportColumn;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\ImportWizard\Data\RelationshipField;
use Relaticle\ImportWizard\Filament\Imports\Concerns\HasPolymorphicRelationshipFields;
use Relaticle\ImportWizard\Filament\Imports\Concerns\SyncsPolymorphicLinks;

final class NoteImporter extends BaseImporter
{
    use HasPolymorphicRelationshipFields;
    use SyncsPolymorphicLinks;

    protected static ?string $model = Note::class;

    protected static bool $skipUniqueIdentifierWarning = true;

    public static function getColumns(): array
    {
        return [
            self::buildIdColumn(),

            ImportColumn::make('title')
                ->label('Title')
                ->requiredMapping()
                ->guess([
                    'title', 'note_title', 'subject', 'name', 'heading',
                    'note', 'summary', 'description', 'content_title',
                    'note name', 'note subject', 'topic',
                ])
                ->rules(['required', 'string', 'max:255'])
                ->example('Meeting Notes - Q1 Review')
                ->fillRecordUsing(function (Note $record, string $state, NoteImporter $importer): void {
                    $record->title = trim($state);
                    $importer->initializeNewRecord($record);
                }),

            ...CustomFields::importer()->forModel(self::getModel())->columns(),
        ];
    }

    public function resolveRecord(): Note
    {
        if ($this->hasIdValue()) {
            /** @var Note|null $record */
            $record = $this->resolveById();

            return $record ?? new Note;
        }

        return new Note;
    }

    protected function afterSave(): void
    {
        parent::afterSave();
        $this->syncPendingEntityLinks();
    }

    public static function getEntityName(): string
    {
        return 'note';
    }

    /**
     * @return array<string, RelationshipField>
     */
    public static function getRelationshipFields(): array
    {
        return self::buildPolymorphicRelationshipFields('Note');
    }
}
