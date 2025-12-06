<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\App\Imports;

use App\Livewire\Import\MigrationWizard;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->user->teams()->attach($this->team);

    $this->actingAs($this->user);
    Filament::setTenant($this->team);
});

test('migration wizard renders successfully', function () {
    Livewire::test(MigrationWizard::class)
        ->assertSuccessful()
        ->assertSee('Select What to Import');
});

test('migration wizard starts at step 1', function () {
    Livewire::test(MigrationWizard::class)
        ->assertSet('currentStep', 1);
});

test('migration wizard has all entity types', function () {
    $component = Livewire::test(MigrationWizard::class);
    $entities = $component->instance()->getEntities();

    expect($entities)
        ->toHaveKey('companies')
        ->toHaveKey('people')
        ->toHaveKey('opportunities')
        ->toHaveKey('tasks')
        ->toHaveKey('notes');
});

test('companies entity has no dependencies', function () {
    $component = Livewire::test(MigrationWizard::class);
    $entities = $component->instance()->getEntities();

    expect($entities['companies']['dependencies'])->toBeEmpty();
});

test('people entity depends on companies', function () {
    $component = Livewire::test(MigrationWizard::class);
    $entities = $component->instance()->getEntities();

    expect($entities['people']['dependencies'])->toContain('companies');
});

test('opportunities entity depends on companies only', function () {
    $component = Livewire::test(MigrationWizard::class);
    $entities = $component->instance()->getEntities();

    expect($entities['opportunities']['dependencies'])
        ->toBe(['companies']);
});

test('can select company entity without dependencies', function () {
    Livewire::test(MigrationWizard::class)
        ->call('toggleEntity', 'companies')
        ->assertSet('selectedEntities.companies', true);
});

test('cannot select people without first selecting companies', function () {
    Livewire::test(MigrationWizard::class)
        ->call('toggleEntity', 'people')
        ->assertSet('selectedEntities.people', false);
});

test('can select people after selecting companies', function () {
    Livewire::test(MigrationWizard::class)
        ->call('toggleEntity', 'companies')
        ->call('toggleEntity', 'people')
        ->assertSet('selectedEntities.companies', true)
        ->assertSet('selectedEntities.people', true);
});

test('unselecting companies cascades to unselect people', function () {
    Livewire::test(MigrationWizard::class)
        ->call('toggleEntity', 'companies')
        ->call('toggleEntity', 'people')
        ->call('toggleEntity', 'companies')
        ->assertSet('selectedEntities.companies', false)
        ->assertSet('selectedEntities.people', false);
});

test('import order is correct based on dependencies', function () {
    $component = Livewire::test(MigrationWizard::class)
        ->call('toggleEntity', 'companies')
        ->call('toggleEntity', 'people')
        ->call('toggleEntity', 'opportunities');

    $order = $component->instance()->getImportOrder();

    expect($order)->toBe(['companies', 'people', 'opportunities']);
});

test('cannot proceed to step 2 without selecting entities', function () {
    Livewire::test(MigrationWizard::class)
        ->call('nextStep')
        ->assertSet('currentStep', 1);
});

test('can proceed to step 2 after selecting entities', function () {
    Livewire::test(MigrationWizard::class)
        ->call('toggleEntity', 'companies')
        ->call('nextStep')
        ->assertSet('currentStep', 2)
        ->assertSet('currentEntity', 'companies');
});

test('can skip current entity', function () {
    Livewire::test(MigrationWizard::class)
        ->call('toggleEntity', 'companies')
        ->call('toggleEntity', 'people')
        ->call('nextStep')
        ->assertSet('currentEntity', 'companies')
        ->call('skipCurrentEntity')
        ->assertSet('currentEntity', 'people');
});

test('skipping sets skipped flag in results', function () {
    $component = Livewire::test(MigrationWizard::class)
        ->call('toggleEntity', 'companies')
        ->call('nextStep')
        ->call('skipCurrentEntity');

    $results = $component->get('importResults');

    expect($results['companies']['skipped'])->toBeTrue();
});

test('skipping all entities goes to completion step', function () {
    Livewire::test(MigrationWizard::class)
        ->call('toggleEntity', 'companies')
        ->call('nextStep')
        ->call('skipCurrentEntity')
        ->assertSet('currentStep', 3);
});

