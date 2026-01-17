<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Importers;

use App\Models\Note;
use Illuminate\Database\Eloquent\Model;
use Relaticle\ImportWizardNew\Data\MatchableField;
use Relaticle\ImportWizardNew\Importers\Fields\FieldCollection;
use Relaticle\ImportWizardNew\Importers\Fields\ImportField;
use Relaticle\ImportWizardNew\Importers\Fields\RelationshipField;

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

    public function fields(): FieldCollection
    {
        return FieldCollection::make([
            ImportField::id(),

            ImportField::make('title')
                ->label('Title')
                ->rules(['nullable', 'string', 'max:255'])
                ->guess([
                    'title', 'subject', 'note_title', 'heading',
                    'note subject', 'summary',
                ])
                ->example('Meeting Notes')
                ->type('text'),

            ImportField::make('content')
                ->label('Content')
                ->required()
                ->rules(['required', 'string'])
                ->guess([
                    'content', 'body', 'text', 'note', 'notes',
                    'note content', 'note body', 'description', 'details',
                ])
                ->example('Discussed project timeline and deliverables.')
                ->type('text'),
        ]);
    }

    /**
     * @return array<string, RelationshipField>
     */
    public function relationships(): array
    {
        return [
            'companies' => RelationshipField::polymorphicCompanies(),
            'people' => RelationshipField::polymorphicPeople(),
            'opportunities' => RelationshipField::polymorphicOpportunities(),
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
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function prepareForSave(array $data, ?Model $existing, array $context): array
    {
        $data = parent::prepareForSave($data, $existing, $context);

        $context = $this->extractPolymorphicIds($data, $context);

        unset($data['companies'], $data['people'], $data['opportunities']);

        if (! $existing instanceof \Illuminate\Database\Eloquent\Model) {
            return $this->initializeNewRecordData($data, $context['creator_id'] ?? null);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function afterSave(Model $record, array $context): void
    {
        parent::afterSave($record, $context);

        $this->syncPolymorphicRelationships($record, $context);
    }

    /**
     * Extract polymorphic relationship IDs from data into context.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function extractPolymorphicIds(array $data, array $context): array
    {
        foreach (['companies', 'people', 'opportunities'] as $relation) {
            $ids = $data[$relation] ?? null;

            if (filled($ids)) {
                $context["{$relation}_ids"] = is_array($ids) ? $ids : [$ids];
            }
        }

        return $context;
    }

    /**
     * Sync polymorphic relationships.
     *
     * @param  array<string, mixed>  $context
     */
    private function syncPolymorphicRelationships(Model $record, array $context): void
    {
        foreach (['companies', 'people', 'opportunities'] as $relation) {
            $ids = $context["{$relation}_ids"] ?? null;

            if (filled($ids) && is_array($ids)) {
                $this->syncMorphToMany($record, $relation, $ids);
            }
        }
    }
}
