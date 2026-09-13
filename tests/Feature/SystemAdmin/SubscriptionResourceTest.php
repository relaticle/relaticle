<?php

declare(strict_types=1);

use App\Enums\BillingStatus;
use App\Enums\StripeSubscriptionStatus;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Laravel\Cashier\Subscription;
use Relaticle\SystemAdmin\Filament\Resources\SubscriptionResource;
use Relaticle\SystemAdmin\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(SubscriptionResource::class, StripeSubscriptionStatus::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
    config()->set('services.stripe.prices.pro_monthly', 'price_pro_monthly_test');
});

it('lists subscriptions with workspace and status for sysadmins', function (): void {
    /** @var Workspace $workspace */
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $subscription = Subscription::factory()->active()->withPrice('price_pro_monthly_test')
        ->create(['workspace_id' => $workspace->getKey()]);

    livewire(ListSubscriptions::class)
        ->assertCanSeeTableRecords([$subscription])
        ->assertCanRenderTableColumn('owner.name')
        ->assertCanRenderTableColumn('stripe_status')
        ->assertSee($workspace->name);
});

it('filters subscriptions by status', function (): void {
    /** @var Workspace $workspaceA */
    $workspaceA = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    /** @var Workspace $workspaceB */
    $workspaceB = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;

    $active = Subscription::factory()->active()->withPrice('price_pro_monthly_test')
        ->create(['workspace_id' => $workspaceA->getKey()]);
    $canceled = Subscription::factory()->canceled()->withPrice('price_pro_monthly_test')
        ->create(['workspace_id' => $workspaceB->getKey(), 'ends_at' => now()->subDay()]);

    livewire(ListSubscriptions::class)
        ->filterTable('stripe_status', 'active')
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$canceled]);
});

it('spells a stripe status the way the workspace billing badge spells it', function (): void {
    /** @var Workspace $workspace */
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $subscription = Subscription::factory()->pastDue()->withPrice('price_pro_monthly_test')
        ->create(['workspace_id' => $workspace->getKey()]);

    livewire(ListSubscriptions::class)
        ->assertCanSeeTableRecords([$subscription])
        ->assertSee(StripeSubscriptionStatus::PastDue->getLabel())
        ->assertSeeHtml(StripeSubscriptionStatus::PastDue->getDescription());

    expect(StripeSubscriptionStatus::PastDue->getLabel())->toBe(BillingStatus::PastDue->getLabel())
        ->and(StripeSubscriptionStatus::PastDue->getColor())->toBe(BillingStatus::PastDue->getColor());
});

it('renders a status Stripe adds later as its raw value instead of failing', function (): void {
    /** @var Workspace $workspace */
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $subscription = Subscription::factory()->withPrice('price_pro_monthly_test')
        ->create(['workspace_id' => $workspace->getKey(), 'stripe_status' => 'some_future_status']);

    livewire(ListSubscriptions::class)
        ->assertCanSeeTableRecords([$subscription])
        ->assertSee('some_future_status');
});

it('labels a subscription by the plan and interval its price maps to', function (): void {
    config()->set('services.stripe.prices.pro_yearly', 'price_pro_yearly_test');

    /** @var Workspace $workspace */
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $subscription = Subscription::factory()->active()->withPrice('price_pro_yearly_test')
        ->create(['workspace_id' => $workspace->getKey()]);

    livewire(ListSubscriptions::class)
        ->assertCanSeeTableRecords([$subscription])
        ->assertSee('Pro · yearly');
});

it('falls back to the raw price id when it is not in the configured price map', function (): void {
    /** @var Workspace $workspace */
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $subscription = Subscription::factory()->active()->withPrice('price_not_in_the_map')
        ->create(['workspace_id' => $workspace->getKey()]);

    livewire(ListSubscriptions::class)
        ->assertCanSeeTableRecords([$subscription])
        ->assertSee('price_not_in_the_map');
});
