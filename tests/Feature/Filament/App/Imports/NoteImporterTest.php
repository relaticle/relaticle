<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\App\Imports;

use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Relaticle\ImportWizard\Filament\Imports\NoteImporter;
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

test('note importer has correct columns defined', function () {
    $columns = NoteImporter::getColumns();

    $columnNames = collect($columns)->map(fn ($column) => $column->getName())->all();

    expect($columnNames)
        ->toContain('title')
        ->toContain('company_name')
        ->toContain('person_name')
        ->toContain('opportunity_name');
});

test('note importer has required title column', function () {
    $columns = NoteImporter::getColumns();

    $titleColumn = collect($columns)->first(fn ($column) => $column->getName() === 'title');

    expect($titleColumn)->not->toBeNull()
        ->and($titleColumn->isMappingRequired())->toBeTrue();
});

test('note importer has options form with duplicate handling', function () {
    $components = NoteImporter::getOptionsFormComponents();

    $duplicateHandlingComponent = collect($components)->first(
        fn ($component) => $component->getName() === 'duplicate_handling'
    );

    expect($duplicateHandlingComponent)->not->toBeNull()
        ->and($duplicateHandlingComponent->isRequired())->toBeTrue();
});

test('import action exists on manage notes page', function () {
    Livewire::test(ManageNotes::class)
        ->assertSuccessful()
        ->assertActionExists('import');
});

test('note importer guesses column names correctly', function () {
    $columns = NoteImporter::getColumns();

    $titleColumn = collect($columns)->first(fn ($column) => $column->getName() === 'title');
    $companyColumn = collect($columns)->first(fn ($column) => $column->getName() === 'company_name');

    expect($titleColumn->getGuesses())
        ->toContain('title')
        ->toContain('note_title')
        ->toContain('subject');

    expect($companyColumn->getGuesses())
        ->toContain('company_name')
        ->toContain('company')
        ->toContain('organization');
});

test('note importer provides example values', function () {
    $columns = NoteImporter::getColumns();

    $titleColumn = collect($columns)->first(fn ($column) => $column->getName() === 'title');
    $companyColumn = collect($columns)->first(fn ($column) => $column->getName() === 'company_name');

    expect($titleColumn->getExample())->toBe('Meeting Notes - Q1 Review')
        ->and($companyColumn->getExample())->toBe('Acme Corporation');
});

test('note importer returns completed notification body', function () {
    $import = new Import;
    $import->successful_rows = 20;

    $body = NoteImporter::getCompletedNotificationBody($import);

    expect($body)->toContain('20')
        ->and($body)->toContain('note')
        ->and($body)->toContain('imported');
});

test('note importer includes failed rows in notification', function () {
    $import = Import::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'successful_rows' => 18,
        'total_rows' => 20,
        'processed_rows' => 20,
        'importer' => NoteImporter::class,
        'file_name' => 'test.csv',
        'file_path' => 'imports/test.csv',
    ]);

    $import->failedRows()->createMany([
        ['data' => ['title' => 'Failed 1'], 'validation_error' => 'Invalid data'],
        ['data' => ['title' => 'Failed 2'], 'validation_error' => 'Invalid data'],
    ]);

    $body = NoteImporter::getCompletedNotificationBody($import);

    expect($body)->toContain('18')
        ->and($body)->toContain('2')
        ->and($body)->toContain('failed');
});
