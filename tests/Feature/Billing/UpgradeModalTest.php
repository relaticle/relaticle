<?php

declare(strict_types=1);

use App\Actions\Billing\CreateSubscriptionCheckout;
use App\Enums\Plan;
use App\Features\Billing as BillingFeature;
use App\Filament\Pages\Billing;
use App\Filament\Pages\Dashboard;
use App\Livewire\App\Billing\UpgradeModal;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Pennant\Feature;
use Tests\Helpers\StripeRecorder;

mutates(UpgradeModal::class);

beforeEach(function (): void {
    Feature::define(BillingFeature::class, true);
    config()->set('cashier.secret', 'sk_test_fake');
    config()->set('services.stripe.prices.pro_monthly', 'price_pro_monthly_test');
    config()->set('services.stripe.prices.pro_yearly', 'price_pro_yearly_test');
    config()->set('services.stripe.prices.scale_monthly', 'price_scale_monthly_test');
    config()->set('services.stripe.prices.scale_yearly', 'price_scale_yearly_test');

    $user = User::factory()->withPersonalWorkspace()->create();

    /** @var Workspace $workspace */
    $workspace = $user->currentWorkspace;
    $workspace->forceFill([
        'hosted_free_grandfathered_at' => now(),
        'plan' => Plan::Free,
        'stripe_id' => 'cus_test_fake',
    ])->save();

    $this->actingAs($user);
    Filament::setTenant($workspace);
    $this->workspace = $workspace;
});

afterEach(function (): void {
    StripeRecorder::uninstall();
});

it('sends the owner to hosted Stripe checkout for the chosen period', function (): void {
    $recorder = StripeRecorder::install();

    livewire(UpgradeModal::class)
        ->call('checkout', 'pro', 'monthly')
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_fake')
        ->assertNotNotified();

    expect($recorder->paramsFor('/checkout/sessions')['line_items'][0]['price'])->toBe('price_pro_monthly_test');
});

it('sends the owner to checkout for Cloud Scale when that plan is picked', function (): void {
    $recorder = StripeRecorder::install();

    livewire(UpgradeModal::class)
        ->call('checkout', 'scale', 'yearly')
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_fake')
        ->assertNotNotified();

    expect($recorder->paramsFor('/checkout/sessions')['line_items'][0]['price'])->toBe('price_scale_yearly_test');
});

it('notifies instead of throwing when the plan is not sold through checkout', function (string $plan): void {
    Exceptions::fake();
    StripeRecorder::install();

    livewire(UpgradeModal::class)
        ->call('checkout', $plan, 'yearly')
        ->assertNoRedirect()
        ->assertNotified(Notification::make()->title(__('billing.errors.checkout_failed'))->danger());

    Exceptions::assertNotReported(InvalidArgumentException::class);
})->with(['free', 'enterprise', 'platinum']);

it('reviews the plan, the period and the totals before checkout', function (): void {
    livewire(UpgradeModal::class)
        ->assertSee(__('billing.upgrade.review.billing_period'))
        ->assertSee(__('billing.upgrade.review.summary'))
        ->assertSee(__('billing.upgrade.review.plan'))
        ->assertSee(__('billing.plans.cloud_pro'))
        ->assertSee(__('billing.plans.cloud_scale'))
        ->assertSee(__('billing.upgrade.review.line_item', ['plan' => __('billing.plans.cloud_pro'), 'workspace' => $this->workspace->name]))
        ->assertSee(__('billing.upgrade.review.amount', ['amount' => number_format(config()->integer('relaticle.pro.price_yearly'))]))
        ->assertSee(__('billing.upgrade.review.credits', ['credits' => number_format(Plan::Pro->credits())]))
        ->assertSee(__('billing.upgrade.review.proceed'))
        ->assertDontSee(__('billing.upgrade.trial_notice', ['date' => '']))
        ->assertDontSee('@js(', false);
});

it('tells a workspace on trial that the card is not charged until the trial ends', function (): void {
    $this->workspace->forceFill(['trial_ends_at' => now()->addDays(9)])->save();

    livewire(UpgradeModal::class)
        ->assertSee(__('billing.upgrade.trial_notice', ['date' => now()->addDays(9)->toFormattedDateString()]));
});

