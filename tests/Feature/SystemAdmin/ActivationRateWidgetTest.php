<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\Note;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
use Relaticle\SystemAdmin\Filament\Widgets\ActivationRateWidget;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(ActivationRateWidget::class);

beforeEach(function () {
    $this->admin = SystemAdministrator::factory()->create();
    $this->actingAs($this->admin, 'sysadmin');
    Filament::setCurrentPanel('sysadmin');

    $this->teamOwner = User::factory()->withTeam()->create();
    $this->team = $this->teamOwner->currentTeam;
});

it('can render the activation rate widget', function () {
    livewire(ActivationRateWidget::class)
        ->assertOk();
});

it('counts activated users who created records manually', function () {
    $users = User::factory(3)->withTeam()->create([
        'created_at' => now()->subDays(5),
    ]);

    Company::withoutEvents(fn () => Company::factory()
        ->for($this->team)
        ->create([
            'creator_id' => $users[0]->id,
            'creation_source' => CreationSource::WEB,
            'created_at' => now()->subDays(4),
        ]));

    Note::withoutEvents(fn () => Note::factory()
        ->for($this->team)
        ->create([
            'creator_id' => $users[1]->id,
            'created_at' => now()->subDays(3),
        ]));

    livewire(ActivationRateWidget::class)
        ->assertSee('Activated Users')
        ->assertSee('2');
});

it('excludes system-created records from activation count', function () {
    $user = User::factory()->withTeam()->create([
        'created_at' => now()->subDays(5),
    ]);

    Company::withoutEvents(fn () => Company::factory()
        ->for($this->team)
        ->create([
            'creator_id' => $user->id,
            'creation_source' => CreationSource::SYSTEM,
            'created_at' => now()->subDays(4),
        ]));

    livewire(ActivationRateWidget::class)
        ->assertSee('Activated Users')
        ->assertSee('0');
});

function actAsActivationAdminInZone(string $timezone): void
{
    test()->actingAs(SystemAdministrator::factory()->create(['timezone' => $timezone]), 'sysadmin');
    Filament::setCurrentPanel('sysadmin');
}

it('opens the window at midnight on the administrator calendar, not a rolling server window', function (): void {
    $this->travelTo(Date::parse('2026-08-27 10:31:00', 'UTC'));
    actAsActivationAdminInZone('Asia/Yerevan');

    // beforeEach seeds a team owner at the frozen instant, which would otherwise
    // land inside the window under test.
    User::query()->update(['created_at' => Date::parse('2026-01-01 00:00:00', 'UTC')]);

    /**
     * The 30 day window opens at midnight on Jul 29 in Yerevan, which is
     * 2026-07-28 20:00 UTC. A rolling now() minus 30 days window would have
     * opened at 10:31 UTC that day and counted both of these.
     */
    User::factory()->create(['created_at' => Date::parse('2026-07-28 15:00:00', 'UTC')]);
    User::factory()->create(['created_at' => Date::parse('2026-07-28 20:30:00', 'UTC')]);

    $stats = invade(livewire(ActivationRateWidget::class)->assertOk()->instance())->getStats();

    expect($stats[0]->getValue())->toBe('1');
});

it('spreads the sparkline across the whole window without doubling the last point', function (): void {
    $this->travelTo(Date::parse('2026-08-27 10:31:00', 'UTC'));
    actAsActivationAdminInZone('Asia/Yerevan');

    User::query()->update(['created_at' => Date::parse('2026-01-01 00:00:00', 'UTC')]);

    /**
     * One signup on each of the seven viewer calendar days in the window, all at
     * midday Yerevan so none sits near a bucket edge. The window runs from local
     * midnight to now, so it is six whole days plus a part of today: deriving the
     * segment from truncated whole days leaves today outside the buckets, and
     * fillBuckets() folds anything past the end into the final one.
     */
    foreach (range(21, 27) as $day) {
        User::factory()->create(['created_at' => Date::parse("2026-08-{$day} 08:00:00", 'UTC')]);
    }

    $stats = invade(livewire(ActivationRateWidget::class, ['pageFilters' => ['period' => '7']])->assertOk()->instance())->getStats();

    expect($stats[0]->getChart())->toBe([1, 1, 1, 1, 1, 1, 1]);
});
