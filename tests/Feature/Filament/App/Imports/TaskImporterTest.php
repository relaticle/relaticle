<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\App\Imports;

use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Relaticle\CustomFields\Services\TenantContextService;
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;
use Relaticle\ImportWizard\Filament\Imports\TaskImporter;
use Relaticle\ImportWizard\Models\Import;

uses(RefreshDatabase::class);

function createTaskTestImportRecord(User $user, Team $team): Import
{
    return Import::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'importer' => TaskImporter::class,
        'file_name' => 'test.csv',
        'file_path' => '/tmp/test.csv',
        'total_rows' => 1,
    ]);
}

function setTaskImporterData(object $importer, array $data): void
{
    $reflection = new \ReflectionClass($importer);
    $dataProperty = $reflection->getProperty('data');
    $dataProperty->setValue($importer, $data);
}

beforeEach(function () {
    Storage::fake('local');

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->user->teams()->attach($this->team);

    $this->actingAs($this->user);
    Filament::setTenant($this->team);
    TenantContextService::setTenantId($this->team->id);
});

test('task importer has correct columns defined', function () {
    $columns = TaskImporter::getColumns();

    $columnNames = collect($columns)->map(fn ($column) => $column->getName())->all();

    // Core database columns and relationship columns are defined explicitly
    // Custom fields like description, status, priority are handled by CustomFields::importer()
    expect($columnNames)
        ->toContain('title')
        ->toContain('company_name')
        ->toContain('person_name')
        ->toContain('opportunity_name')
        ->toContain('assignee_email');
});

test('task importer has required title column', function () {
    $columns = TaskImporter::getColumns();

    $titleColumn = collect($columns)->first(fn ($column) => $column->getName() === 'title');

    expect($titleColumn)->not->toBeNull()
        ->and($titleColumn->isMappingRequired())->toBeTrue();
});

test('task importer has options form with duplicate handling', function () {
    $components = TaskImporter::getOptionsFormComponents();

    $duplicateHandlingComponent = collect($components)->first(
        fn ($component) => $component->getName() === 'duplicate_handling'
    );

    expect($duplicateHandlingComponent)->not->toBeNull()
        ->and($duplicateHandlingComponent->isRequired())->toBeTrue();
});

test('import action exists on manage tasks page', function () {
    Livewire::test(ManageTasks::class)
        ->assertSuccessful()
        ->assertActionExists('import');
});

test('task importer guesses column names correctly', function () {
    $columns = TaskImporter::getColumns();

    $titleColumn = collect($columns)->first(fn ($column) => $column->getName() === 'title');
    $assigneeColumn = collect($columns)->first(fn ($column) => $column->getName() === 'assignee_email');

    expect($titleColumn->getGuesses())
        ->toContain('title')
        ->toContain('task_title')
        ->toContain('task_name');

    expect($assigneeColumn->getGuesses())
        ->toContain('assignee_email')
        ->toContain('assignee')
        ->toContain('assigned_to');
});

test('task importer provides example values', function () {
    $columns = TaskImporter::getColumns();

    $titleColumn = collect($columns)->first(fn ($column) => $column->getName() === 'title');

    expect($titleColumn->getExample())->toBe('Follow up with client');
});

test('task importer returns completed notification body', function () {
    $import = new Import;
    $import->successful_rows = 15;

    $body = TaskImporter::getCompletedNotificationBody($import);

    expect($body)->toContain('15')
        ->and($body)->toContain('task')
        ->and($body)->toContain('imported');
});

test('task importer includes failed rows in notification', function () {
    $import = Import::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'successful_rows' => 12,
        'total_rows' => 15,
        'processed_rows' => 15,
        'importer' => TaskImporter::class,
        'file_name' => 'test.csv',
        'file_path' => 'imports/test.csv',
    ]);

    $import->failedRows()->createMany([
        ['data' => ['title' => 'Failed 1'], 'validation_error' => 'Invalid data'],
        ['data' => ['title' => 'Failed 2'], 'validation_error' => 'Invalid data'],
        ['data' => ['title' => 'Failed 3'], 'validation_error' => 'Invalid data'],
    ]);

    $body = TaskImporter::getCompletedNotificationBody($import);

    expect($body)->toContain('12')
        ->and($body)->toContain('3')
        ->and($body)->toContain('failed');
});

