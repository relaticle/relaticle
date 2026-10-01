<?php

declare(strict_types=1);

use App\Actions\Billing\CreateProCheckout;
use App\Enums\Plan;
use App\Features\Billing as BillingFeature;
use App\Filament\Pages\Billing;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Laravel\Pennant\Feature;
use Tests\Helpers\StripeRecorder;

mutates(Billing::class, CreateProCheckout::class);

beforeEach(function (): void {
    Feature::define(BillingFeature::class, true);
    config()->set('cashier.secret', 'sk_test_fake');
    config()->set('services.stripe.prices.pro_monthly', 'price_pro_monthly_test');
    config()->set('services.stripe.prices.pro_yearly', 'price_pro_yearly_test');
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);

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

it('redirects the owner to the Stripe checkout url when buying a credit pack', function (): void {
    StripeRecorder::install();

    livewire(Billing::class)
        ->call('buyCredits', 'small')
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_fake')
        ->assertNotNotified();
});

it('redirects the owner to the Stripe billing portal', function (): void {
    StripeRecorder::install();
    $this->workspace->forceFill(['plan' => Plan::Pro])->save();

    livewire(Billing::class)
        ->call('managePortal')
        ->assertRedirect('https://billing.stripe.com/p/session/test')
        ->assertNotNotified();
});

it('builds an embedded checkout session that keeps managed payments', function (): void {
    $recorder = StripeRecorder::install();
    config()->set('services.stripe.managed_payments', true);

    $secret = resolve(CreateProCheckout::class)->execute($this->workspace, 'monthly');

    expect($secret)->toBe('cs_test_fake_secret');

    $params = $recorder->paramsFor('/checkout/sessions');

    expect($params['ui_mode'])->toBe('embedded_page')
        ->and($params['redirect_on_completion'])->toBe('if_required')
        ->and($params['managed_payments'])->toBe(['enabled' => 'true'])
        ->and($params['return_url'])->toContain('checkout=success')
        ->and($params)->not->toHaveKey('success_url')
        ->and($params)->not->toHaveKey('cancel_url');
});

it('carries a running trial into the subscription', function (): void {
    $this->freezeTime();
    $recorder = StripeRecorder::install();
    $this->workspace->forceFill(['trial_ends_at' => now()->addDays(9)])->save();

    resolve(CreateProCheckout::class)->execute($this->workspace, 'yearly');

    $params = $recorder->paramsFor('/checkout/sessions');

    expect($params['subscription_data']['trial_end'])
        ->toBe(now()->addDays(9)->getTimestamp());
});

it('does not grant a trial to a workspace whose trial already ended', function (): void {
    $recorder = StripeRecorder::install();
    $this->workspace->forceFill(['trial_ends_at' => now()->subMonth()])->save();

    resolve(CreateProCheckout::class)->execute($this->workspace, 'yearly');

    $params = $recorder->paramsFor('/checkout/sessions');

    expect($params['subscription_data'] ?? [])->not->toHaveKey('trial_end');
});

it('rejects an interval that is not on the price map', function (): void {
    StripeRecorder::install();

    expect(fn (): string => resolve(CreateProCheckout::class)->execute($this->workspace, 'weekly'))
        ->toThrow(InvalidArgumentException::class);
});