test('completion step shows imports queued message', function () {
    Livewire::test(MigrationWizard::class)
        ->call('toggleEntity', 'companies')
        ->call('nextStep')
        ->call('skipCurrentEntity')
        ->assertSee('Imports Queued');
});

test('can reset wizard after completion', function () {
    Livewire::test(MigrationWizard::class)
        ->call('toggleEntity', 'companies')
        ->call('nextStep')
        ->call('skipCurrentEntity')
        ->call('resetWizard')
        ->assertSet('currentStep', 1)
        ->assertSet('selectedEntities.companies', false)
        ->assertSet('currentEntity', null)
        ->assertSet('importResults', []);
});

test('total counts are calculated correctly', function () {
    $component = Livewire::test(MigrationWizard::class);

    // Set import results manually to test counting
    $component->set('importResults', [
        'companies' => ['imported' => 10, 'failed' => 2],
        'people' => ['imported' => 5, 'failed' => 1],
        'opportunities' => ['imported' => 0, 'failed' => 0, 'skipped' => true],
    ]);

    $totals = $component->instance()->getTotalCounts();

    expect($totals['imported'])->toBe(15)
        ->and($totals['failed'])->toBe(3)
        ->and($totals['skipped'])->toBe(1);
});

test('has selected entities returns false when none selected', function () {
    $component = Livewire::test(MigrationWizard::class);

    expect($component->instance()->hasSelectedEntities())->toBeFalse();
});

test('has selected entities returns true when entities selected', function () {
    $component = Livewire::test(MigrationWizard::class)
        ->call('toggleEntity', 'companies');

    expect($component->instance()->hasSelectedEntities())->toBeTrue();
});

test('get missing dependencies returns correct list', function () {
    $component = Livewire::test(MigrationWizard::class);

    $missing = $component->instance()->getMissingDependencies('opportunities');

    expect($missing)->toBe(['companies']);
});

test('get missing dependencies returns empty when all met', function () {
    $component = Livewire::test(MigrationWizard::class)
        ->call('toggleEntity', 'companies');

    $missing = $component->instance()->getMissingDependencies('opportunities');

    expect($missing)->toBeEmpty();
});

test('tasks entity has no dependencies', function () {
    $component = Livewire::test(MigrationWizard::class);
    $entities = $component->instance()->getEntities();

    expect($entities['tasks']['dependencies'])->toBeEmpty();
});

test('notes entity has no dependencies', function () {
    $component = Livewire::test(MigrationWizard::class);
    $entities = $component->instance()->getEntities();

    expect($entities['notes']['dependencies'])->toBeEmpty();
});

test('can select tasks without selecting other entities', function () {
    Livewire::test(MigrationWizard::class)
        ->call('toggleEntity', 'tasks')
        ->assertSet('selectedEntities.tasks', true);
});

test('can select notes without selecting other entities', function () {
    Livewire::test(MigrationWizard::class)
        ->call('toggleEntity', 'notes')
        ->assertSet('selectedEntities.notes', true);
});

test('can import only tasks and notes without core entities', function () {
    $component = Livewire::test(MigrationWizard::class)
        ->call('toggleEntity', 'tasks')
        ->call('toggleEntity', 'notes');

    $order = $component->instance()->getImportOrder();

    expect($order)->toBe(['tasks', 'notes']);
});

test('can cancel active migration and resets wizard', function () {
    $component = Livewire::test(MigrationWizard::class)
        ->call('toggleEntity', 'companies')
        ->call('nextStep');

    $component->call('cancelMigration');

    // Wizard should be reset
    expect($component->get('currentStep'))->toBe(1);
});

test('imports are processed sequentially via queue middleware', function () {
    // This test verifies that BaseImporter has the WithoutOverlapping middleware
    // which ensures imports for the same team run one at a time
    $import = \Filament\Actions\Imports\Models\Import::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'importer' => \App\Filament\Imports\CompanyImporter::class,
        'file_name' => 'test.csv',
        'file_path' => 'imports/test.csv',
        'total_rows' => 0,
        'processed_rows' => 0,
        'successful_rows' => 0,
    ]);

    // Instantiate the importer with required constructor arguments
    $importer = new \App\Filament\Imports\CompanyImporter(
        import: $import,
        columnMap: [],
        options: [],
    );

    $middleware = $importer->getJobMiddleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(\Illuminate\Queue\Middleware\WithoutOverlapping::class);
});
