<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\App\Imports;

use App\Filament\Pages\ImportCenter;
use App\Models\Team;
use App\Models\User;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->user->teams()->attach($this->team);

    $this->actingAs($this->user);
    Filament::setTenant($this->team);
});

test('import center page renders successfully', function () {
    Livewire::test(ImportCenter::class)
        ->assertSuccessful()
        ->assertSee('Import Center');
});

test('import center shows quick import tab by default', function () {
    Livewire::test(ImportCenter::class)
        ->assertSet('activeTab', 'quick-import');
});

test('import center can switch to history tab', function () {
    Livewire::test(ImportCenter::class)
        ->call('setActiveTab', 'history')
        ->assertSet('activeTab', 'history');
});

test('import center can switch to migration tab', function () {
    Livewire::test(ImportCenter::class)
        ->call('setActiveTab', 'migration')
        ->assertSet('activeTab', 'migration');
});

test('import center has all entity types defined', function () {
    $component = Livewire::test(ImportCenter::class);
    $entityTypes = $component->instance()->getEntityTypes();

    expect($entityTypes)
        ->toHaveKey('companies')
        ->toHaveKey('people')
        ->toHaveKey('opportunities')
        ->toHaveKey('tasks')
        ->toHaveKey('notes');
});

test('entity types have correct structure', function () {
    $component = Livewire::test(ImportCenter::class);
    $entityTypes = $component->instance()->getEntityTypes();

    foreach ($entityTypes as $key => $entity) {
        expect($entity)
            ->toHaveKey('label')
            ->toHaveKey('icon')
            ->toHaveKey('description')
            ->toHaveKey('importer');
    }
});

test('import center shows import history in table', function () {
    // Create some imports for this team
    for ($i = 0; $i < 3; $i++) {
        Import::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'successful_rows' => 10,
            'total_rows' => 10,
            'processed_rows' => 10,
            'importer' => 'App\\Filament\\Imports\\CompanyImporter',
            'file_name' => "test{$i}.csv",
            'file_path' => "imports/test{$i}.csv",
        ]);
    }

    Livewire::test(ImportCenter::class)
        ->call('setActiveTab', 'history')
        ->assertCanSeeTableRecords(Import::where('team_id', $this->team->id)->get());
});

test('import history only shows team imports', function () {
    // Create import for this team
    $teamImport = Import::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'successful_rows' => 10,
        'total_rows' => 10,
        'processed_rows' => 10,
        'importer' => 'App\\Filament\\Imports\\CompanyImporter',
        'file_name' => 'team.csv',
        'file_path' => 'imports/team.csv',
    ]);

    // Create import for another team
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherImport = Import::create([
        'team_id' => $otherTeam->id,
        'user_id' => $otherUser->id,
        'successful_rows' => 5,
        'total_rows' => 5,
        'processed_rows' => 5,
        'importer' => 'App\\Filament\\Imports\\CompanyImporter',
        'file_name' => 'other.csv',
        'file_path' => 'imports/other.csv',
    ]);

    Livewire::test(ImportCenter::class)
        ->call('setActiveTab', 'history')
        ->assertCanSeeTableRecords([$teamImport])
        ->assertCanNotSeeTableRecords([$otherImport]);
});

test('import center can mount import action for entity type', function () {
    Livewire::test(ImportCenter::class)
        ->callAction('importCompanies')
        ->assertSuccessful();
});

test('import center throws exception for unknown entity type', function () {
    $component = Livewire::test(ImportCenter::class);

    expect(fn () => $component->instance()->getImportAction('unknown'))
        ->toThrow(\InvalidArgumentException::class);
});

test('import center has correct navigation properties', function () {
    expect(ImportCenter::getNavigationLabel())->toBe('Import Center')
        ->and(ImportCenter::getNavigationSort())->toBe(2);
});

test('import status shows completed when completed_at is set', function () {
    Import::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'successful_rows' => 10,
        'total_rows' => 10,
        'processed_rows' => 10,
        'importer' => 'App\\Filament\\Imports\\CompanyImporter',
        'file_name' => 'test.csv',
        'file_path' => 'imports/test.csv',
        'completed_at' => now(),
    ]);

    // Switch to history tab to see the table with status
    Livewire::test(ImportCenter::class)
        ->call('setActiveTab', 'history')
        ->assertSee('Completed');
});