it('refuses a member who does not own the workspace', function (): void {
    StripeRecorder::install();

    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => 'admin']);
    $this->actingAs($member);

    livewire(UpgradeModal::class)
        ->assertDontSee(__('billing.upgrade.review.proceed'))
        ->call('checkout', 'pro', 'yearly')
        ->assertNoRedirect();
});

it('refuses a workspace that already subscribes', function (): void {
    StripeRecorder::install();

    $this->workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test_fake',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_yearly_test',
        'quantity' => 1,
    ]);

    livewire(UpgradeModal::class)
        ->call('checkout', 'pro', 'yearly')
        ->assertNoRedirect();
});

it('refuses a workspace on a managed plan', function (Plan $plan): void {
    StripeRecorder::install();
    $this->workspace->forceFill(['plan' => $plan])->save();

    livewire(UpgradeModal::class)
        ->call('checkout', 'pro', 'yearly')
        ->assertNoRedirect();
})->with([Plan::Enterprise]);

it('refuses when billing is switched off', function (): void {
    StripeRecorder::install();
    Feature::define(BillingFeature::class, false);

    livewire(UpgradeModal::class)
        ->call('checkout', 'pro', 'yearly')
        ->assertNoRedirect();
});

it('notifies instead of throwing when the interval is unknown', function (): void {
    Exceptions::fake();
    StripeRecorder::install();

    livewire(UpgradeModal::class)
        ->call('checkout', 'pro', 'weekly')
        ->assertNoRedirect()
        ->assertNotified(Notification::make()->title(__('billing.errors.checkout_failed'))->danger());

    Exceptions::assertNotReported(InvalidArgumentException::class);
});

it('reports an unexpected checkout failure instead of swallowing it', function (): void {
    Exceptions::fake();

    app()->bind(CreateSubscriptionCheckout::class, function (): never {
        throw new RuntimeException('stripe unreachable');
    });

    livewire(UpgradeModal::class)
        ->call('checkout', 'pro', 'yearly')
        ->assertNoRedirect()
        ->assertNotified(Notification::make()->title(__('billing.errors.checkout_failed'))->danger());

    Exceptions::assertReported(RuntimeException::class);
});

it('reports a missing price configuration instead of blaming the browser', function (): void {
    Exceptions::fake();
    StripeRecorder::install();
    config()->set('services.stripe.prices.pro_yearly', null);

    livewire(UpgradeModal::class)
        ->call('checkout', 'pro', 'yearly')
        ->assertNoRedirect()
        ->assertNotified(Notification::make()->title(__('billing.errors.checkout_failed'))->danger());

    Exceptions::assertReported(InvalidArgumentException::class);
});

it('mounts the modal for an owner who can upgrade', function (): void {
    $this->get(Billing::getUrl(panel: 'app', tenant: $this->workspace))
        ->assertOk()
        ->assertSeeLivewire(UpgradeModal::class);
});

it('mounts the modal on the paused screen so its buttons have something to open', function (): void {
    $this->workspace->forceFill([
        'hosted_free_grandfathered_at' => null,
        'pro_trial_used_at' => now()->subDays(20),
    ])->save();

    $this->get(Billing::getUrl(panel: 'app', tenant: $this->workspace))
        ->assertOk()
        ->assertSee(__('billing.paused.continue'))
        ->assertSeeLivewire(UpgradeModal::class)
        ->assertSee(__('billing.upgrade.review.proceed'));
});

it('does not mount the modal for a member who cannot upgrade', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => 'admin']);
    $this->actingAs($member);

    $this->get(Billing::getUrl(panel: 'app', tenant: $this->workspace))
        ->assertOk()
        ->assertDontSeeLivewire(UpgradeModal::class);
});

it('does not mount the modal anywhere in the panel when billing is switched off', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->get(Dashboard::getUrl(panel: 'app', tenant: $this->workspace))
        ->assertOk()
        ->assertDontSeeLivewire(UpgradeModal::class);
});
