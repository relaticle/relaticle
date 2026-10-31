<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Features\Billing;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Cloud\Admin\Filament\Pages\Overview;
use Relaticle\Cloud\Admin\Filament\Resources\AiCreditTransactions\Pages\ListAiCreditTransactions;
use Relaticle\Cloud\Admin\Filament\Widgets\Overview\MoneyStats;
use Relaticle\Cloud\Admin\Filament\Widgets\Overview\PlanStats;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages\ListWorkspaces;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Tests\Helpers\OverviewData;

mutates(PlanStats::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
    Cache::flush();
    withHostedBilling();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
});

function workspaceOn(Plan $plan): Workspace
{
    $workspace = OverviewData::workspaceOf(OverviewData::owner());
    $workspace->forceFill(['plan' => $plan])->save();

    return $workspace;
}

it('renders on an empty database', function (): void {
    livewire(PlanStats::class)
        ->assertOk()
        ->assertSee('Who is on which plan?')
        ->assertSee('Cloud Pro')
        ->assertSee('Cloud Scale')
        ->assertSee('Enterprise')
        ->assertSee('No customer workspaces yet')
        ->assertSee('No packs bought this month')
        ->assertSee('Nobody is blocked by the cap');
});

it('follows the money row', function (): void {
    $widgets = livewire(Overview::class)->instance()->getVisibleWidgets();

    expect(array_search(PlanStats::class, $widgets, true))->toBe(array_search(MoneyStats::class, $widgets, true) + 1);
});

it('leaves the overview when billing is off', function (): void {
    Feature::define(Billing::class, false);

    expect(livewire(Overview::class)->instance()->getVisibleWidgets())->not->toContain(PlanStats::class);
});

it('counts customer workspaces per plan exactly as the workspace list filters them', function (): void {
    $pro = workspaceOn(Plan::Pro);
    $trialing = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    $scale = workspaceOn(Plan::Scale);
    $free = workspaceOn(Plan::Free);
    $internal = OverviewData::workspaceOf(OverviewData::internalOwner());
    $internal->forceFill(['plan' => Plan::Scale])->save();

    livewire(PlanStats::class)
        ->assertSee('50% of 4 customer workspaces')
        ->assertSee('25% of 4 customer workspaces')
        ->assertSee('filters%5Bplan%5D%5Bvalue%5D=scale', escape: false);

    livewire(ListWorkspaces::class)
        ->filterTable('internal', false)
        ->filterTable('plan', Plan::Scale->value)
        ->assertCanSeeTableRecords([$scale])
        ->assertCanNotSeeTableRecords([$pro, $trialing, $free, $internal]);
});

it('counts the credit packs customers bought this month', function (): void {
    $customer = workspaceOn(Plan::Pro);
    $thisMonth = AiCreditTransaction::factory()->create([
        'workspace_id' => $customer,
        'type' => AiCreditType::Purchase,
        'credits_charged' => 20_000,
        'created_at' => CarbonImmutable::parse('2026-10-02 09:00:00'),
    ]);
    $lastMonth = AiCreditTransaction::factory()->create([
        'workspace_id' => $customer,
        'type' => AiCreditType::Purchase,
        'credits_charged' => 1_000,
        'created_at' => CarbonImmutable::parse('2026-09-30 23:00:00'),
    ]);
    $spent = AiCreditTransaction::factory()->create(['workspace_id' => $customer, 'created_at' => CarbonImmutable::parse('2026-10-03 09:00:00')]);
    AiCreditTransaction::factory()->create([
        'workspace_id' => OverviewData::workspaceOf(OverviewData::internalOwner()),
        'type' => AiCreditType::Purchase,
        'credits_charged' => 5_000,
        'created_at' => CarbonImmutable::parse('2026-10-03 09:00:00'),
    ]);

    livewire(PlanStats::class)
        ->assertSee('20,000 credits bought')
        ->assertSee('filters%5Btype%5D%5Bvalue%5D=purchase', escape: false)
        ->assertSee('filters%5Bdate_range%5D%5Bfrom%5D=2026-10-01', escape: false);

    livewire(ListAiCreditTransactions::class)
        ->filterTable('type', AiCreditType::Purchase->value)
        ->filterTable('date_range', ['from' => '2026-10-01'])
        ->assertCanSeeTableRecords([$thisMonth])
        ->assertCanNotSeeTableRecords([$lastMonth, $spent]);
});

it('counts self-serve workspaces at their mailbox cap exactly as the workspace list shows them', function (): void {
    $capped = workspaceOn(Plan::Pro);
    ConnectedAccount::factory()->count(10)->create(['workspace_id' => $capped]);
    $nearlyCapped = workspaceOn(Plan::Pro);
    ConnectedAccount::factory()->count(9)->create(['workspace_id' => $nearlyCapped]);
    ConnectedAccount::factory()->disconnected()->create(['workspace_id' => $nearlyCapped]);
    $scale = workspaceOn(Plan::Scale);
    ConnectedAccount::factory()->count(10)->create(['workspace_id' => $scale]);
    $free = workspaceOn(Plan::Free);
    ConnectedAccount::factory()->create(['workspace_id' => $free]);
    $internal = OverviewData::workspaceOf(OverviewData::internalOwner());
    $internal->forceFill(['plan' => Plan::Pro])->save();
    ConnectedAccount::factory()->count(10)->create(['workspace_id' => $internal]);

    livewire(PlanStats::class)
        ->assertSee('Blocked from connecting another mailbox')
        ->assertSee('filters%5Bat_mailbox_cap%5D%5BisActive%5D=1', escape: false);

    livewire(ListWorkspaces::class)
        ->filterTable('internal', false)
        ->filterTable('at_mailbox_cap')
        ->assertCanSeeTableRecords([$capped])
        ->assertCanNotSeeTableRecords([$nearlyCapped, $scale, $free, $internal]);
});
