<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\SystemAdmin\Filament\Pages\Overview;
use Relaticle\SystemAdmin\Filament\Resources\UserResource\Pages\ListUsers;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages\ListWorkspaces;
use Relaticle\SystemAdmin\Filament\Widgets\Overview\CohortTable;
use Relaticle\SystemAdmin\Filament\Widgets\Overview\ValueStats;
use Relaticle\SystemAdmin\Metrics\Cohorts;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Tests\Helpers\OverviewData;

mutates(ValueStats::class, CohortTable::class, Cohorts::class);

function iconMarkup(string $name): string
{
    preg_match('/ d="([^"]+)"/', svg($name)->contents(), $path);

    return $path[1];
}

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00'));
});

it('renders on an empty database', function (): void {
    livewire(ValueStats::class)
        ->assertOk()
        ->assertSee('Is anyone getting value?')
        ->assertSee('Real signups')
        ->assertSee('Reached first value')
        ->assertSee('Formed a habit')
        ->assertSee('No real signups that week')
        ->assertDontSee('0 of 0')
        ->assertSeeHtml(iconMarkup('heroicon-m-minus'));

    livewire(CohortTable::class)->assertOk();
});

it('counts real signups and first value for the latest week with 7 days of follow-up', function (): void {
    $fast = OverviewData::owner(CarbonImmutable::parse('2026-09-16 10:00:00'));
    OverviewData::ownRecord(OverviewData::workspaceOf($fast), $fast, CarbonImmutable::parse('2026-09-18 10:00:00'));
    $slow = OverviewData::owner(CarbonImmutable::parse('2026-09-17 10:00:00'));
    $nextWeek = OverviewData::owner(CarbonImmutable::parse('2026-09-24 10:00:00'));

    livewire(ValueStats::class)
        ->assertSee('Week of Sep 14')
        ->assertSee('50%')
        ->assertSee('filters%5Bgenuine_signup%5D%5BisActive%5D=1', escape: false);

    livewire(ListUsers::class)
        ->filterTable('genuine_signup')
        ->filterTable('signed_up', ['from' => '2026-09-14', 'until' => '2026-09-20'])
        ->assertCanSeeTableRecords([$fast, $slow])
        ->assertCanNotSeeTableRecords([$nextWeek])
        ->filterTable('reached_first_value')
        ->assertCanSeeTableRecords([$fast])
        ->assertCanNotSeeTableRecords([$slow, $nextWeek]);
});

it('points the signup arrow by the change against the week before', function (): void {
    OverviewData::owner(CarbonImmutable::parse('2026-09-08 10:00:00'));
    OverviewData::owner(CarbonImmutable::parse('2026-09-09 10:00:00'));
    OverviewData::owner(CarbonImmutable::parse('2026-09-15 10:00:00'));

    livewire(ValueStats::class)
        ->assertSee('Week of Sep 14, -1 vs the week before')
        ->assertSeeHtml(iconMarkup('heroicon-m-arrow-trending-down'));

    Cache::flush();
    OverviewData::owner(CarbonImmutable::parse('2026-09-16 10:00:00'));

    livewire(ValueStats::class)
        ->assertSee('Week of Sep 14, +0 vs the week before')
        ->assertSeeHtml(iconMarkup('heroicon-m-minus'));

    Cache::flush();
    OverviewData::owner(CarbonImmutable::parse('2026-09-17 10:00:00'));

    livewire(ValueStats::class)
        ->assertSee('Week of Sep 14, +1 vs the week before')
        ->assertSeeHtml(iconMarkup('heroicon-m-arrow-trending-up'));
});

it('measures a week only once every timezone has seen its last signup for 7 days', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00'));

    livewire(ValueStats::class)->assertSee('Week of Sep 14')->assertDontSee('Week of Sep 21');
});

it('keeps each administrator calendar to its own cached signup counts', function (): void {
    OverviewData::owner(CarbonImmutable::parse('2026-09-20 20:00:00'));
    $auckland = SystemAdministrator::factory()->create(['timezone' => 'Pacific/Auckland']);

    livewire(ValueStats::class)->assertSee('Week of Sep 14, +1 vs the week before');

    $this->actingAs($auckland, 'sysadmin');

    livewire(ValueStats::class)->assertSee('Week of Sep 14, +0 vs the week before');
});

it('moves to the new week and its counts as soon as the week turns', function (): void {
    OverviewData::owner(CarbonImmutable::parse('2026-09-15 10:00:00'));

    foreach (['2026-09-22 10:00:00', '2026-09-23 10:00:00', '2026-09-24 10:00:00'] as $at) {
        OverviewData::owner(CarbonImmutable::parse($at));
    }

    $this->travelTo(CarbonImmutable::parse('2026-10-05 23:58:00'));
    livewire(ValueStats::class)->assertSee('Week of Sep 14, +1 vs the week before');

    $this->travelTo(CarbonImmutable::parse('2026-10-06 00:02:00'));
    livewire(ValueStats::class)->assertSee('Week of Sep 21, +2 vs the week before');
});