test('import status shows completed when all rows are processed', function () {
    Import::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'successful_rows' => 10,
        'total_rows' => 10,
        'processed_rows' => 10,
        'importer' => 'App\\Filament\\Imports\\CompanyImporter',
        'file_name' => 'test.csv',
        'file_path' => 'imports/test.csv',
        'completed_at' => null,
    ]);

    Livewire::test(ImportCenter::class)
        ->call('setActiveTab', 'history')
        ->assertSee('Completed');
});

test('import status shows processing for active imports', function () {
    Import::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'successful_rows' => 5,
        'total_rows' => 10,
        'processed_rows' => 5,
        'importer' => 'App\\Filament\\Imports\\CompanyImporter',
        'file_name' => 'test.csv',
        'file_path' => 'imports/test.csv',
        'completed_at' => null,
    ]);

    Livewire::test(ImportCenter::class)
        ->call('setActiveTab', 'history')
        ->assertSee('Processing');
});

test('import status shows pending when import has not started', function () {
    Import::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'successful_rows' => 0,
        'total_rows' => 0,
        'processed_rows' => 0,
        'importer' => 'App\\Filament\\Imports\\CompanyImporter',
        'file_name' => 'test.csv',
        'file_path' => 'imports/test.csv',
        'completed_at' => null,
    ]);

    Livewire::test(ImportCenter::class)
        ->call('setActiveTab', 'history')
        ->assertSee('Pending');
});

test('import status shows failed when import is stuck', function () {
    // Create an import that has been stuck for more than 10 minutes
    $import = Import::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'successful_rows' => 5,
        'total_rows' => 10,
        'processed_rows' => 5,
        'importer' => 'App\\Filament\\Imports\\CompanyImporter',
        'file_name' => 'test.csv',
        'file_path' => 'imports/test.csv',
        'completed_at' => null,
    ]);

    // Manually update the timestamps to simulate stuck import
    $import->update([
        'updated_at' => now()->subMinutes(15),
        'created_at' => now()->subMinutes(20),
    ]);

    Livewire::test(ImportCenter::class)
        ->call('setActiveTab', 'history')
        ->assertSee('Failed');
});

test('import status shows failed when job is in failed_jobs table', function () {
    $import = Import::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'successful_rows' => 0,
        'total_rows' => 10,
        'processed_rows' => 0,
        'importer' => 'App\\Filament\\Imports\\CompanyImporter',
        'file_name' => 'test.csv',
        'file_path' => 'imports/test.csv',
        'completed_at' => null,
    ]);

    // Insert a failed job record that references this import
    \Illuminate\Support\Facades\DB::table('failed_jobs')->insert([
        'uuid' => \Illuminate\Support\Str::uuid()->toString(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode([
            'displayName' => 'Filament\\Actions\\Imports\\Jobs\\ImportCsv',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'commandName' => 'Filament\\Actions\\Imports\\Jobs\\ImportCsv',
                'command' => serialize(['import' => $import->id]),
            ],
        ]),
        'exception' => 'Test exception',
        'failed_at' => now(),
    ]);

    Livewire::test(ImportCenter::class)
        ->call('setActiveTab', 'history')
        ->assertSee('Failed');
});

test('import status shows failed when created long ago but never processed', function () {
    $import = Import::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'successful_rows' => 0,
        'total_rows' => 10,
        'processed_rows' => 0,
        'importer' => 'App\\Filament\\Imports\\CompanyImporter',
        'file_name' => 'test.csv',
        'file_path' => 'imports/test.csv',
        'completed_at' => null,
    ]);

    // Manually update the timestamps to simulate stuck import
    $import->update([
        'created_at' => now()->subMinutes(15),
        'updated_at' => now()->subMinutes(15),
    ]);

    Livewire::test(ImportCenter::class)
        ->call('setActiveTab', 'history')
        ->assertSee('Failed');
});
