<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\App\Imports;

use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Relaticle\ImportWizard\Filament\Pages\ImportCompanies;
use Relaticle\ImportWizard\Filament\Pages\ImportNotes;
use Relaticle\ImportWizard\Filament\Pages\ImportOpportunities;
use Relaticle\ImportWizard\Filament\Pages\ImportPeople;
use Relaticle\ImportWizard\Filament\Pages\ImportTasks;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->user->teams()->attach($this->team);

    $this->actingAs($this->user);
    Filament::setTenant($this->team);
});

it('renders import page for :dataset', function (string $pageClass, string $expectedTitle): void {
    Livewire::test($pageClass)
        ->assertSuccessful()
        ->assertSee($expectedTitle);
})->with([
    'companies' => [ImportCompanies::class, 'Import Companies'],
    'people' => [ImportPeople::class, 'Import People'],
    'opportunities' => [ImportOpportunities::class, 'Import Opportunities'],
    'tasks' => [ImportTasks::class, 'Import Tasks'],
    'notes' => [ImportNotes::class, 'Import Notes'],
]);

test('import pages are hidden from navigation and have correct entity types', function (): void {
    $pages = [
        ImportCompanies::class => 'companies',
        ImportPeople::class => 'people',
        ImportOpportunities::class => 'opportunities',
        ImportTasks::class => 'tasks',
        ImportNotes::class => 'notes',
    ];

    foreach ($pages as $pageClass => $expectedType) {
        expect($pageClass::shouldRegisterNavigation())->toBeFalse()
            ->and($pageClass::getEntityType())->toBe($expectedType);
    }
});
