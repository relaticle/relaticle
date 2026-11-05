<?php

declare(strict_types=1);

use App\Contracts\Entitlements;
use App\Enums\BillingStatus;
use App\Enums\Plan;
use App\Models\ActivityLog\Activity;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Cloud\Admin\Actions\EndWorkspaceTrial;
use Relaticle\Cloud\Admin\Actions\ExtendWorkspaceTrial;
use Relaticle\Cloud\Admin\Actions\RecordTrialChange;
use Relaticle\Cloud\Admin\Filament\Support\EndTrial;
use Relaticle\Cloud\Admin\Filament\Support\ExtendTrial;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages\ListWorkspaces;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages\ViewWorkspace;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Helpers\OverviewData;

mutates(ExtendWorkspaceTrial::class, EndWorkspaceTrial::class, RecordTrialChange::class, ExtendTrial::class, EndTrial::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
});

it('ends a trial now so the workspace pauses on the pay screen', function (): void {
    withHostedBilling();
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));

    livewire(ListWorkspaces::class)
        ->callAction(TestAction::make('endTrial')->table($workspace));

    $workspace->refresh();

    expect($workspace->billingStatus())->toBe(BillingStatus::TrialEnded)
        ->and(resolve(Entitlements::class)->allowsAccess($workspace))->toBeFalse();
});

it('ends several trials at once and skips workspaces that are not trialing', function (): void {
    $trialA = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    $trialB = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    $free = OverviewData::workspaceOf(OverviewData::owner());

    livewire(ListWorkspaces::class)
        ->selectTableRecords([$trialA, $trialB, $free])
        ->callAction(TestAction::make('endTrials')->table()->bulk())
        ->assertNotified('Ended 2 of 3 trials');

    expect($trialA->refresh()->billingStatus())->toBe(BillingStatus::TrialEnded)
        ->and($trialB->refresh()->billingStatus())->toBe(BillingStatus::TrialEnded)
        ->and($free->refresh()->trial_ends_at)->toBeNull();
});

it('reports success when every selected workspace is trialing', function (): void {
    $trialA = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    $trialB = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));

    livewire(ListWorkspaces::class)
        ->selectTableRecords([$trialA, $trialB])
        ->callAction(TestAction::make('endTrials')->table()->bulk())
        ->assertNotified('Trials ended');

    expect($trialA->refresh()->billingStatus())->toBe(BillingStatus::TrialEnded)
        ->and($trialB->refresh()->billingStatus())->toBe(BillingStatus::TrialEnded);
});

it('hides end trial on a workspace that is not trialing', function (): void {
    $free = OverviewData::workspaceOf(OverviewData::owner());

    livewire(ListWorkspaces::class)
        ->assertActionHidden(TestAction::make('endTrial')->table($free));
});

it('hides end trial from an administrator without customer access', function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::Administrator]), 'sysadmin');
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));

    livewire(ListWorkspaces::class)
        ->assertActionHidden(TestAction::make('endTrial')->table($workspace));
});

it('hides the end trials bulk action from an administrator without customer access', function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::Administrator]), 'sysadmin');
    OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));

    livewire(ListWorkspaces::class)
        ->assertActionHidden(TestAction::make('endTrials')->table()->bulk());
});

it('warns instead of reporting success when no selected workspace is trialing', function (): void {
    $free = OverviewData::workspaceOf(OverviewData::owner());

    livewire(ListWorkspaces::class)
        ->selectTableRecords([$free])
        ->callAction(TestAction::make('endTrials')->table()->bulk())
        ->assertNotified('No trialing workspaces selected');
});

it('offers end trial on the workspace page', function (): void {
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));

    livewire(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->callAction('endTrial');

    expect($workspace->refresh()->billingStatus())->toBe(BillingStatus::TrialEnded);
});

