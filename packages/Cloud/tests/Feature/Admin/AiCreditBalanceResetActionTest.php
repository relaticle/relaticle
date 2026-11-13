<?php

declare(strict_types=1);

use App\Enums\DeletionReason;
use App\Enums\Plan;
use App\Models\Workspace;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Cloud\Admin\Actions\ResetCreditPeriod;
use Relaticle\Cloud\Admin\Filament\Resources\AiCreditBalanceResource;
use Relaticle\Cloud\Admin\Filament\Resources\AiCreditBalanceResource\Pages\ListAiCreditBalances;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(AiCreditBalanceResource::class);
mutates(CreditService::class);

beforeEach(function (): void {
    $this->admin = SystemAdministrator::factory()->create();
    $this->actingAs($this->admin, 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
});

it('resets the period using the chosen plan allowance and logs an audit transaction', function (): void {
    withHostedBilling();

    $workspace = Workspace::factory()->create();
    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->delete();
    AiCreditTransaction::query()->where('workspace_id', $workspace->getKey())->delete();
    $balance = AiCreditBalance::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'credits_remaining' => 12,
        'credits_used' => 488,
    ]);

    livewire(ListAiCreditBalances::class)
        ->callAction(TestAction::make('resetPeriod')->table($balance), [
            'plan' => 'pro',
        ])
        ->assertHasNoActionErrors();

    $balance->refresh();
    expect($balance->credits_remaining)->toBe(Plan::Pro->credits())
        ->and($balance->credits_used)->toBe(0);

    expect($workspace->fresh()->plan)->toBe(Plan::Pro);

    $transaction = AiCreditTransaction::query()
        ->where('workspace_id', $workspace->getKey())
        ->where('type', AiCreditType::Adjustment)
        ->latest('id')
        ->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->credits_charged)->toBe(0)
        ->and($transaction->metadata['action'])->toBe('reset_period')
        ->and($transaction->metadata['plan'])->toBe('pro')
        ->and($transaction->metadata['allowance_granted'])->toBe(Plan::Pro->credits())
        ->and($transaction->metadata['previous_credits_remaining'])->toBe(12)
        ->and($transaction->metadata['previous_credits_used'])->toBe(488)
        ->and($transaction->metadata['sysadmin_id'])->toBe((string) $this->admin->getKey());
});

it('resets multiple balances via the bulk action', function (): void {
    withHostedBilling();

    $workspace1 = Workspace::factory()->create();
    $workspace2 = Workspace::factory()->create();
    AiCreditBalance::query()->where('workspace_id', $workspace1->getKey())->delete();
    $b1 = AiCreditBalance::factory()->create(['workspace_id' => $workspace1->getKey(), 'credits_remaining' => 0, 'credits_used' => 100]);
    AiCreditBalance::query()->where('workspace_id', $workspace2->getKey())->delete();
    $b2 = AiCreditBalance::factory()->create(['workspace_id' => $workspace2->getKey(), 'credits_remaining' => 5, 'credits_used' => 200]);

    livewire(ListAiCreditBalances::class)
        ->selectTableRecords([$b1->getKey(), $b2->getKey()])
        ->callAction(
            [['name' => 'resetPeriod', 'context' => ['table' => true, 'bulk' => true]]],
            data: ['plan' => 'enterprise'],
        )
        ->assertHasNoActionErrors();

    expect($b1->refresh()->credits_remaining)->toBe(Plan::Enterprise->credits())
        ->and($b2->refresh()->credits_remaining)->toBe(Plan::Enterprise->credits());
});

it('renders all Plan enum cases as reset-action plan options', function (): void {
    withHostedBilling();

    $workspace = Workspace::factory()->create();
    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->delete();
    $balance = AiCreditBalance::factory()->create(['workspace_id' => $workspace->getKey()]);

    livewire(ListAiCreditBalances::class)
        ->mountAction(TestAction::make('resetPeriod')->table($balance))
        ->assertMountedActionModalSee('Free')
        ->assertMountedActionModalSee('Pro')
        ->assertMountedActionModalSee('Enterprise');
});

it('points a workspace with a valid Pro subscription to Start managed plan', function (): void {
    withHostedBilling();

    $workspace = Workspace::factory()->create();
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_reset_warning',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);
    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->delete();
    $balance = AiCreditBalance::factory()->create(['workspace_id' => $workspace->getKey()]);

    livewire(ListAiCreditBalances::class)
        ->mountAction(TestAction::make('resetPeriod')->table($balance))
        ->assertMountedActionModalSee('Start managed plan')
        ->assertMountedActionModalDontSee('Cancel the subscription in Stripe first');
});

