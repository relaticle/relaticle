<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\SystemAdmin\Filament\Widgets\GoneQuietTeamsWidget;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(GoneQuietTeamsWidget::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel('sysadmin');
});

it('renders the widget', function (): void {
    livewire(GoneQuietTeamsWidget::class)
        ->assertSuccessful();
});

it('lists a team that was active before the period but silent inside it', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    Company::withoutEvents(fn (): Company => Company::factory()
        ->for($team)
        ->create([
            'creator_id' => $owner->id,
            'creation_source' => CreationSource::WEB,
            'created_at' => now()->subDays(45),
        ]));

    livewire(GoneQuietTeamsWidget::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$team]);
});

it('excludes a team with activity inside the period', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    Company::withoutEvents(fn (): Company => Company::factory()
        ->for($team)
        ->create([
            'creator_id' => $owner->id,
            'creation_source' => CreationSource::WEB,
            'created_at' => now()->subDays(45),
        ]));

    Company::withoutEvents(fn (): Company => Company::factory()
        ->for($team)
        ->create([
            'creator_id' => $owner->id,
            'creation_source' => CreationSource::WEB,
            'created_at' => now()->subDays(2),
        ]));

    livewire(GoneQuietTeamsWidget::class)
        ->assertOk()
        ->assertCanNotSeeTableRecords([$team]);
});

it('excludes a team that was never active', function (): void {
    $owner = User::factory()->withTeam()->create();

    livewire(GoneQuietTeamsWidget::class)
        ->assertOk()
        ->assertCanNotSeeTableRecords([$owner->currentTeam]);
});