it('extends a running trial and leaves its credit balance alone', function (): void {
    withHostedBilling();
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00'));
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    $balance = AiCreditBalance::query()->updateOrCreate(
        ['workspace_id' => $workspace->getKey()],
        ['credits_remaining' => 1_500, 'credits_used' => 500, 'purchased_credits' => 0, 'period_starts_at' => now(), 'period_ends_at' => $workspace->trial_ends_at],
    );

    livewire(ListWorkspaces::class)
        ->callAction(TestAction::make('extendTrial')->table($workspace), ['trial_ends_at' => '2026-11-10 12:00:00'])
        ->assertHasNoActionErrors()
        ->assertNotified('Trial extended');

    $this->travelTo(CarbonImmutable::parse('2026-10-28 12:00:00'));
    $this->artisan('billing:process-trials')->assertSuccessful();

    expect($workspace->refresh()->trial_ends_at?->toDateTimeString())->toBe('2026-11-10 12:00:00')
        ->and($workspace->plan)->toBe(Plan::Pro)
        ->and($workspace->billingStatus())->toBe(BillingStatus::Trialing)
        ->and($balance->refresh()->credits_used)->toBe(500)
        ->and($balance->period_ends_at->toDateTimeString())->toBe('2026-10-27 12:00:00');
});

it('restarts a trial the pause step already downgraded and grants the pro allowance', function (): void {
    withHostedBilling();
    $admin = SystemAdministrator::factory()->create();
    $this->actingAs($admin, 'sysadmin');
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));

    $this->travelTo(now()->addDays(15));
    $this->artisan('billing:process-trials')->assertSuccessful();

    expect($workspace->refresh()->plan)->toBe(Plan::Free)
        ->and(resolve(Entitlements::class)->allowsAccess($workspace))->toBeFalse();

    $endsAt = now()->addDays(7)->startOfMinute();

    livewire(ListWorkspaces::class)
        ->callAction(TestAction::make('extendTrial')->table($workspace), ['trial_ends_at' => $endsAt->toDateTimeString()])
        ->assertHasNoActionErrors()
        ->assertNotified('Trial restarted');

    $workspace->refresh();
    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();
    $transaction = AiCreditTransaction::query()
        ->where('workspace_id', $workspace->getKey())
        ->where('type', AiCreditType::Adjustment)
        ->latest('id')
        ->firstOrFail();

    expect($workspace->plan)->toBe(Plan::Pro)
        ->and($workspace->trial_ends_at?->equalTo($endsAt))->toBeTrue()
        ->and($workspace->billingStatus())->toBe(BillingStatus::Trialing)
        ->and(resolve(Entitlements::class)->allowsAccess($workspace))->toBeTrue()
        ->and($balance->credits_remaining)->toBe(Plan::Pro->credits())
        ->and($transaction->metadata['sysadmin_id'])->toBe((string) $admin->getKey());
});

it('restarts a trial an administrator ended before the pause step ran', function (): void {
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    $workspace->forceFill(['trial_ends_at' => now()->subHour()])->save();

    livewire(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->assertActionHidden('endTrial')
        ->callAction('extendTrial', ['trial_ends_at' => now()->addDays(14)->toDateTimeString()])
        ->assertHasNoActionErrors()
        ->assertActionVisible('endTrial');

    expect($workspace->refresh()->billingStatus())->toBe(BillingStatus::Trialing);
});

it('reads the picked trial end in the administrator timezone', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00'));
    $this->actingAs(SystemAdministrator::factory()->create(['timezone' => 'Asia/Tokyo']), 'sysadmin');
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));

    livewire(ListWorkspaces::class)
        ->callAction(TestAction::make('extendTrial')->table($workspace), ['trial_ends_at' => '2026-11-10 08:30:00'])
        ->assertHasNoActionErrors();

    expect($workspace->refresh()->trial_ends_at?->toDateTimeString())->toBe('2026-11-09 23:30:00');
});

it('refuses a trial end date in the past', function (): void {
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    $endsAt = $workspace->trial_ends_at;

    livewire(ListWorkspaces::class)
        ->callAction(TestAction::make('extendTrial')->table($workspace), ['trial_ends_at' => now()->subDay()->toDateTimeString()])
        ->assertHasActionErrors(['trial_ends_at']);

    expect($workspace->refresh()->trial_ends_at?->equalTo($endsAt))->toBeTrue();
});

it('refuses a past trial end date when the action is called directly', function (): void {
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    $endsAt = $workspace->trial_ends_at;

    expect(fn () => resolve(ExtendWorkspaceTrial::class)->execute(SystemAdministrator::factory()->create(), $workspace, now()->subDay()))
        ->toThrow(ValidationException::class);

    expect($workspace->refresh()->trial_ends_at?->equalTo($endsAt))->toBeTrue();
});

