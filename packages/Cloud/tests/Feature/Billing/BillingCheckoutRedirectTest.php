<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Relaticle\Cloud\Analytics\Models\Acquisition;
use Relaticle\Cloud\Billing\Actions\CreateSubscriptionCheckout;
use Relaticle\Cloud\Billing\Filament\Billing;
use Stripe\Exception\InvalidRequestException;
use Tests\Helpers\StripeRecorder;

mutates(Billing::class, CreateSubscriptionCheckout::class);

beforeEach(function (): void {
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
    withHostedBilling();

    StripeRecorder::install();

    livewire(Billing::class)
        ->call('buyCredits', 'small')
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_fake')
        ->assertNotNotified();
});

it('redirects the owner to the Stripe billing portal', function (): void {
    withHostedBilling();

    StripeRecorder::install();
    $this->workspace->forceFill(['plan' => Plan::Pro])->save();

    livewire(Billing::class)
        ->call('managePortal')
        ->assertRedirect('https://billing.stripe.com/p/session/test')
        ->assertNotNotified();
});

it('builds a hosted checkout session that keeps managed payments', function (): void {
    withHostedBilling();

    $recorder = StripeRecorder::install();
    config()->set('services.stripe.managed_payments', true);

    $url = resolve(CreateSubscriptionCheckout::class)->execute($this->workspace, Plan::Pro, 'monthly');

    expect($url)->toBe('https://checkout.stripe.com/c/pay/cs_test_fake');

    $params = $recorder->paramsFor('/checkout/sessions');

    expect($params['managed_payments'])->toBe(['enabled' => 'true'])
        ->and($params['success_url'])->toEndWith('/billing?checkout=success')
        ->and($params['cancel_url'])->toEndWith('/billing')
        ->and($params)->not->toHaveKey('ui_mode');
});

it('returns a paused workspace to a checkout result that reopens it', function (): void {
    withHostedBilling();

    $recorder = StripeRecorder::install();
    $this->workspace->forceFill([
        'hosted_free_grandfathered_at' => null,
        'pro_trial_used_at' => now()->subDays(20),
    ])->save();

    resolve(CreateSubscriptionCheckout::class)->execute($this->workspace->refresh(), Plan::Pro, 'yearly');

    expect($recorder->paramsFor('/checkout/sessions')['success_url'])->toEndWith('/billing?checkout=reopened');
});

it('carries a running trial into the subscription', function (): void {
    withHostedBilling();

    $this->freezeTime();
    $recorder = StripeRecorder::install();
    $this->workspace->forceFill(['trial_ends_at' => now()->addDays(9)])->save();

    resolve(CreateSubscriptionCheckout::class)->execute($this->workspace, Plan::Pro, 'yearly');

    $params = $recorder->paramsFor('/checkout/sessions');

    expect($params['subscription_data']['trial_end'])
        ->toBe(now()->addDays(9)->getTimestamp());
});

it('does not grant a trial to a workspace whose trial already ended', function (): void {
    withHostedBilling();

    $recorder = StripeRecorder::install();
    $this->workspace->forceFill(['trial_ends_at' => now()->subMonth()])->save();

    resolve(CreateSubscriptionCheckout::class)->execute($this->workspace, Plan::Pro, 'yearly');

    $params = $recorder->paramsFor('/checkout/sessions');

    expect($params['subscription_data'] ?? [])->not->toHaveKey('trial_end');
});

it('rejects an interval that is not on the price map', function (): void {
    withHostedBilling();

    StripeRecorder::install();

    expect(fn (): string => resolve(CreateSubscriptionCheckout::class)->execute($this->workspace, Plan::Pro, 'weekly'))
        ->toThrow(InvalidArgumentException::class);
});

function checkoutAcquisition(Workspace $workspace): Acquisition
{
    return Acquisition::query()->create([
        'user_id' => $workspace->user_id,
        'workspace_id' => $workspace->getKey(),
        'source' => 'google',
        'landing' => 'pricing',
        'captured_at' => now()->subDay(),
        'workspace_linked_at' => now()->subDay(),
    ]);
}

it('records the first successful subscription checkout and exposes its operation id to the browser', function (): void {
    withHostedBilling();
    $this->travelTo(now()->startOfSecond());
    $acquisition = checkoutAcquisition($this->workspace);
    $recorder = StripeRecorder::install();
    request()->setLaravelSession(app('session.store'));

    resolve(CreateSubscriptionCheckout::class)->execute($this->workspace, Plan::Pro, 'monthly');

    expect($acquisition->refresh()->first_checkout_session_id)->toBe('cs_test_fake')
        ->and($acquisition->first_checkout_at->equalTo(now()))->toBeTrue()
        ->and(session('fathom.track_checkout_started'))->toBeUuid();

    $this->travel(1)->hour();
    $recorder->respondTo('/checkout/sessions', [
        'id' => 'cs_later',
        'object' => 'checkout.session',
        'url' => 'https://checkout.stripe.com/c/pay/cs_later',
    ]);

    resolve(CreateSubscriptionCheckout::class)->execute($this->workspace, Plan::Pro, 'yearly');

    expect($acquisition->refresh()->first_checkout_session_id)->toBe('cs_test_fake')
        ->and($acquisition->first_checkout_at->equalTo(now()->subHour()))->toBeTrue()
        ->and(session('fathom.track_checkout_started'))->toBeUuid();
});

it('does not fabricate an acquisition for a legacy workspace checkout', function (): void {
    withHostedBilling();
    StripeRecorder::install();

    resolve(CreateSubscriptionCheckout::class)->execute($this->workspace, Plan::Pro, 'monthly');

    $this->assertDatabaseMissing('acquisitions', ['workspace_id' => $this->workspace->getKey()]);
});

it('records neither checkout nor browser success when Stripe rejects checkout creation', function (): void {
    withHostedBilling();
    $acquisition = checkoutAcquisition($this->workspace);
    request()->setLaravelSession(app('session.store'));
    StripeRecorder::install()->respondTo('/checkout/sessions', [
        'error' => ['type' => 'invalid_request_error', 'message' => 'Checkout unavailable'],
    ], 400);

    expect(fn (): string => resolve(CreateSubscriptionCheckout::class)->execute($this->workspace, Plan::Pro, 'monthly'))
        ->toThrow(InvalidRequestException::class);

    expect($acquisition->refresh()->first_checkout_at)->toBeNull()
        ->and($acquisition->first_checkout_session_id)->toBeNull()
        ->and(session()->has('fathom.track_checkout_started'))->toBeFalse();
});

it('records neither checkout nor browser success for an invalid plan interval', function (): void {
    withHostedBilling();
    $acquisition = checkoutAcquisition($this->workspace);
    request()->setLaravelSession(app('session.store'));
    $recorder = StripeRecorder::install();

    expect(fn (): string => resolve(CreateSubscriptionCheckout::class)->execute($this->workspace, Plan::Pro, 'weekly'))
        ->toThrow(InvalidArgumentException::class);

    expect($acquisition->refresh()->first_checkout_at)->toBeNull()
        ->and(session()->has('fathom.track_checkout_started'))->toBeFalse()
        ->and($recorder->requests)->toBeEmpty();
});
