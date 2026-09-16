<?php

declare(strict_types=1);

use App\Actions\Billing\CreateProCheckout;
use App\Enums\Plan;
use App\Features\Billing as BillingFeature;
use App\Filament\Pages\Billing;
use App\Filament\Pages\Dashboard;
use App\Livewire\App\Billing\UpgradeModal;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Exceptions;
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
    Exceptions::fake();
    StripeRecorder::install();

    livewire(UpgradeModal::class)
        ->call('createSession', 'weekly', 'light')
        ->assertReturned(null)
        ->assertSet('error', __('billing.errors.checkout_failed'));

    Exceptions::assertNotReported(InvalidArgumentException::class);
});

it('reports an unexpected checkout failure instead of swallowing it', function (): void {
    Exceptions::fake();

    app()->bind(CreateProCheckout::class, function (): never {
        throw new RuntimeException('stripe unreachable');
    });

    livewire(UpgradeModal::class)
        ->call('createSession', 'yearly', 'light')
        ->assertReturned(null)
        ->assertSet('error', __('billing.errors.checkout_failed'));

    Exceptions::assertReported(RuntimeException::class);
});

it('reports a missing price configuration instead of blaming the browser', function (): void {
    Exceptions::fake();
    StripeRecorder::install();
    config()->set('services.stripe.prices.pro_yearly', null);

    livewire(UpgradeModal::class)
        ->call('createSession', 'yearly', 'light')
        ->assertReturned(null)
        ->assertSet('error', __('billing.errors.checkout_failed'));

    Exceptions::assertReported(InvalidArgumentException::class);
});

it('clears a previous error once a session is created successfully', function (): void {
    StripeRecorder::install();

    $component = livewire(UpgradeModal::class)
        ->call('createSession', 'weekly', 'light')
        ->assertSet('error', __('billing.errors.checkout_failed'));

    $component->call('createSession', 'yearly', 'light')
        ->assertSet('error', null);
});

it('flips paid when the frame completes', function (): void {
    livewire(UpgradeModal::class)
        ->call('markPaid')
        ->assertSet('paid', true);
});

it('keeps the successful interval on the component', function (): void {
    StripeRecorder::install();

    livewire(UpgradeModal::class)
        ->call('createSession', 'monthly', 'light')
        ->assertSet('interval', 'monthly');
});

it('creates sessions up to the limit then throttles with a wait message', function (): void {
    StripeRecorder::install();
    $this->travelTo(now());

    $component = livewire(UpgradeModal::class);

    foreach (range(1, 10) as $ignored) {
        $component->call('createSession', 'yearly', 'light')
            ->assertReturned('cs_test_fake_secret');
    }

    $component->call('createSession', 'yearly', 'light')
        ->assertReturned(null)
        ->assertSet('error', __('billing.upgrade.rate_limited', ['seconds' => 600]));
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

it('no longer exposes the redirect upgrade method on the billing page', function (): void {
    expect(method_exists(Billing::class, 'upgrade'))->toBeFalse();
});

it('mounts the modal for an owner who can upgrade', function (): void {
    $this->get(Billing::getUrl(panel: 'app', tenant: $this->workspace))
        ->assertOk()
        ->assertSeeLivewire(UpgradeModal::class);
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