it('hides extend trial on a workspace that never trialed or holds a granted plan', function (): void {
    $free = OverviewData::workspaceOf(OverviewData::owner());
    $granted = OverviewData::workspaceOf(OverviewData::owner());
    $granted->forceFill(['plan' => Plan::Pro])->save();

    livewire(ListWorkspaces::class)
        ->assertActionHidden(TestAction::make('extendTrial')->table($free))
        ->assertActionHidden(TestAction::make('extendTrial')->table($granted));
});

it('hides extend trial from an administrator without customer access', function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::Administrator]), 'sysadmin');
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));

    livewire(ListWorkspaces::class)
        ->assertActionHidden(TestAction::make('extendTrial')->table($workspace));
});

it('forbids an administrator without customer access from extending a trial directly', function (): void {
    $administrator = SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::Administrator]);
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    $endsAt = $workspace->trial_ends_at;

    expect(fn () => resolve(ExtendWorkspaceTrial::class)->execute($administrator, $workspace, now()->addMonth()))
        ->toThrow(HttpException::class);

    expect($workspace->refresh()->trial_ends_at?->equalTo($endsAt))->toBeTrue();
});

it('refuses a trial end more than sixty days out', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 21:58:00'));
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    $endsAt = $workspace->trial_ends_at;

    livewire(ListWorkspaces::class)
        ->callAction(TestAction::make('extendTrial')->table($workspace), ['trial_ends_at' => '2027-06-01 18:36:00'])
        ->assertHasActionErrors(['trial_ends_at']);

    expect($workspace->refresh()->trial_ends_at?->equalTo($endsAt))->toBeTrue()
        ->and(Activity::query()->withoutGlobalScopes()->forSubject($workspace)->exists())->toBeFalse();
});

it('refuses a trial end more than sixty days out when the action is called directly', function (): void {
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    $endsAt = $workspace->trial_ends_at;

    expect(fn () => resolve(ExtendWorkspaceTrial::class)->execute(SystemAdministrator::factory()->create(), $workspace, now()->addDays(ExtendWorkspaceTrial::MAX_DAYS_AHEAD)->addMinute()))
        ->toThrow(ValidationException::class);

    expect($workspace->refresh()->trial_ends_at?->equalTo($endsAt))->toBeTrue();
});

it('accepts a trial end exactly sixty days out', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00'));
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));

    livewire(ListWorkspaces::class)
        ->callAction(TestAction::make('extendTrial')->table($workspace), ['trial_ends_at' => '2026-12-12 12:00:00'])
        ->assertHasNoActionErrors();

    expect($workspace->refresh()->trial_ends_at?->toDateTimeString())->toBe('2026-12-12 12:00:00');
});

it('offers the sixty day limit as the default when two more weeks would pass it', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00'));
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    $workspace->forceFill(['trial_ends_at' => now()->addDays(55)])->save();

    livewire(ListWorkspaces::class)
        ->mountAction(TestAction::make('extendTrial')->table($workspace))
        ->assertSchemaStateSet(['trial_ends_at' => '2026-12-12 12:00'], 'mountedActionSchema0');
});

it('records who extended a trial and the dates it moved between', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00'));
    $admin = SystemAdministrator::factory()->create();
    $this->actingAs($admin, 'sysadmin');
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    $previousEnd = $workspace->trial_ends_at?->toDateTimeString();

    livewire(ListWorkspaces::class)
        ->callAction(TestAction::make('extendTrial')->table($workspace), ['trial_ends_at' => '2026-11-10 12:00:00'])
        ->assertHasNoActionErrors();

    $activity = Activity::query()->withoutGlobalScopes()->forSubject($workspace)->sole();

    expect($activity->event)->toBe('trial_extended')
        ->and($activity->causer?->is($admin))->toBeTrue()
        ->and($activity->workspace_id)->toBeNull()
        ->and($activity->properties['old'])->toBe(['trial_ends_at' => $previousEnd])
        ->and($activity->properties['attributes'])->toBe(['trial_ends_at' => '2026-11-10 12:00:00']);
});

