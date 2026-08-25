<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Enums\Plan;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\SystemAdmin\Filament\Widgets\TopTeamsTableWidget;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(TopTeamsTableWidget::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel('sysadmin');
});

it('renders the widget', function (): void {
    livewire(TopTeamsTableWidget::class)
        ->assertSuccessful();
});

it('lists an active team with its plan badge and active member count', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;
    $team->forceFill(['plan' => Plan::Pro])->save();

    Company::withoutEvents(fn (): Company => Company::factory()
        ->for($team)
        ->create([
            'creator_id' => $owner->id,
            'creation_source' => CreationSource::WEB,
            'created_at' => now()->subDays(2),
        ]));

    livewire(TopTeamsTableWidget::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$team])
        ->assertSee('Pro')
        ->assertSee('1 / 1');
});