describe('Polymorphic Relationship Attachment', function (): void {
    it('attaches task to existing company', function (): void {
        $company = Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Corp']);

        $import = createTaskTestImportRecord($this->user, $this->team);
        $importer = new TaskImporter(
            $import,
            ['title' => 'title', 'company_name' => 'company_name'],
            ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]
        );

        setTaskImporterData($importer, [
            'title' => 'Follow up',
            'company_name' => 'Acme Corp',
        ]);

        ($importer)(['title' => 'Follow up', 'company_name' => 'Acme Corp']);

        $task = Task::query()->where('title', 'Follow up')->first();
        expect($task)->not->toBeNull()
            ->and($task->companies)->toHaveCount(1)
            ->and($task->companies->first()->id)->toBe($company->id);
    });

    it('creates and attaches new company when it does not exist', function (): void {
        $import = createTaskTestImportRecord($this->user, $this->team);
        $importer = new TaskImporter(
            $import,
            ['title' => 'title', 'company_name' => 'company_name'],
            ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]
        );

        setTaskImporterData($importer, [
            'title' => 'New Task',
            'company_name' => 'New Company Inc',
        ]);

        ($importer)(['title' => 'New Task', 'company_name' => 'New Company Inc']);

        $task = Task::query()->where('title', 'New Task')->first();
        $company = Company::query()->where('name', 'New Company Inc')->first();

        expect($task)->not->toBeNull()
            ->and($company)->not->toBeNull()
            ->and($task->companies)->toHaveCount(1)
            ->and($task->companies->first()->id)->toBe($company->id);
    });

    it('attaches task to person', function (): void {
        $person = People::factory()->for($this->team, 'team')->create(['name' => 'John Doe']);

        $import = createTaskTestImportRecord($this->user, $this->team);
        $importer = new TaskImporter(
            $import,
            ['title' => 'title', 'person_name' => 'person_name'],
            ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]
        );

        setTaskImporterData($importer, [
            'title' => 'Contact John',
            'person_name' => 'John Doe',
        ]);

        ($importer)(['title' => 'Contact John', 'person_name' => 'John Doe']);

        $task = Task::query()->where('title', 'Contact John')->first();
        expect($task)->not->toBeNull()
            ->and($task->people)->toHaveCount(1)
            ->and($task->people->first()->id)->toBe($person->id);
    });

    it('attaches task to opportunity', function (): void {
        $opportunity = Opportunity::factory()->for($this->team, 'team')->create(['name' => 'Big Deal']);

        $import = createTaskTestImportRecord($this->user, $this->team);
        $importer = new TaskImporter(
            $import,
            ['title' => 'title', 'opportunity_name' => 'opportunity_name'],
            ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]
        );

        setTaskImporterData($importer, [
            'title' => 'Follow up on deal',
            'opportunity_name' => 'Big Deal',
        ]);

        ($importer)(['title' => 'Follow up on deal', 'opportunity_name' => 'Big Deal']);

        $task = Task::query()->where('title', 'Follow up on deal')->first();
        expect($task)->not->toBeNull()
            ->and($task->opportunities)->toHaveCount(1)
            ->and($task->opportunities->first()->id)->toBe($opportunity->id);
    });

    it('attaches task to multiple entities', function (): void {
        $company = Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Corp']);
        $person = People::factory()->for($this->team, 'team')->create(['name' => 'John Doe']);
        $opportunity = Opportunity::factory()->for($this->team, 'team')->create(['name' => 'Big Deal']);

        $import = createTaskTestImportRecord($this->user, $this->team);
        $importer = new TaskImporter(
            $import,
            [
                'title' => 'title',
                'company_name' => 'company_name',
                'person_name' => 'person_name',
                'opportunity_name' => 'opportunity_name',
            ],
            ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]
        );

        setTaskImporterData($importer, [
            'title' => 'Complex Task',
            'company_name' => 'Acme Corp',
            'person_name' => 'John Doe',
            'opportunity_name' => 'Big Deal',
        ]);

        ($importer)([
            'title' => 'Complex Task',
            'company_name' => 'Acme Corp',
            'person_name' => 'John Doe',
            'opportunity_name' => 'Big Deal',
        ]);

        $task = Task::query()->where('title', 'Complex Task')->first();
        expect($task)->not->toBeNull()
            ->and($task->companies)->toHaveCount(1)
            ->and($task->people)->toHaveCount(1)
            ->and($task->opportunities)->toHaveCount(1);
    });

    it('attaches assignee by email', function (): void {
        // The user must be a member of the team
        $assignee = User::factory()->create();
        $assignee->teams()->attach($this->team);

        $import = createTaskTestImportRecord($this->user, $this->team);
        $importer = new TaskImporter(
            $import,
            ['title' => 'title', 'assignee_email' => 'assignee_email'],
            ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]
        );

        setTaskImporterData($importer, [
            'title' => 'Assigned Task',
            'assignee_email' => $assignee->email,
        ]);

        ($importer)(['title' => 'Assigned Task', 'assignee_email' => $assignee->email]);

        $task = Task::query()->where('title', 'Assigned Task')->first();
        expect($task)->not->toBeNull()
            ->and($task->assignees)->toHaveCount(1)
            ->and($task->assignees->first()->id)->toBe($assignee->id);
    });
});
