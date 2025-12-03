<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Enums\CreationSource;
use App\Enums\DuplicateHandlingStrategy;
use App\Models\Task;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;
use Relaticle\CustomFields\Facades\CustomFields;

final class TaskImporter extends BaseImporter
{
    protected static ?string $model = Task::class;

    /**
     * Store original data for afterSave() to handle relationships.
     *
     * @var array<string, mixed>
     */
    protected array $originalImportData = [];

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('title')
                ->requiredMapping()
                ->guess(['title', 'task_title', 'task_name', 'name', 'subject'])
                ->rules(['required', 'string', 'max:255'])
                ->example('Follow up with client')
                ->fillRecordUsing(function (Task $record, string $state, Importer $importer): void {
                    $record->title = trim($state);

                    if (! $record->exists) {
                        $record->team_id = $importer->import->team_id;
                        $record->creator_id = $importer->import->user_id;
                        $record->creation_source = CreationSource::IMPORT;
                    }
                }),

            ImportColumn::make('company_name')
                ->label('Company Name')
                ->guess(['company_name', 'company', 'organization', 'account'])
                ->rules(['nullable', 'string', 'max:255'])
                ->example('Acme Corporation')
                ->fillRecordUsing(function (Task $record, ?string $state, TaskImporter $importer): void {
                    // Store for afterSave() - companies use MorphToMany
                    $importer->originalImportData['company_name'] = $state;
                }),

            ImportColumn::make('person_name')
                ->label('Person Name')
                ->guess(['person_name', 'contact_name', 'contact', 'person', 'related_to'])
                ->rules(['nullable', 'string', 'max:255'])
                ->example('John Doe')
                ->fillRecordUsing(function (Task $record, ?string $state, TaskImporter $importer): void {
                    // Store for afterSave() - people use MorphToMany
                    $importer->originalImportData['person_name'] = $state;
                }),

            ImportColumn::make('opportunity_name')
                ->label('Opportunity Name')
                ->guess(['opportunity_name', 'opportunity', 'deal', 'deal_name'])
                ->rules(['nullable', 'string', 'max:255'])
                ->example('Enterprise License Deal')
                ->fillRecordUsing(function (Task $record, ?string $state, TaskImporter $importer): void {
                    // Store for afterSave() - opportunities use MorphToMany
                    $importer->originalImportData['opportunity_name'] = $state;
                }),

            ImportColumn::make('assignee_email')
                ->label('Assignee Email')
                ->guess(['assignee_email', 'assignee', 'assigned_to', 'owner', 'responsible'])
                ->rules(['nullable', 'email'])
                ->example('assignee@company.com')
                ->fillRecordUsing(function (Task $record, ?string $state, TaskImporter $importer): void {
                    // Store for afterSave() - assignees use BelongsToMany
                    $importer->originalImportData['assignee_email'] = $state;
                }),

            ...CustomFields::importer()->forModel(self::getModel())->columns(),
        ];
    }

    public function resolveRecord(): Task
    {
        $title = $this->data['title'] ?? null;

        if (blank($title)) {
            return new Task;
        }

        $existing = Task::query()
            ->where('team_id', $this->import->team_id)
            ->where('title', trim($title))
            ->first();

        $strategy = $this->getDuplicateStrategy();

        return match ($strategy) {
            DuplicateHandlingStrategy::SKIP => $existing ?? new Task,
            DuplicateHandlingStrategy::UPDATE => $existing ?? new Task,
            DuplicateHandlingStrategy::CREATE_NEW => new Task,
        };
    }

    protected function afterSave(): void
    {
        /** @var Task $task */
        $task = $this->record;

        // Handle company association (MorphToMany via taskables)
        $companyName = $this->originalImportData['company_name'] ?? null;
        if (filled($companyName)) {
            $company = $this->resolveCompanyByName($companyName);
            if ($company !== null) {
                $task->companies()->syncWithoutDetaching([$company->getKey()]);
            }
        }

        // Handle person association (MorphToMany via taskables)
        $personName = $this->originalImportData['person_name'] ?? null;
        if (filled($personName)) {
            $person = $this->resolvePersonByName($personName);
            if ($person !== null) {
                $task->people()->syncWithoutDetaching([$person->getKey()]);
            }
        }

        // Handle opportunity association (MorphToMany via taskables)
        $opportunityName = $this->originalImportData['opportunity_name'] ?? null;
        if (filled($opportunityName)) {
            $opportunity = $this->resolveOpportunityByName($opportunityName);
            if ($opportunity !== null) {
                $task->opportunities()->syncWithoutDetaching([$opportunity->getKey()]);
            }
        }

        // Handle assignee association (BelongsToMany via task_user)
        $assigneeEmail = $this->originalImportData['assignee_email'] ?? null;
        if (filled($assigneeEmail)) {
            $user = $this->resolveTeamMemberByEmail($assigneeEmail);
            if ($user !== null) {
                $task->assignees()->syncWithoutDetaching([$user->getKey()]);
            }
        }

        // Save custom field values
        CustomFields::importer()->forModel($task)->saveValues();

        // Clear stored data for next row
        $this->originalImportData = [];
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your task import has completed and '.Number::format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if (($failedRowsCount = $import->getFailedRowsCount()) !== 0) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }
}