it('warns that webhook sync restores a paid managed plan', function (): void {
    withHostedBilling();

    $workspace = Workspace::factory()->create();
    $workspace->subscriptions()->create([
        'type' => 'managed',
        'stripe_id' => 'sub_reset_managed_warning',
        'stripe_status' => 'active',
        'stripe_price' => 'price_inline_reset_managed',
        'quantity' => 1,
    ]);
    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->delete();
    $balance = AiCreditBalance::factory()->create(['workspace_id' => $workspace->getKey()]);

    livewire(ListAiCreditBalances::class)
        ->mountAction(TestAction::make('resetPeriod')->table($balance))
        ->assertMountedActionModalSee('Webhook sync restores that plan over a Free grant')
        ->assertMountedActionModalDontSee('Start managed plan');
});

it('cancels an inactivity countdown when the reset grants a paid plan, and keeps an owner request', function (DeletionReason $reason, string $plan, bool $cancelled): void {
    withHostedBilling();

    $workspace = Workspace::factory()->create(['scheduled_deletion_at' => now()->addDays(14)->startOfSecond(), 'deletion_reason' => $reason]);
    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->firstOr(fn (): AiCreditBalance => AiCreditBalance::factory()->create(['workspace_id' => $workspace->getKey()]));

    livewire(ListAiCreditBalances::class)
        ->callAction(TestAction::make('resetPeriod')->table($balance), ['plan' => $plan])
        ->assertHasNoActionErrors();

    $workspace->refresh();

    expect($workspace->scheduled_deletion_at === null)->toBe($cancelled)
        ->and($workspace->deletion_reason)->toBe($cancelled ? null : $reason);
})->with([
    'inactivity, pro' => [DeletionReason::Inactivity, 'pro', true],
    'inactivity, free' => [DeletionReason::Inactivity, 'free', false],
    'requested, pro' => [DeletionReason::Requested, 'pro', false],
]);

it('lets a super administrator grant a plan through the reset action', function (): void {
    withHostedBilling();

    $workspace = Workspace::factory()->create(['plan' => Plan::Free]);

    resolve(ResetCreditPeriod::class)->execute($this->admin, Plan::Enterprise, [$workspace]);

    expect($workspace->fresh()->plan)->toBe(Plan::Enterprise);
});

it('lets a plain administrator reset credits on the current plan but not choose a plan', function (): void {
    withHostedBilling();

    $administrator = SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::Administrator]);
    $this->actingAs($administrator, 'sysadmin');

    $workspace = Workspace::factory()->create(['plan' => Plan::Free, 'trial_ends_at' => null, 'pro_trial_used_at' => now()->subDay()]);
    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->delete();
    $balance = AiCreditBalance::factory()->create(['workspace_id' => $workspace->getKey(), 'credits_remaining' => 3, 'credits_used' => 47]);

    livewire(ListAiCreditBalances::class)
        ->mountAction(TestAction::make('resetPeriod')->table($balance))
        ->assertMountedActionModalDontSee('Enterprise')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    livewire(ListAiCreditBalances::class)
        ->callAction(TestAction::make('resetPeriod')->table($balance), data: ['plan' => 'enterprise']);

    expect($workspace->fresh()->plan)->toBe(Plan::Free)
        ->and($balance->refresh()->credits_used)->toBe(0)
        ->and($balance->credits_remaining)->toBe(Plan::Free->credits());
});

it('lets a plain administrator bulk reset credits without changing any plan', function (): void {
    withHostedBilling();

    $administrator = SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::Administrator]);
    $this->actingAs($administrator, 'sysadmin');

    $free = Workspace::factory()->create(['plan' => Plan::Free]);
    $pro = Workspace::factory()->create(['plan' => Plan::Pro]);
    AiCreditBalance::query()->whereIn('workspace_id', [$free->getKey(), $pro->getKey()])->delete();
    $freeBalance = AiCreditBalance::factory()->create(['workspace_id' => $free->getKey(), 'credits_used' => 10]);
    $proBalance = AiCreditBalance::factory()->create(['workspace_id' => $pro->getKey(), 'credits_used' => 10]);

    livewire(ListAiCreditBalances::class)
        ->selectTableRecords([$freeBalance->getKey(), $proBalance->getKey()])
        ->callAction(TestAction::make('resetPeriod')->table()->bulk(), data: ['plan' => 'enterprise'])
        ->assertHasNoActionErrors();

    expect($free->fresh()->plan)->toBe(Plan::Free)
        ->and($pro->fresh()->plan)->toBe(Plan::Pro)
        ->and($freeBalance->refresh()->credits_remaining)->toBe(Plan::Free->credits())
        ->and($proBalance->refresh()->credits_remaining)->toBe(Plan::Pro->credits());
});

it('rejects a plan grant from a plain administrator in the reset action class', function (): void {
    withHostedBilling();

    $administrator = SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::Administrator]);
    $workspace = Workspace::factory()->create(['plan' => Plan::Free]);

    expect(fn () => resolve(ResetCreditPeriod::class)->execute($administrator, Plan::Enterprise, [$workspace]))
        ->toThrow(HttpException::class);

    expect($workspace->fresh()->plan)->toBe(Plan::Free);
});
