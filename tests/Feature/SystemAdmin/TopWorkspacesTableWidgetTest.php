<?php

declare(strict_types=1);

use App\Enums\BillingStatus;
use App\Enums\Plan;
use App\Models\ActivityLog\Activity;
use App\Models\ActivityLog\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
use Relaticle\SystemAdmin\Filament\Widgets\TopWorkspacesTableWidget;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(BillingStatus::class, TopWorkspacesTableWidget::class);

function seedWorkspaceActivity(Workspace $workspace, User $causer, string $subjectId, ?DateTimeInterface $createdAt = null): void
{
    Activity::query()->withoutGlobalScope(WorkspaceScope::class)->create([
        'log_name' => 'crm',
        'description' => 'updated',
        'event' => 'updated',
        'subject_type' => 'company',
        'subject_id' => $subjectId,
        'causer_type' => 'user',
        'causer_id' => $causer->id,
        'workspace_id' => $workspace->id,
        'properties' => [],
        'created_at' => $createdAt ?? now(),
    ]);
}

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel('sysadmin');
});

it('renders the widget', function (): void {
    livewire(TopWorkspacesTableWidget::class)
        ->assertSuccessful();
});

it('lists an active workspace with its billing badge and active member count', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    seedWorkspaceActivity($workspace, $owner, 'subject-1', now()->subDay());
    seedWorkspaceActivity($workspace, $owner, 'subject-1', now()->subDays(2));

    livewire(TopWorkspacesTableWidget::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$workspace])
        // Pro with nothing bought and no trial running is a hand-assigned plan.
        ->assertSee(BillingStatus::Granted->getLabel())
        ->assertSeeHtml(BillingStatus::Granted->getDescription())
        ->assertSee('1 / 1');
});

it('separates a trialling workspace from a paying one', function (): void {
    $trialOwner = User::factory()->withWorkspace()->create();
    $trialWorkspace = $trialOwner->currentWorkspace;
    $trialWorkspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(5)])->save();
    seedWorkspaceActivity($trialWorkspace, $trialOwner, 'trial-subject-1');

    $payingOwner = User::factory()->withWorkspace()->create();
    $payingWorkspace = $payingOwner->currentWorkspace;
    $payingWorkspace->forceFill(['plan' => Plan::Pro])->save();
    $payingWorkspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_widget',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);
    seedWorkspaceActivity($payingWorkspace, $payingOwner, 'paying-subject-1');

    expect($trialWorkspace->fresh()?->billingStatus())->toBe(BillingStatus::Trialing)
        ->and($payingWorkspace->fresh()?->billingStatus())->toBe(BillingStatus::Subscribed);

    livewire(TopWorkspacesTableWidget::class)
        ->assertCanSeeTableRecords([$trialWorkspace, $payingWorkspace])
        ->assertSee(BillingStatus::Trialing->getLabel())
        ->assertSee(BillingStatus::Subscribed->getLabel());
});

it('ranks by distinct records touched, not raw event volume', function (): void {
    $ownerA = User::factory()->withWorkspace()->create();
    $workspaceA = $ownerA->currentWorkspace;
    seedWorkspaceActivity($workspaceA, $ownerA, 'a-subject-1');
    seedWorkspaceActivity($workspaceA, $ownerA, 'a-subject-2');

    $ownerB = User::factory()->withWorkspace()->create();
    $workspaceB = $ownerB->currentWorkspace;
    foreach (range(1, 5) as $i) {
        seedWorkspaceActivity($workspaceB, $ownerB, 'b-subject-1');
    }

    livewire(TopWorkspacesTableWidget::class)
        ->assertCanSeeTableRecords([$workspaceA, $workspaceB], inOrder: true);
});

it('excludes a workspace whose activity all predates the period', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    seedWorkspaceActivity($workspace, $owner, 'old-subject', now()->subDays(45));

    livewire(TopWorkspacesTableWidget::class)
        ->assertOk()
        ->assertCanNotSeeTableRecords([$workspace]);
});

function actAsTopWorkspacesAdminInZone(string $timezone): void
{
    test()->actingAs(SystemAdministrator::factory()->create(['timezone' => $timezone]), 'sysadmin');
    Filament::setCurrentPanel('sysadmin');
}

it('counts active days on the administrator calendar, not the server one', function (): void {
    $this->travelTo(Date::parse('2026-08-27 10:31:00', 'UTC'));
    actAsTopWorkspacesAdminInZone('Asia/Yerevan');

    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    // 01:00 and 09:00 on Aug 27 in Yerevan: one active day there, two on the server.
    seedWorkspaceActivity($workspace, $owner, 'subject-1', Date::parse('2026-08-26 21:00:00', 'UTC'));
    seedWorkspaceActivity($workspace, $owner, 'subject-2', Date::parse('2026-08-27 05:00:00', 'UTC'));

    $records = livewire(TopWorkspacesTableWidget::class)->assertOk()->instance()->getTableRecords();

    expect((int) $records->first()->active_days)->toBe(1);
});

it('opens its window at midnight on the administrator calendar', function (): void {
    $this->travelTo(Date::parse('2026-08-27 10:31:00', 'UTC'));
    actAsTopWorkspacesAdminInZone('Asia/Yerevan');

    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    /**
     * 19:00 on Jul 28 in Yerevan is the day before the 30 day window opens, but
     * it is after the 10:31 UTC mark a rolling window would have used.
     */
    seedWorkspaceActivity($workspace, $owner, 'stale-subject', Date::parse('2026-07-28 15:00:00', 'UTC'));

    livewire(TopWorkspacesTableWidget::class)
        ->assertOk()
        ->assertCanNotSeeTableRecords([$workspace]);
});

it('stamps the table with the read time in the administrator zone', function (): void {
    $this->travelTo(Date::parse('2026-08-27 10:31:00', 'UTC'));
    actAsTopWorkspacesAdminInZone('Asia/Yerevan');

    livewire(TopWorkspacesTableWidget::class)
        ->assertOk()
        ->assertSee('Updated 14:31 +04');
});
