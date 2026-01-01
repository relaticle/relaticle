<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;
use Relaticle\ImportWizard\Filament\Imports\TaskImporter;

beforeEach(function (): void {
    Storage::fake('local');
    ['user' => $this->user, 'team' => $this->team] = setupImportTestContext();
});

describe('Polymorphic Relationship Attachment', function (): void {
    it('attaches task to :dataset', function (string $relationMethod, string $entityFactory, string $columnKey, string $entityName): void {
        $entity = match ($entityFactory) {
            'company' => Company::factory()->for($this->team, 'team')->create(['name' => $entityName]),
            'person' => People::factory()->for($this->team, 'team')->create(['name' => $entityName]),
            'opportunity' => Opportunity::factory()->for($this->team, 'team')->create(['name' => $entityName]),
        };

        $import = createImportRecord($this->user, $this->team, TaskImporter::class);
        $importer = new TaskImporter($import, ['title' => 'title', $columnKey => $columnKey], ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]);

        $data = ['title' => 'Test Task', $columnKey => $entityName];
        setImporterData($importer, $data);
        ($importer)($data);

        $task = Task::where('title', 'Test Task')->first();

        expect($task)->not->toBeNull()
            ->and($task->{$relationMethod})->toHaveCount(1)
            ->and($task->{$relationMethod}->first()->id)->toBe($entity->id);
    })->with([
        'existing company' => ['companies', 'company', 'company_name', 'Acme Corp'],
        'person' => ['people', 'person', 'person_name', 'John Doe'],
        'opportunity' => ['opportunities', 'opportunity', 'opportunity_name', 'Big Deal'],
    ]);

    it('creates and attaches new company when nonexistent', function (): void {
        $import = createImportRecord($this->user, $this->team, TaskImporter::class);
        $importer = new TaskImporter($import, ['title' => 'title', 'company_name' => 'company_name'], ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]);

        $data = ['title' => 'New Task', 'company_name' => 'New Company Inc'];
        setImporterData($importer, $data);
        ($importer)($data);

        $task = Task::where('title', 'New Task')->first();
        $company = Company::where('name', 'New Company Inc')->first();

        expect($task)->not->toBeNull()
            ->and($company)->not->toBeNull()
            ->and($task->companies)->toHaveCount(1)
            ->and($task->companies->first()->id)->toBe($company->id);
    });

    it('attaches task to multiple entities', function (): void {
        $company = Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Corp']);
        $person = People::factory()->for($this->team, 'team')->create(['name' => 'John Doe']);
        $opportunity = Opportunity::factory()->for($this->team, 'team')->create(['name' => 'Big Deal']);

        $import = createImportRecord($this->user, $this->team, TaskImporter::class);
        $importer = new TaskImporter(
            $import,
            ['title' => 'title', 'company_name' => 'company_name', 'person_name' => 'person_name', 'opportunity_name' => 'opportunity_name'],
            ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]
        );

        $data = ['title' => 'Complex Task', 'company_name' => 'Acme Corp', 'person_name' => 'John Doe', 'opportunity_name' => 'Big Deal'];
        setImporterData($importer, $data);
        ($importer)($data);

        $task = Task::where('title', 'Complex Task')->first();

        expect($task)->not->toBeNull()
            ->and($task->companies)->toHaveCount(1)
            ->and($task->people)->toHaveCount(1)
            ->and($task->opportunities)->toHaveCount(1);
    });

    it('attaches assignee by email', function (): void {
        $assignee = User::factory()->create();
        $assignee->teams()->attach($this->team);

        $import = createImportRecord($this->user, $this->team, TaskImporter::class);
        $importer = new TaskImporter($import, ['title' => 'title', 'assignee_email' => 'assignee_email'], ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]);

        $data = ['title' => 'Assigned Task', 'assignee_email' => $assignee->email];
        setImporterData($importer, $data);
        ($importer)($data);

        $task = Task::where('title', 'Assigned Task')->first();

        expect($task)->not->toBeNull()
            ->and($task->assignees)->toHaveCount(1)
            ->and($task->assignees->first()->id)->toBe($assignee->id);
    });
});
