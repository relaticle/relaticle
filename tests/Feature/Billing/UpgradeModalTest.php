<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Features\Billing as BillingFeature;
use App\Livewire\App\Billing\UpgradeModal;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Laravel\Pennant\Feature;
use Tests\Helpers\StripeRecorder;

mutates(UpgradeModal::class);

beforeEach(function (): void {
    Feature::define(BillingFeature::class, true);
    config()->set('cashier.secret', 'sk_test_fake');
    config()->set('services.stripe.prices.pro_monthly', 'price_pro_monthly_test');
    config()->set('services.stripe.prices.pro_yearly', 'price_pro_yearly_test');

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

it('gives the owner a client secret', function (): void {
    StripeRecorder::install();

    livewire(UpgradeModal::class)
        ->call('createSession', 'yearly', 'dark')
        ->assertReturned('cs_test_fake_secret');
});

it('passes the requested theme to the session', function (): void {
    $recorder = StripeRecorder::install();

    livewire(UpgradeModal::class)->call('createSession', 'yearly', 'dark');

    expect($recorder->paramsFor('/checkout/sessions')['branding_settings']['background_color'])
        ->toBe('#111827');
});

it('falls back to the light frame for an unknown theme', function (): void {
    $recorder = StripeRecorder::install();

    livewire(UpgradeModal::class)->call('createSession', 'yearly', 'sepia');

    expect($recorder->paramsFor('/checkout/sessions')['branding_settings']['background_color'])
        ->toBe('#ffffff');
});

it('refuses a member who does not own the workspace', function (): void {
    StripeRecorder::install();

    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => 'admin']);
    $this->actingAs($member);

    livewire(UpgradeModal::class)
        ->call('createSession', 'yearly', 'light')
        ->assertReturned(null);
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
        ->call('createSession', 'yearly', 'light')
        ->assertReturned(null);
});

it('refuses an enterprise workspace', function (): void {
    StripeRecorder::install();
    $this->workspace->forceFill(['plan' => Plan::Enterprise])->save();

    livewire(UpgradeModal::class)
        ->call('createSession', 'yearly', 'light')
        ->assertReturned(null);
});

it('refuses when billing is switched off', function (): void {
    StripeRecorder::install();
    Feature::define(BillingFeature::class, false);

    livewire(UpgradeModal::class)
        ->call('createSession', 'yearly', 'light')
        ->assertReturned(null);
});

it('surfaces an error instead of throwing when the interval is unknown', function (): void {
    StripeRecorder::install();

    livewire(UpgradeModal::class)
        ->call('createSession', 'weekly', 'light')
        ->assertReturned(null)
        ->assertSet('error', __('billing.errors.checkout_failed'));
});

it('stops creating sessions after ten attempts', function (): void {
    StripeRecorder::install();

    $component = livewire(UpgradeModal::class);

    foreach (range(1, 10) as $ignored) {
        $component->call('createSession', 'yearly', 'light');
    }

    $component->call('createSession', 'yearly', 'light')->assertReturned(null);
});

it('reports the workspace as activated once the subscription lands', function (): void {
    StripeRecorder::install();

    $component = livewire(UpgradeModal::class);

    expect($component->instance()->activated())->toBeFalse();

    $this->workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test_fake',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_yearly_test',
        'quantity' => 1,
    ]);

    $this->workspace->refresh();

    expect($component->instance()->activated())->toBeTrue();
});
