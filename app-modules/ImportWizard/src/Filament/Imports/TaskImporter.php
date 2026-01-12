<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Imports;

use App\Models\Task;
use Filament\Actions\Imports\ImportColumn;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\ImportWizard\Data\RelationshipField;
use Relaticle\ImportWizard\Filament\Imports\Concerns\HasPolymorphicRelationshipFields;
use Relaticle\ImportWizard\Filament\Imports\Concerns\SyncsPolymorphicLinks;

final class TaskImporter extends BaseImporter
{
    use HasPolymorphicRelationshipFields;
    use SyncsPolymorphicLinks;

    protected static ?string $model = Task::class;

    protected static array $uniqueIdentifierColumns = ['id'];

    protected static string $missingUniqueIdentifiersMessage = 'For Tasks, map a Record ID column';

    /**
     * Pending assignee ID to attach in afterSave.
     */
    public ?string $pendingAssigneeId = null;

    public static function getColumns(): array
    {
        return [
            self::buildIdColumn(),

            ImportColumn::make('title')
                ->label('Title')
                ->requiredMapping()
                ->guess([
                    'title', 'task_title', 'task_name', 'name', 'subject',
                    'task', 'action', 'action_item', 'to_do', 'todo', 'activity',
                    'task subject', 'task description', 'summary', 'description',
                ])
                ->rules(['required', 'string', 'max:255'])
                ->example('Follow up with client')
                ->fillRecordUsing(function (Task $record, string $state, TaskImporter $importer): void {
                    $record->title = trim($state);
                    $importer->initializeNewRecord($record);
                }),

            ImportColumn::make('assignee_email')
                ->label('Assignee Email')
                ->guess([
                    'assignee_email', 'assignee', 'assigned_to', 'owner', 'responsible',
                    'assignee email', 'assigned to', 'task owner', 'task assignee',
                    'owner_email', 'owner email', 'rep', 'representative',
                ])
                ->rules(['nullable', 'email'])
                ->example('assignee@company.com')
                ->fillRecordUsing(function (Task $record, ?string $state, TaskImporter $importer): void {
                    if (blank($state)) {
                        return;
                    }

                    $user = $importer->resolveTeamMemberByEmail($state);

                    if ($user instanceof \App\Models\User) {
                        $importer->pendingAssigneeId = $user->getKey();
                    }
                }),

            ...CustomFields::importer()->forModel(self::getModel())->columns(),
        ];
    }

    public function resolveRecord(): Task
    {
        if ($this->hasIdValue()) {
            /** @var Task|null $record */
            $record = $this->resolveById();

            return $record ?? new Task;
        }

        return new Task;
    }

    protected function afterSave(): void
    {
        parent::afterSave();

        /** @var Task $task */
        $task = $this->record;

        if ($this->pendingAssigneeId !== null) {
            $task->assignees()->syncWithoutDetaching([$this->pendingAssigneeId]);
        }

        $this->syncPendingEntityLinks();
    }

    public static function getEntityName(): string
    {
        return 'task';
    }

    /**
     * @return array<string, RelationshipField>
     */
    public static function getRelationshipFields(): array
    {
        return self::buildPolymorphicRelationshipFields('Task');
    }
}