it('moves the habit and cohort numbers to the new week as soon as the week turns', function (): void {
    $owner = OverviewData::owner(CarbonImmutable::parse('2026-08-20 10:00:00'));

    foreach (['2026-09-01', '2026-09-08', '2026-09-15'] as $day) {
        OverviewData::ownRecord(OverviewData::workspaceOf($owner), $owner, CarbonImmutable::parse("{$day} 10:00:00"));
    }

    $this->travelTo(CarbonImmutable::parse('2026-10-04 23:58:00'));
    livewire(ValueStats::class)->assertSee('+0 vs a week earlier');
    livewire(CohortTable::class)->assertDontSee('Sep 28');

    $this->travelTo(CarbonImmutable::parse('2026-10-05 00:02:00'));
    livewire(ValueStats::class)->assertSee('-1 vs a week earlier');
    livewire(CohortTable::class)->assertSee('Sep 28');
});

it('counts workspaces that formed a habit as the workspace list shows them', function (): void {
    $steady = OverviewData::owner(CarbonImmutable::parse('2026-08-20 10:00:00'));
    $steadyWorkspace = OverviewData::workspaceOf($steady);

    foreach (['2026-09-08', '2026-09-15', '2026-09-22'] as $day) {
        OverviewData::ownRecord($steadyWorkspace, $steady, CarbonImmutable::parse("{$day} 10:00:00"));
    }

    $sporadic = OverviewData::owner(CarbonImmutable::parse('2026-08-20 10:00:00'));
    OverviewData::ownRecord(OverviewData::workspaceOf($sporadic), $sporadic, CarbonImmutable::parse('2026-09-22 10:00:00'));

    livewire(ValueStats::class)->assertSeeInOrder(['Formed a habit', '1', '+1 vs a week earlier']);

    livewire(ListWorkspaces::class)
        ->filterTable('formed_habit')
        ->assertCanSeeTableRecords([$steadyWorkspace])
        ->assertCanNotSeeTableRecords([OverviewData::workspaceOf($sporadic)]);
});

it('builds six weekly cohorts with a dot for weeks that have not happened', function (): void {
    $owner = OverviewData::owner(CarbonImmutable::parse('2026-09-15 10:00:00'));
    OverviewData::ownRecord(OverviewData::workspaceOf($owner), $owner, CarbonImmutable::parse('2026-09-23 10:00:00'));

    $rows = Cohorts::rows();
    $week = collect($rows)->first(fn (array $row): bool => $row['week']->toDateString() === '2026-09-14');

    expect($rows)->toHaveCount(6)
        ->and($week['size'])->toBe(1)
        ->and($week['shares'])->toBe([0, 100, null, null]);

    livewire(CohortTable::class)->assertSee('Sep 14');
});

it('shows changed numbers after Refresh redraws the page', function (): void {
    livewire(ValueStats::class)->assertSee('No real signups that week');

    OverviewData::owner(CarbonImmutable::parse('2026-09-16 10:00:00'));

    livewire(ValueStats::class)->assertSee('No real signups that week');

    livewire(Overview::class)
        ->callAction('refresh')
        ->assertNotified('Numbers refreshed')
        ->assertRedirect(Overview::getUrl());

    livewire(ValueStats::class)->assertDontSee('No real signups that week');
});

it('explains every number and the cohort table in a tooltip', function (): void {
    livewire(ValueStats::class)
        ->assertSee('Only verified people who signed up on their own count')
        ->assertSee('within 7 days. Sample data does not count')
        ->assertSee('active in at least 3 of the last 4 full weeks');

    livewire(CohortTable::class)->assertSee('Each row is one week of real signups');
});

it('counts customer workspaces with a connected mailbox exactly as the workspace list shows them', function (): void {
    $connected = OverviewData::workspaceOf(OverviewData::owner());
    ConnectedAccount::factory()->count(2)->create(['workspace_id' => $connected]);
    $needsSignIn = OverviewData::workspaceOf(OverviewData::owner());
    ConnectedAccount::factory()->error()->create(['workspace_id' => $needsSignIn]);
    $disconnected = OverviewData::workspaceOf(OverviewData::owner());
    ConnectedAccount::factory()->disconnected()->create(['workspace_id' => $disconnected]);
    $without = OverviewData::workspaceOf(OverviewData::owner());
    $internal = OverviewData::workspaceOf(OverviewData::internalOwner());
    ConnectedAccount::factory()->create(['workspace_id' => $internal]);

    livewire(ValueStats::class)
        ->assertSee('Connected a mailbox')
        ->assertSee('50% of 4 customer workspaces')
        ->assertSee('filters%5Bconnected_mailbox%5D%5BisActive%5D=1', escape: false);

    livewire(ListWorkspaces::class)
        ->filterTable('internal', false)
        ->filterTable('connected_mailbox')
        ->assertCanSeeTableRecords([$connected, $needsSignIn])
        ->assertCanNotSeeTableRecords([$disconnected, $without, $internal]);

    Livewire::withQueryParams(['filters' => ['internal' => ['value' => '0'], 'connected_mailbox' => ['isActive' => '1']]])
        ->test(ListWorkspaces::class)
        ->assertCanSeeTableRecords([$connected, $needsSignIn])
        ->assertCanNotSeeTableRecords([$disconnected, $without, $internal]);
});
