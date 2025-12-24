<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\App\Imports;

use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Models\Team;
use App\Models\User;
use Relaticle\ImportWizard\Models\Import;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;
use Relaticle\ImportWizard\Filament\Imports\CompanyImporter;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->user->teams()->attach($this->team);

    $this->actingAs($this->user);
    Filament::setTenant($this->team);
});

test('company importer has correct columns defined', function () {
    $columns = CompanyImporter::getColumns();

    $columnNames = collect($columns)->map(fn ($column) => $column->getName())->all();

    // Core database columns are defined explicitly
    // Custom fields like address, country, phone are handled by CustomFields::importer()
    expect($columnNames)
        ->toContain('name')
        ->toContain('account_owner_email');
});

test('company importer has required name column', function () {
    $columns = CompanyImporter::getColumns();

    $nameColumn = collect($columns)->first(fn ($column) => $column->getName() === 'name');

    expect($nameColumn)->not->toBeNull()
        ->and($nameColumn->isMappingRequired())->toBeTrue();
});

test('company importer has options form with duplicate handling', function () {
    $components = CompanyImporter::getOptionsFormComponents();

    $duplicateHandlingComponent = collect($components)->first(
        fn ($component) => $component->getName() === 'duplicate_handling'
    );

    expect($duplicateHandlingComponent)->not->toBeNull()
        ->and($duplicateHandlingComponent->isRequired())->toBeTrue();
});

test('import action exists on list companies page', function () {
    Livewire::test(ListCompanies::class)
        ->assertSuccessful()
        ->assertActionExists('import');
});

test('company importer guesses column names correctly', function () {
    $columns = CompanyImporter::getColumns();

    $nameColumn = collect($columns)->first(fn ($column) => $column->getName() === 'name');

    expect($nameColumn->getGuesses())
        ->toContain('name')
        ->toContain('company_name')
        ->toContain('company');
});

test('company importer provides example values', function () {
    $columns = CompanyImporter::getColumns();

    $nameColumn = collect($columns)->first(fn ($column) => $column->getName() === 'name');

    expect($nameColumn->getExample())->not->toBeNull()
        ->and($nameColumn->getExample())->toBe('Acme Corporation');
});

test('duplicate handling strategy enum has correct values', function () {
    expect(DuplicateHandlingStrategy::SKIP->value)->toBe('skip')
        ->and(DuplicateHandlingStrategy::UPDATE->value)->toBe('update')
        ->and(DuplicateHandlingStrategy::CREATE_NEW->value)->toBe('create_new');
});

test('duplicate handling strategy has labels', function () {
    expect(DuplicateHandlingStrategy::SKIP->getLabel())->toBe('Skip duplicates')
        ->and(DuplicateHandlingStrategy::UPDATE->getLabel())->toBe('Update existing records')
        ->and(DuplicateHandlingStrategy::CREATE_NEW->getLabel())->toBe('Create new records anyway');
});

test('company importer returns completed notification body', function () {
    $import = new Import;
    $import->successful_rows = 10;

    $body = CompanyImporter::getCompletedNotificationBody($import);

    expect($body)->toContain('10')
        ->and($body)->toContain('imported');
});

test('company importer includes failed rows in notification', function () {
    $import = Import::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'successful_rows' => 8,
        'total_rows' => 10,
        'processed_rows' => 10,
        'importer' => CompanyImporter::class,
        'file_name' => 'test.csv',
        'file_path' => 'imports/test.csv',
    ]);

    // Create some failed rows
    $import->failedRows()->createMany([
        ['data' => ['name' => 'Failed 1'], 'validation_error' => 'Invalid data'],
        ['data' => ['name' => 'Failed 2'], 'validation_error' => 'Invalid data'],
    ]);

    $body = CompanyImporter::getCompletedNotificationBody($import);

    expect($body)->toContain('8')
        ->and($body)->toContain('2')
        ->and($body)->toContain('failed');
});
