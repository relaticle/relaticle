<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\App\Imports;

use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Relaticle\ImportWizard\Filament\Imports\TaskImporter;
use Relaticle\ImportWizard\Models\Import;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->user->teams()->attach($this->team);

    $this->actingAs($this->user);
    Filament::setTenant($this->team);
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