it('records a restart with the plan it brought back', function (): void {
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    $this->travelTo(now()->addDays(15));
    $this->artisan('billing:process-trials')->assertSuccessful();

    livewire(ListWorkspaces::class)
        ->callAction(TestAction::make('extendTrial')->table($workspace), ['trial_ends_at' => now()->addDays(7)->toDateTimeString()])
        ->assertHasNoActionErrors();

    $activity = Activity::query()->withoutGlobalScopes()->forSubject($workspace)->sole();

    expect($activity->event)->toBe('trial_restarted')
        ->and($activity->properties['old']['plan'])->toBe(Plan::Free->value)
        ->and($activity->properties['old']['trial_ends_at'])->toBeNull()
        ->and($activity->properties['attributes']['plan'])->toBe(Plan::Pro->value);
});

it('records who ended a trial early', function (): void {
    $admin = SystemAdministrator::factory()->create();
    $this->actingAs($admin, 'sysadmin');
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    $previousEnd = $workspace->trial_ends_at?->toDateTimeString();

    livewire(ListWorkspaces::class)
        ->callAction(TestAction::make('endTrial')->table($workspace));

    $activity = Activity::query()->withoutGlobalScopes()->forSubject($workspace)->sole();

    expect($activity->event)->toBe('trial_ended')
        ->and($activity->causer?->is($admin))->toBeTrue()
        ->and($activity->properties['old'])->toBe(['trial_ends_at' => $previousEnd])
        ->and($activity->properties['attributes'])->toBe(['trial_ends_at' => $workspace->refresh()->trial_ends_at?->toDateTimeString()]);
});

it('accepts the default and the last allowed minute for an administrator east of UTC', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00'));
    $this->actingAs(SystemAdministrator::factory()->create(['timezone' => 'Asia/Yerevan']), 'sysadmin');
    $defaulted = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    $defaulted->forceFill(['trial_ends_at' => now()->addDays(55)])->save();
    $picked = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));

    livewire(ListWorkspaces::class)
        ->callAction(TestAction::make('extendTrial')->table($defaulted))
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('extendTrial')->table($picked), ['trial_ends_at' => '2026-12-12 16:00:00'])
        ->assertHasNoActionErrors();

    expect($defaulted->refresh()->trial_ends_at?->toDateTimeString())->toBe('2026-12-12 12:00:00')
        ->and($picked->refresh()->trial_ends_at?->toDateTimeString())->toBe('2026-12-12 12:00:00');
});

it('refuses a trial end past sixty days for an administrator west of UTC', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00'));
    $this->actingAs(SystemAdministrator::factory()->create(['timezone' => 'America/Los_Angeles']), 'sysadmin');
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    $endsAt = $workspace->trial_ends_at;

    livewire(ListWorkspaces::class)
        ->callAction(TestAction::make('extendTrial')->table($workspace), ['trial_ends_at' => '2026-12-12 04:30:00'])
        ->assertHasActionErrors(['trial_ends_at']);

    expect($workspace->refresh()->trial_ends_at?->equalTo($endsAt))->toBeTrue();
});

it('refuses a trial end that has already passed in the administrator timezone', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00'));
    $this->actingAs(SystemAdministrator::factory()->create(['timezone' => 'Asia/Yerevan']), 'sysadmin');
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    $endsAt = $workspace->trial_ends_at;

    livewire(ListWorkspaces::class)
        ->callAction(TestAction::make('extendTrial')->table($workspace), ['trial_ends_at' => '2026-10-13 15:00:00'])
        ->assertHasActionErrors(['trial_ends_at']);

    expect($workspace->refresh()->trial_ends_at?->equalTo($endsAt))->toBeTrue();
});

it('records an earlier end as a shortened trial', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00'));
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    $workspace->forceFill(['trial_ends_at' => '2027-06-01 18:36:00'])->save();

    livewire(ListWorkspaces::class)
        ->callAction(TestAction::make('extendTrial')->table($workspace), ['trial_ends_at' => '2026-11-16 10:00:00'])
        ->assertHasNoActionErrors();

    $activity = Activity::query()->withoutGlobalScopes()->forSubject($workspace)->sole();

    expect($activity->event)->toBe('trial_shortened')
        ->and($activity->properties['old'])->toBe(['trial_ends_at' => '2027-06-01 18:36:00'])
        ->and($activity->properties['attributes'])->toBe(['trial_ends_at' => '2026-11-16 10:00:00']);
});
