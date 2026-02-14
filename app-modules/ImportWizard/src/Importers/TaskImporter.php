<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Importers;

use App\Models\Task;
use Illuminate\Database\Eloquent\Model;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\ImportField;
use Relaticle\ImportWizard\Data\ImportFieldCollection;
use Relaticle\ImportWizard\Data\MatchableField;

/**
 * Importer for Task entities.
 *
 * Tasks have polymorphic relationships with companies, people, and opportunities.
 * They can only be matched by ID.
 */
final class TaskImporter extends BaseImporter
{
    public function modelClass(): string
    {
        return Task::class;
    }

    public function entityName(): string
    {
        return 'task';
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
                    'title', 'task_title', 'name', 'subject', 'todo',
                    'task', 'task name', 'action', 'action item',
                    'to do', 'todo item', 'activity',
                ])
                ->example('Follow up with client')
                ->icon('heroicon-o-check-circle'),

            ImportField::make('assignee_email')
                ->label('Assignee Email')
                ->rules(['nullable', 'email'])
                ->guess([
                    'assignee', 'assigned_to', 'owner', 'assignee_email',
                    'assigned_email', 'owner_email', 'responsible',
                ])
                ->example('user@company.com')
                ->icon('heroicon-o-envelope'),
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
     * @return array<MatchableField>
     */
    public function matchableFields(): array
    {
        return [
            MatchableField::id(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  &$context
     * @return array<string, mixed>
     */
    public function prepareForSave(array $data, ?Model $existing, array &$context): array
    {
        $data = parent::prepareForSave($data, $existing, $context);

        $assigneeEmail = $data['assignee_email'] ?? null;
        unset($data['assignee_email']);

        if (filled($assigneeEmail)) {
            $user = $this->resolveTeamMemberByEmail($assigneeEmail);
            if ($user instanceof \App\Models\User) {
                $context['assignee_id'] = $user->getKey();
            }
        }

        if (! $existing instanceof Model) {
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

        $this->syncAssignee($record, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function syncAssignee(Model $record, array $context): void
    {
        $assigneeId = $context['assignee_id'] ?? null;

        if ($assigneeId === null) {
            return;
        }

        /** @var Task $record */
        $record->assignees()->syncWithoutDetaching([$assigneeId]);
    }
}
