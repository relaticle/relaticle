<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Enums\Plan;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\SystemAdmin\Enums\LeadStage;
use Relaticle\SystemAdmin\Filament\Widgets\Overview\SalesLeads;
use Relaticle\SystemAdmin\Metrics\SalesLeadsQuery;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Tests\Helpers\OverviewData;

mutates(SalesLeads::class, SalesLeadsQuery::class, LeadStage::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
});

function leadsOn(LeadStage $stage): Testable
{
    return livewire(SalesLeads::class)->callAction(TestAction::make("stage_{$stage->value}")->table());
}

function endedTrial(Workspace $workspace, CarbonImmutable $startedAt): Workspace
{
    $workspace->forceFill(['plan' => Plan::Free, 'trial_ends_at' => null, 'pro_trial_used_at' => $startedAt])->save();

    return $workspace->refresh();
}

it('lists free customers with real use, most active first, and says why', function (): void {
    $light = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    OverviewData::ownRecord(OverviewData::workspaceOf($light), $light, now()->subDay());

    $bulk = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    for ($record = 0; $record < 5; $record++) {
        OverviewData::ownRecord(OverviewData::workspaceOf($bulk), $bulk, now()->subDay());
    }

    $steady = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    foreach ([1, 2, 3] as $daysAgo) {
        OverviewData::ownRecord(OverviewData::workspaceOf($steady), $steady, now()->subDays($daysAgo));
    }

    $busy = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    foreach ([1, 2, 3] as $daysAgo) {
        OverviewData::ownRecord(OverviewData::workspaceOf($busy), $busy, now()->subDays($daysAgo));
    }
    OverviewData::ownRecord(OverviewData::workspaceOf($busy), $busy, now()->subDay(), CreationSource::API);

    $sampleOnly = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    OverviewData::sampleRecord(OverviewData::workspaceOf($sampleOnly), now()->subDay());

    $internal = OverviewData::internalOwner();
    OverviewData::ownRecord(OverviewData::workspaceOf($internal), $internal, now()->subDay());

    leadsOn(LeadStage::Free)
        ->assertCanSeeTableRecords([
            OverviewData::workspaceOf($busy),
            OverviewData::workspaceOf($steady),
            OverviewData::workspaceOf($bulk),
            OverviewData::workspaceOf($light),
        ], inOrder: true)
        ->assertCanNotSeeTableRecords([OverviewData::workspaceOf($sampleOnly), OverviewData::workspaceOf($internal)])
        ->assertSee('4 records, 3 active days')
        ->assertSee('uses API');
});

it('leaves out a workspace whose only records a mailbox sync created', function (): void {
    $synced = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    OverviewData::ownRecord(OverviewData::workspaceOf($synced), $synced, now()->subDay(), CreationSource::MAILBOX);

    leadsOn(LeadStage::Free)->assertCanNotSeeTableRecords([OverviewData::workspaceOf($synced)]);
});

it('leaves out workspaces that already pay or are on a negotiated plan', function (): void {
    $subscriber = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    $subscribed = OverviewData::workspaceOf($subscriber);
    OverviewData::ownRecord($subscribed, $subscriber, now()->subDay());
    $subscribed->subscriptions()->create([
        'type' => 'default', 'stripe_id' => 'sub_paying', 'stripe_status' => 'active', 'stripe_price' => 'price_x', 'quantity' => 1,
    ]);

    $negotiated = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    OverviewData::ownRecord(OverviewData::workspaceOf($negotiated), $negotiated, now()->subDay());
    OverviewData::workspaceOf($negotiated)->forceFill(['plan' => Plan::Enterprise])->save();

    $free = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    OverviewData::ownRecord(OverviewData::workspaceOf($free), $free, now()->subDay());

    leadsOn(LeadStage::Free)
        ->assertCanSeeTableRecords([OverviewData::workspaceOf($free)])
        ->assertCanNotSeeTableRecords([$subscribed, OverviewData::workspaceOf($negotiated)]);
});

it('renders an empty list when nobody qualifies', function (): void {
    livewire(SalesLeads::class)
        ->assertSuccessful()
        ->assertCountTableRecords(0);
});

it('shows ten workspaces a page', function (): void {
    for ($workspace = 0; $workspace < 11; $workspace++) {
        $owner = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
        OverviewData::ownRecord(OverviewData::workspaceOf($owner), $owner, now()->subDay());
    }

    expect(leadsOn(LeadStage::Free)->instance()->getTableRecords())->toHaveCount(10);
});

