<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
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

it('treats the day before the window opens as outside the period, on the administrator calendar', function (): void {
    $this->travelTo(Date::parse('2026-08-27 10:31:00', 'UTC'));
    $this->actingAs(SystemAdministrator::factory()->create(['timezone' => 'Asia/Yerevan']), 'sysadmin');
    Filament::setCurrentPanel('sysadmin');

    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $company = fn (string $utc): Company => Company::withoutEvents(fn (): Company => Company::factory()
        ->for($team)
        ->create([
            'creator_id' => $owner->id,
            'creation_source' => CreationSource::WEB,
            'created_at' => Date::parse($utc, 'UTC'),
        ]));

    $company('2026-06-01 09:00:00');   // makes the team previously active
    // Jul 28 19:00 in Yerevan is the day before the window opens, so the team has
    // gone quiet. A rolling window opening at 10:31 UTC would have counted it.
    $company('2026-07-28 15:00:00');

    livewire(GoneQuietTeamsWidget::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$team]);
});
