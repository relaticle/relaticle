<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Imports;

use App\Models\Task;
use Filament\Actions\Imports\ImportColumn;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\ImportWizard\Filament\Imports\Concerns\HasPolymorphicEntityAttachment;

final class TaskImporter extends BaseImporter
{
    use HasPolymorphicEntityAttachment;

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
                ->guess(['title', 'task_title', 'task_name', 'name', 'subject'])
                ->rules(['required', 'string', 'max:255'])
                ->example('Follow up with client')
                ->fillRecordUsing(function (Task $record, string $state, TaskImporter $importer): void {
                    $record->title = trim($state);
                    $importer->initializeNewRecord($record);
                }),

            ImportColumn::make('company_name')
                ->label('Company Name')
                ->guess(['company_name', 'company', 'organization', 'account'])
                ->rules(['nullable', 'string', 'max:255'])
                ->example('Acme Corporation')
                ->fillRecordUsing(function (): void {
                    // Relationship attached in afterSave()
                }),

            ImportColumn::make('person_name')
                ->label('Person Name')
                ->guess(['person_name', 'contact_name', 'contact', 'person', 'related_to'])
                ->rules(['nullable', 'string', 'max:255'])
                ->example('John Doe')
                ->fillRecordUsing(function (): void {
                    // Relationship attached in afterSave()
                }),

            ImportColumn::make('opportunity_name')
                ->label('Opportunity Name')
                ->guess(['opportunity_name', 'opportunity', 'deal', 'deal_name'])
                ->rules(['nullable', 'string', 'max:255'])
                ->example('Enterprise License Deal')
                ->fillRecordUsing(function (): void {
                    // Relationship attached in afterSave()
                }),

            ImportColumn::make('assignee_email')
                ->label('Assignee Email')
                ->guess(['assignee_email', 'assignee', 'assigned_to', 'owner', 'responsible'])
                ->rules(['nullable', 'email'])
                ->example('assignee@company.com')
                ->fillRecordUsing(function (Task $record, ?string $state, TaskImporter $importer): void {
                    if (blank($state)) {
                        return;
                    }

                    $user = $importer->resolveTeamMemberByEmail($state);

                    if ($user instanceof \App\Models\User) {
                        // Store assignee to attach in afterSave (assignees is a BelongsToMany)
                        $importer->pendingAssigneeId = $user->getKey();
                    }
                }),

            ...CustomFields::importer()->forModel(self::getModel())->columns(),
        ];
    }

    public function resolveRecord(): Task
    {
        // ID-based matching only
        if ($this->hasIdValue()) {
            /** @var Task|null $record */
            $record = $this->resolveById();

            return $record ?? new Task;
        }

        // No match found - create new task
        return new Task;
    }

    /**
     * Attach polymorphic relationships and assignees after the task is saved.
     */
    protected function afterSave(): void
    {
        parent::afterSave();

        $this->attachRelatedEntities();

        // Attach assignee (Task-specific)
        if ($this->pendingAssigneeId !== null) {
            /** @var Task $task */
            $task = $this->record;
            $task->assignees()->syncWithoutDetaching([$this->pendingAssigneeId]);
        }
    }

    public static function getEntityName(): string
    {
        return 'task';
    }
}