it('counts the last 30 calendar days, today included, as active days', function (): void {
    $owner = OverviewData::owner(CarbonImmutable::parse('2026-08-01'));
    $workspace = OverviewData::workspaceOf($owner);
    OverviewData::ownRecord($workspace, $owner, CarbonImmutable::parse('2026-09-15 12:00:00'));
    OverviewData::ownRecord($workspace, $owner, CarbonImmutable::parse('2026-09-16 12:00:00'));
    OverviewData::ownRecord($workspace, $owner, now());

    leadsOn(LeadStage::Free)->assertSee('3 records, 2 active days');
});

it('lists a workspace whose owner no longer exists, without an email action', function (): void {
    $departed = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    $orphaned = OverviewData::workspaceOf($departed);
    OverviewData::ownRecord($orphaned, $departed, now()->subDay());
    $orphaned->forceFill(['user_id' => (string) Str::ulid()])->save();

    $present = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    OverviewData::ownRecord(OverviewData::workspaceOf($present), $present, now()->subDay());

    leadsOn(LeadStage::Free)
        ->assertCanSeeTableRecords([$orphaned, OverviewData::workspaceOf($present)])
        ->assertActionHidden(TestAction::make('emailOwner')->table($orphaned))
        ->assertActionVisible(TestAction::make('emailOwner')->table(OverviewData::workspaceOf($present)));
});

it('explains who makes the list in a tooltip', function (): void {
    livewire(SalesLeads::class)->assertSee('Log outreach in the Relaticle HQ workspace');
});

it('opens on trials with their own data, the one ending soonest first', function (): void {
    $later = OverviewData::owner(CarbonImmutable::parse('2026-10-10'));
    $laterTrial = OverviewData::trial(OverviewData::workspaceOf($later));
    OverviewData::ownRecord($laterTrial, $later, now()->subDay());

    $sooner = OverviewData::owner(CarbonImmutable::parse('2026-10-03'));
    $soonerTrial = OverviewData::trial(OverviewData::workspaceOf($sooner));
    $soonerTrial->forceFill(['trial_ends_at' => now()->addDays(2)->subHour()])->save();
    OverviewData::ownRecord($soonerTrial, $sooner, now()->subDays(3), CreationSource::IMPORT);
    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $soonerTrial->getKey()], [
        'credits_remaining' => 960, 'credits_used' => 40, 'period_starts_at' => now()->startOfMonth(), 'period_ends_at' => now()->endOfMonth(),
    ]);

    $empty = OverviewData::owner(CarbonImmutable::parse('2026-10-10'));
    OverviewData::trial(OverviewData::workspaceOf($empty));

    $free = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    OverviewData::ownRecord(OverviewData::workspaceOf($free), $free, now()->subDay());

    livewire(SalesLeads::class)
        ->assertCanSeeTableRecords([$soonerTrial, $laterTrial], inOrder: true)
        ->assertCanNotSeeTableRecords([OverviewData::workspaceOf($empty), OverviewData::workspaceOf($free)])
        ->assertTableColumnStateSet('stage', '2 days left', $soonerTrial)
        ->assertTableColumnStateSet('stage', '14 days left', $laterTrial)
        ->assertSee('1 record (imported), 1 active day, 40 chat credits used')
        ->assertSee('Trialing (2)')
        ->assertSee('Free (1)');
});

it('lists ended trials with their own data, the most recent first', function (): void {
    $recent = OverviewData::owner(CarbonImmutable::parse('2026-09-28'));
    $recentEnded = endedTrial(OverviewData::workspaceOf($recent), CarbonImmutable::parse('2026-09-28 12:00:00'));
    OverviewData::ownRecord($recentEnded, $recent, CarbonImmutable::parse('2026-09-29 12:00:00'));

    $older = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    $olderEnded = endedTrial(OverviewData::workspaceOf($older), CarbonImmutable::parse('2026-09-01 12:00:00'));
    OverviewData::ownRecord($olderEnded, $older, CarbonImmutable::parse('2026-09-02 12:00:00'));

    $empty = OverviewData::owner(CarbonImmutable::parse('2026-09-28'));
    endedTrial(OverviewData::workspaceOf($empty), CarbonImmutable::parse('2026-09-28 12:00:00'));

    leadsOn(LeadStage::TrialEnded)
        ->assertCanSeeTableRecords([$recentEnded, $olderEnded], inOrder: true)
        ->assertCanNotSeeTableRecords([OverviewData::workspaceOf($empty)])
        ->assertTableColumnStateSet('stage', 'Ended 3 days ago', $recentEnded)
        ->assertTableColumnStateSet('stage', 'Ended 30 days ago', $olderEnded)
        ->assertSee(LeadStage::TrialEnded->getHelp())
        ->assertDontSee(LeadStage::Trialing->getHelp());
});
