<?php

declare(strict_types=1);

use App\Enums\BillingStatus;
use App\Enums\Plan;
use App\Models\ActivityLog\Activity;
use App\Models\ActivityLog\Scopes\TeamScope;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
use Relaticle\SystemAdmin\Filament\Widgets\TopTeamsTableWidget;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(BillingStatus::class, TopTeamsTableWidget::class);

function seedTeamActivity(Team $team, User $causer, string $subjectId, ?DateTimeInterface $createdAt = null): void
{
    Activity::query()->withoutGlobalScope(TeamScope::class)->create([
        'log_name' => 'crm',
        'description' => 'updated',
        'event' => 'updated',
        'subject_type' => 'company',
        'subject_id' => $subjectId,
        'causer_type' => 'user',
        'causer_id' => $causer->id,
        'team_id' => $team->id,
        'properties' => [],
        'created_at' => $createdAt ?? now(),
    ]);
}

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel('sysadmin');
});

it('renders the widget', function (): void {
    livewire(TopTeamsTableWidget::class)
        ->assertSuccessful();
});

it('lists an active team with its billing badge and active member count', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;
    $team->forceFill(['plan' => Plan::Pro])->save();

    seedTeamActivity($team, $owner, 'subject-1', now()->subDay());
    seedTeamActivity($team, $owner, 'subject-1', now()->subDays(2));

    livewire(TopTeamsTableWidget::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$team])
        // Pro with nothing bought and no trial running is a hand-assigned plan.
        ->assertSee(BillingStatus::Granted->getLabel())
        ->assertSeeHtml(BillingStatus::Granted->getDescription())
        ->assertSee('1 / 1');
});

it('separates a trialling workspace from a paying one', function (): void {
    $trialOwner = User::factory()->withTeam()->create();
    $trialTeam = $trialOwner->currentTeam;
    $trialTeam->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(5)])->save();
    seedTeamActivity($trialTeam, $trialOwner, 'trial-subject-1');

    $payingOwner = User::factory()->withTeam()->create();
    $payingTeam = $payingOwner->currentTeam;
    $payingTeam->forceFill(['plan' => Plan::Pro])->save();
    $payingTeam->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_widget',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);
    seedTeamActivity($payingTeam, $payingOwner, 'paying-subject-1');

    expect($trialTeam->fresh()?->billingStatus())->toBe(BillingStatus::Trialing)
        ->and($payingTeam->fresh()?->billingStatus())->toBe(BillingStatus::Subscribed);

    livewire(TopTeamsTableWidget::class)
        ->assertCanSeeTableRecords([$trialTeam, $payingTeam])
        ->assertSee(BillingStatus::Trialing->getLabel())
        ->assertSee(BillingStatus::Subscribed->getLabel());
});

it('ranks by distinct records touched, not raw event volume', function (): void {
    $ownerA = User::factory()->withTeam()->create();
    $teamA = $ownerA->currentTeam;
    seedTeamActivity($teamA, $ownerA, 'a-subject-1');
    seedTeamActivity($teamA, $ownerA, 'a-subject-2');

    $ownerB = User::factory()->withTeam()->create();
    $teamB = $ownerB->currentTeam;
    foreach (range(1, 5) as $i) {
        seedTeamActivity($teamB, $ownerB, 'b-subject-1');
    }

    livewire(TopTeamsTableWidget::class)
        ->assertCanSeeTableRecords([$teamA, $teamB], inOrder: true);
});

it('excludes a team whose activity all predates the period', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    seedTeamActivity($team, $owner, 'old-subject', now()->subDays(45));

    livewire(TopTeamsTableWidget::class)
        ->assertOk()
        ->assertCanNotSeeTableRecords([$team]);
});

function actAsTopTeamsAdminInZone(string $timezone): void
{
    test()->actingAs(SystemAdministrator::factory()->create(['timezone' => $timezone]), 'sysadmin');
    Filament::setCurrentPanel('sysadmin');
}

it('counts active days on the administrator calendar, not the server one', function (): void {
    $this->travelTo(Date::parse('2026-08-27 10:31:00', 'UTC'));
    actAsTopTeamsAdminInZone('Asia/Yerevan');

    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    // 01:00 and 09:00 on Aug 27 in Yerevan: one active day there, two on the server.
    seedTeamActivity($team, $owner, 'subject-1', Date::parse('2026-08-26 21:00:00', 'UTC'));
    seedTeamActivity($team, $owner, 'subject-2', Date::parse('2026-08-27 05:00:00', 'UTC'));

    $records = livewire(TopTeamsTableWidget::class)->assertOk()->instance()->getTableRecords();

    expect((int) $records->first()->active_days)->toBe(1);
});

it('opens its window at midnight on the administrator calendar', function (): void {
    $this->travelTo(Date::parse('2026-08-27 10:31:00', 'UTC'));
    actAsTopTeamsAdminInZone('Asia/Yerevan');

    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    /**
     * 19:00 on Jul 28 in Yerevan is the day before the 30 day window opens, but
     * it is after the 10:31 UTC mark a rolling window would have used.
     */
    seedTeamActivity($team, $owner, 'stale-subject', Date::parse('2026-07-28 15:00:00', 'UTC'));

    livewire(TopTeamsTableWidget::class)
        ->assertOk()
        ->assertCanNotSeeTableRecords([$team]);
});

it('stamps the table with the read time in the administrator zone', function (): void {
    $this->travelTo(Date::parse('2026-08-27 10:31:00', 'UTC'));
    actAsTopTeamsAdminInZone('Asia/Yerevan');

    livewire(TopTeamsTableWidget::class)
        ->assertOk()
        ->assertSee('Updated 14:31 +04');
});
