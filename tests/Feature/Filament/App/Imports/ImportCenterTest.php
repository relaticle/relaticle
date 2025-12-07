<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\App\Imports;

use App\Filament\Pages\Import\ImportCompanies;
use App\Filament\Pages\Import\ImportNotes;
use App\Filament\Pages\Import\ImportOpportunities;
use App\Filament\Pages\Import\ImportPeople;
use App\Filament\Pages\Import\ImportTasks;
use App\Livewire\Import\ImportWizard;
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

test('import companies page renders successfully', function () {
    Livewire::test(ImportCompanies::class)
        ->assertSuccessful()
        ->assertSee('Import Companies');
});

test('import companies page renders import wizard', function () {
    Livewire::test(ImportCompanies::class)
        ->assertSeeLivewire(ImportWizard::class);
});

test('import people page renders successfully', function () {
    Livewire::test(ImportPeople::class)
        ->assertSuccessful()
        ->assertSee('Import People');
});

test('import opportunities page renders successfully', function () {
    Livewire::test(ImportOpportunities::class)
        ->assertSuccessful()
        ->assertSee('Import Opportunities');
});

test('import tasks page renders successfully', function () {
    Livewire::test(ImportTasks::class)
        ->assertSuccessful()
        ->assertSee('Import Tasks');
});

test('import notes page renders successfully', function () {
    Livewire::test(ImportNotes::class)
        ->assertSuccessful()
        ->assertSee('Import Notes');
});

test('import pages are not registered in navigation', function () {
    expect(ImportCompanies::shouldRegisterNavigation())->toBeFalse()
        ->and(ImportPeople::shouldRegisterNavigation())->toBeFalse()
        ->and(ImportOpportunities::shouldRegisterNavigation())->toBeFalse()
        ->and(ImportTasks::shouldRegisterNavigation())->toBeFalse()
        ->and(ImportNotes::shouldRegisterNavigation())->toBeFalse();
});

test('import pages have correct entity types', function () {
    expect(ImportCompanies::getEntityType())->toBe('companies')
        ->and(ImportPeople::getEntityType())->toBe('people')
        ->and(ImportOpportunities::getEntityType())->toBe('opportunities')
        ->and(ImportTasks::getEntityType())->toBe('tasks')
        ->and(ImportNotes::getEntityType())->toBe('notes');
});
