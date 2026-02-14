<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Importers;

use App\Models\Note;
use Illuminate\Database\Eloquent\Model;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\ImportField;
use Relaticle\ImportWizard\Data\ImportFieldCollection;
use Relaticle\ImportWizard\Data\MatchableField;

/**
 * Importer for Note entities.
 *
 * Notes have polymorphic relationships with companies, people, and opportunities.
 * Notes cannot be matched to existing records (always create new).
 */
final class NoteImporter extends BaseImporter
{
    public function modelClass(): string
    {
        return Note::class;
    }

    public function entityName(): string
    {
        return 'note';
    }

    public function fields(): ImportFieldCollection
    {
        return new ImportFieldCollection([
            ImportField::id(),

            ImportField::make('title')
                ->label('Title')
                ->required()
                ->rules(['required', 'string', 'max:255'])
                ->guess([
                    'title', 'subject', 'note_title', 'heading',
                    'note subject', 'summary',
                ])
                ->example('Meeting Notes')
                ->icon('heroicon-o-pencil-square'),
        ]);
    }

    /**
     * @return array<string, EntityLink>
     */
    protected function defineEntityLinks(): array
    {
        return [
            'companies' => EntityLink::polymorphicCompanies(),
            'people' => EntityLink::polymorphicPeople(),
            'opportunities' => EntityLink::polymorphicOpportunities(),
        ];
    }

    /**
     * Notes cannot be matched - always create new.
     *
     * @return array<MatchableField>
     */
    public function matchableFields(): array
    {
        return [];
    }

    /**
     * Notes don't require unique identifiers - always create new.
     */
    public function requiresUniqueIdentifier(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  &$context
     * @return array<string, mixed>
     */
    public function prepareForSave(array $data, ?Model $existing, array &$context): array
    {
        $data = parent::prepareForSave($data, $existing, $context);

        if (! $existing instanceof Model) {
            return $this->initializeNewRecordData($data, $context['creator_id'] ?? null);
        }

        return $data;
    }
}
