# Upgrade Modal with Embedded Stripe Checkout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: use the `sdd-lean` skill to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a workspace owner subscribe to Pro inside a modal over the app, with no navigation to a Stripe-hosted page.

**Architecture:** One Livewire component mounted at the panel's `BODY_END` render hook owns an `x-filament::modal`. It asks the server for a Stripe Checkout Session client secret and mounts Stripe's embedded frame inside the modal. The session uses `ui_mode: embedded` and `redirect_on_completion: if_required`, which keeps Stripe Managed Payments, promotion codes and the carried trial, and completes in place for card payments.

**Tech Stack:** Laravel 13, Livewire 4, Filament 5, Laravel Cashier 16, stripe-php 21.3.2, Stripe API `2026-08-26.dahlia`, Alpine 3, Pest.

**Spec:** `docs/superpowers/specs/2026-09-16-upgrade-modal-embedded-checkout-design.md`

## Global Constraints

- PostgreSQL only. No SQLite or MySQL compatibility branches.
- Dates are immutable. Never name the mutable `Carbon` class. `tests/Arch/ConventionsTest.php` fails on it.
- No em-dash (U+2014) anywhere in code, copy, lang files, commits or PR text.
- No comments that narrate the diff. No comments in tests.
- All writes go through action classes. `EloquentWriteOutsideActionRule` fails analysis otherwise.
- Every user-facing string goes through `__()`. Two PHPStan rules enforce this.
- Type coverage must stay at 100%. No untyped parameters, returns or closures.
- No new PHPStan ignores.
- Tests live only in `tests/Arch`, `tests/PHPStan`, `tests/Smoke`, `tests/Feature`, `tests/Browser`. There is no `tests/Unit`.
- Do not write isolated unit tests for actions or services. Test through the Livewire component.
- Stripe.js must load from `https://js.stripe.com/dahlia/stripe.js`. Never bundle or self-host it.
- Pre-commit order: `vendor/bin/pint --dirty --format agent`, `vendor/bin/rector --dry-run`, `vendor/bin/phpstan analyse`, `composer test:type-coverage`, targeted `php artisan test --compact --filter=...`.

---

### Task 1: Checkout session returns an embedded client secret

**Files:**
- Create: `tests/Helpers/StripeRecorder.php`
- Modify: `app/Actions/Billing/CreateProCheckout.php`
- Test: `tests/Feature/Billing/BillingCheckoutRedirectTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `CreateProCheckout::execute(Workspace $workspace, string $interval, string $theme = 'light'): string` returning a Checkout Session `client_secret`. `Tests\Helpers\StripeRecorder` with public `array $requests` and `paramsFor(string $urlFragment): array`.

- [ ] **Step 1: Write the recorder helper**

Create `tests/Helpers/StripeRecorder.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Stripe's SDK talks HTTP directly, so the only way to exercise the real
 * checkout path offline is to hand it a client that answers with a canned
 * object. Everything above it (Cashier, the action, the Livewire component)
 * runs for real. It also records every request so a test can assert on the
 * payload Cashier built.
 */
final class StripeRecorder implements ClientInterface
{
    /** @var list<array{url: string, params: array<string, mixed>}> */
    public array $requests = [];

    public static function install(): self
    {
        $recorder = new self;

        ApiRequestor::setHttpClient($recorder);

        return $recorder;
    }

    public static function uninstall(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    /**
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $params
     * @return array{0: string, 1: int, 2: array<string, mixed>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $this->requests[] = ['url' => (string) $absUrl, 'params' => $params];

        $body = match (true) {
            str_contains((string) $absUrl, '/checkout/sessions') => [
                'id' => 'cs_test_fake',
                'object' => 'checkout.session',
                'client_secret' => 'cs_test_fake_secret',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_fake',
            ],
            str_contains((string) $absUrl, '/billing_portal/sessions') => [
                'id' => 'bps_test_fake',
                'object' => 'billing_portal.session',
                'url' => 'https://billing.stripe.com/p/session/test',
            ],
            str_contains((string) $absUrl, '/customers') => [
                'id' => 'cus_test_fake',
                'object' => 'customer',
                'email' => 'billing@example.test',
            ],
            default => ['id' => 'obj_test_fake', 'object' => 'object'],
        };

        return [json_encode($body), 200, []];
    }

    /** @return array<string, mixed> */
    public function paramsFor(string $urlFragment): array
    {
        foreach ($this->requests as $request) {
            if (str_contains($request['url'], $urlFragment)) {
                return $request['params'];
            }
        }

        return [];
    }
}
```

- [ ] **Step 2: Replace the redirect tests with client-secret tests**

In `tests/Feature/Billing/BillingCheckoutRedirectTest.php`, delete the `fakeStripeCheckoutSession()` function and the `it('redirects the owner to the Stripe checkout url when upgrading')` case. Keep the credit-pack and billing-portal cases, switching them to the recorder.

Replace the imports and hooks:

```php
use Tests\Helpers\StripeRecorder;

afterEach(function (): void {
    StripeRecorder::uninstall();
});
```

Rewrite the two surviving cases to install the recorder instead of calling `fakeStripeCheckoutSession(...)`:

```php
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
```

Add the new session-shape cases. These call the action directly because the Livewire component does not exist until Task 2; Task 2 adds component-level coverage on top.

```php
it('builds an embedded checkout session that keeps managed payments', function (): void {
    $recorder = StripeRecorder::install();
    config()->set('services.stripe.managed_payments', true);

    $secret = resolve(CreateProCheckout::class)->execute($this->workspace, 'monthly');

    expect($secret)->toBe('cs_test_fake_secret');

    $params = $recorder->paramsFor('/checkout/sessions');

    expect($params['ui_mode'])->toBe('embedded_page')
        ->and($params['redirect_on_completion'])->toBe('if_required')
        ->and($params['managed_payments'])->toBe(['enabled' => true])
        ->and($params['return_url'])->toContain('checkout=success')
        ->and($params)->not->toHaveKey('success_url')
        ->and($params)->not->toHaveKey('cancel_url');
});

it('carries a running trial into the subscription', function (): void {
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
```

Add `use App\Actions\Billing\CreateProCheckout;` and `use InvalidArgumentException;` to the file's imports, and add `config()->set('services.stripe.prices.pro_yearly', 'price_pro_yearly_test');` to `beforeEach`.

The third case is the one that matters most. A paused workspace keeps a past `trial_ends_at`, and `SubscriptionBuilder::checkout()` clamps any trial it receives to `now + 48h + 10s`. Passing the stale date would hand a free two-day trial to a workspace that already used one.

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=BillingCheckoutRedirectTest`
Expected: FAIL. The four new cases fail because `execute()` still returns a URL and sets `success_url`.

- [ ] **Step 4: Rewrite the action**

Replace the body of `app/Actions/Billing/CreateProCheckout.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Filament\Pages\Billing;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class CreateProCheckout
{
    /** @var list<string> */
    private const array INTERVALS = ['monthly', 'yearly'];

    /**
     * Stripe renders the card fields, so these only colour the frame around
     * them. Values track resources/css/theme.css.
     *
     * @var array<string, array<string, string>>
     */
    private const array BRANDING = [
        'light' => ['background_color' => '#ffffff', 'button_color' => '#7c3aed', 'border_style' => 'rounded'],
        'dark' => ['background_color' => '#131318', 'button_color' => '#7c3aed', 'border_style' => 'rounded'],
    ];

    /**
     * Create the embedded Stripe Checkout session and return the client secret
     * the browser mounts it with. Stripe round-trip, covered by the staging E2E
     * checklist, not unit tests.
     */
    public function execute(Workspace $workspace, string $interval, string $theme = 'light'): string
    {
        $builder = $workspace
            ->newSubscription('default', $this->priceId($interval))
            ->allowPromotionCodes();

        $trialEndsAt = $workspace->onGenericTrial() ? $workspace->trial_ends_at : null;

        $builder = $trialEndsAt instanceof CarbonInterface
            ? $builder->trialUntil($trialEndsAt)
            : $builder->skipTrial();

        return (string) $builder
            ->checkout($this->sessionOptions($workspace, $theme))
            ->asStripeCheckoutSession()
            ->client_secret;
    }

    private function priceId(string $interval): string
    {
        // $interval arrives from the browser, so pin it to the known intervals and
        // an arbitrary string never reaches a config lookup.
        throw_unless(in_array($interval, self::INTERVALS, true), InvalidArgumentException::class, "Unsupported billing interval [{$interval}].");

        $priceId = config("services.stripe.prices.pro_{$interval}");

        throw_if(! is_string($priceId) || $priceId === '', InvalidArgumentException::class, "No Stripe price configured for interval [{$interval}].");

        return $priceId;
    }

    /** @return array<string, mixed> */
    private function sessionOptions(Workspace $workspace, string $theme): array
    {
        $billingUrl = Billing::getUrl(panel: 'app', tenant: $workspace);

        // Mandatory twice over: it is where a redirect-based method lands, and
        // Cashier otherwise defaults it to route('home'), which does not exist here.
        $options = [
            'ui_mode' => 'embedded',
            'redirect_on_completion' => 'if_required',
            'return_url' => "{$billingUrl}?checkout=success",
            'client_reference_id' => (string) $workspace->getKey(),
            'branding_settings' => self::BRANDING[$theme] ?? self::BRANDING['light'],
        ];

        if (config('services.stripe.managed_payments')) {
            $options['managed_payments'] = ['enabled' => true];
        }

        return $options;
    }
}
```

Cashier's `Checkout::create()` recognises `embedded`, suppresses `success_url` and `cancel_url`, and `StripeApiVersions::transformUiMode()` rewrites `embedded` to `embedded_page` on the pinned dahlia API version. That is why the test asserts `embedded_page` while the action passes `embedded`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=BillingCheckoutRedirectTest`
Expected: PASS.

- [ ] **Step 6: Run the gates and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
git add app/Actions/Billing/CreateProCheckout.php tests/Helpers/StripeRecorder.php tests/Feature/Billing/BillingCheckoutRedirectTest.php
git commit -m "feat: return an embedded checkout client secret for pro upgrades"
```

---

### Task 2: The upgrade modal component and its guards

**Files:**
- Create: `app/Livewire/App/Billing/UpgradeModal.php`
- Create: `resources/views/livewire/app/billing/upgrade-modal.blade.php`
- Create: `tests/Feature/Billing/UpgradeModalTest.php`

**Interfaces:**
- Consumes: `CreateProCheckout::execute(Workspace, string $interval, string $theme): string` from Task 1.
- Produces: `App\Livewire\App\Billing\UpgradeModal` with public `string $interval`, public `bool $paid`, public `?string $error`, `const string MODAL_ID = 'upgrade-plan'`, and methods `createSession(string $interval, string $theme): ?string`, `markPaid(): void`, `activated(): bool`, `canUpgrade(): bool`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Billing/UpgradeModalTest.php`:

```php
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

    expect($component->instance()->activated())->toBeTrue();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=UpgradeModalTest`
Expected: FAIL with "Class App\Livewire\App\Billing\UpgradeModal not found".

- [ ] **Step 3: Write the component**

Create `app/Livewire/App/Billing/UpgradeModal.php`:

```php
<?php

declare(strict_types=1);

namespace App\Livewire\App\Billing;

use App\Actions\Billing\CreateProCheckout;
use App\Enums\Plan;
use App\Features\Billing as BillingFeature;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Pennant\Feature;
use Livewire\Component;
use Throwable;

/**
 * Card entry for a Pro upgrade, rendered over the app instead of on a
 * Stripe-hosted page. The frame inside is Stripe's; everything around it,
 * including the billing period, is ours.
 */
final class UpgradeModal extends Component
{
    public const string MODAL_ID = 'upgrade-plan';

    /** Stripe prices a session at creation, so changing this remounts the frame. */
    public string $interval = 'yearly';

    public bool $paid = false;

    public ?string $error = null;

    /**
     * Stripe hosts the card fields, so this covers session-creation looping
     * rather than card testing.
     */
    private const int MAX_SESSIONS = 10;

    private const int DECAY_SECONDS = 600;

    public function createSession(string $interval, string $theme): ?string
    {
        $this->error = null;

        $workspace = $this->workspace();

        if (! $workspace instanceof Workspace || ! $this->canUpgrade()) {
            return null;
        }

        $key = "upgrade-session:{$workspace->getKey()}";

        if (RateLimiter::tooManyAttempts($key, self::MAX_SESSIONS)) {
            $this->error = __('billing.errors.checkout_failed');

            return null;
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        try {
            $secret = resolve(CreateProCheckout::class)->execute($workspace, $interval, $theme);
        } catch (Throwable $exception) {
            report($exception);
            $this->error = __('billing.errors.checkout_failed');

            return null;
        }

        $this->interval = $interval;

        return $secret;
    }

    public function markPaid(): void
    {
        $this->paid = true;
    }

    /**
     * The local subscription row is written by the customer.subscription.created
     * webhook, not by the session, so the modal can outrun it.
     */
    public function activated(): bool
    {
        return $this->workspace()?->subscribed() === true;
    }

    public function canUpgrade(): bool
    {
        $workspace = $this->workspace();
        $user = Filament::auth()->user();

        return Feature::active(BillingFeature::class)
            && $workspace instanceof Workspace
            && $user instanceof User
            && $user->ownsWorkspace($workspace)
            && ! $workspace->subscribed()
            && $workspace->plan !== Plan::Enterprise;
    }

    public function render(): View
    {
        return view('livewire.app.billing.upgrade-modal');
    }

    private function workspace(): ?Workspace
    {
        $workspace = Filament::getTenant();

        return $workspace instanceof Workspace ? $workspace : null;
    }
}
```

- [ ] **Step 4: Add a placeholder view so render() resolves**

Create `resources/views/livewire/app/billing/upgrade-modal.blade.php`. Task 3 fills it in.

```blade
<div></div>
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter=UpgradeModalTest`
Expected: PASS.

- [ ] **Step 6: Run the gates and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
composer test:type-coverage
git add app/Livewire/App/Billing/UpgradeModal.php resources/views/livewire/app/billing/upgrade-modal.blade.php tests/Feature/Billing/UpgradeModalTest.php
git commit -m "feat: add the pro upgrade modal component"
```

---

### Task 3: The modal view, shared activating panel, and Stripe mount

**Files:**
- Create: `resources/views/components/billing/activating.blade.php`
- Modify: `resources/views/livewire/app/billing/upgrade-modal.blade.php`
- Modify: `lang/en/billing.php`
- Modify: `resources/views/filament/pages/billing.blade.php` (the `@if($activating)` block)

**Interfaces:**
- Consumes: `UpgradeModal::MODAL_ID`, `$interval`, `$paid`, `$error`, `createSession()`, `markPaid()`, `activated()`, `canUpgrade()` from Task 2.
- Produces: `<x-billing.activating />` Blade component, rendering the spinner and the 60-second delayed notice.

- [ ] **Step 1: Add the lang keys**

In `lang/en/billing.php`, add to the `upgrade` array:

```php
'modal_heading' => 'Upgrade plan',
'modal_plan_title' => 'Continue with Pro',
'billing_period' => 'Billing period',
'trial_notice' => 'Your card will not be charged until your trial ends on :date',
'paid_title' => 'Payment received',
'close' => 'Close',
```

Add to the `errors` array:

```php
'frame_failed' => 'The payment form could not load. Check your connection or any ad blocker, then try again.',
'retry' => 'Try again',
```

Add to the `manage` array:

```php
'first_charge' => 'First charge on :date',
```

- [ ] **Step 2: Extract the activating panel**

Create `resources/views/components/billing/activating.blade.php`, moving the markup that currently lives in `resources/views/filament/pages/billing.blade.php` under `@if($activating)`:

```blade
@props(['card' => 'rounded-2xl border border-[var(--surface-card-border)] bg-white shadow-sm dark:bg-[var(--surface-card-bg)] dark:shadow-none'])

<div x-data="{ waited: false }" x-init="setTimeout(() => waited = true, 60000)">
    <div class="{{ $card }} flex items-center gap-3 p-5" wire:poll.3s x-show="! waited">
        <x-filament::loading-indicator class="h-5 w-5 text-primary" />
        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('billing.upgrade.activating') }}</span>
    </div>

    <div class="rounded-2xl border border-warning-200 bg-warning-50 p-5 dark:border-warning-400/20 dark:bg-warning-400/[0.06]" x-show="waited" x-cloak>
        <div class="flex gap-3">
            <x-ri-time-line class="mt-0.5 h-5 w-5 shrink-0 text-warning-500 dark:text-warning-400" />
            <div>
                <h3 class="text-sm font-semibold text-warning-800 dark:text-warning-300">{{ __('billing.upgrade.activation_delayed_title') }}</h3>
                <p class="mt-0.5 text-sm text-warning-700/80 dark:text-warning-400/70">{{ __('billing.upgrade.activation_delayed_body') }}</p>
            </div>
        </div>
    </div>
</div>
```

In `resources/views/filament/pages/billing.blade.php`, replace the whole `@if($activating) ... @endif` block with:

```blade
@if($activating)
    <x-billing.activating :card="$card" />
@endif
```

and delete the now-unused `x-data="{ waited: false }" x-init="..."` attributes from the wrapping `<div class="w-full max-w-2xl space-y-5" ...>`.

- [ ] **Step 3: Write the modal view**

Replace `resources/views/livewire/app/billing/upgrade-modal.blade.php`:

```blade
@php
    $workspace = \Filament\Facades\Filament::getTenant();
    $trialEndsAt = $workspace instanceof \App\Models\Workspace && $workspace->onGenericTrial()
        ? $workspace->trial_ends_at
        : null;
@endphp

<div>
    @if($this->canUpgrade() || $paid)
        <x-filament::modal
            :id="\App\Livewire\App\Billing\UpgradeModal::MODAL_ID"
            width="3xl"
            :close-by-clicking-away="false"
            icon="heroicon-o-arrow-up-circle"
        >
            <x-slot name="heading">{{ __('billing.upgrade.modal_heading') }}</x-slot>

            @if($paid)
                <div class="space-y-4">
                    <h3 class="font-display text-lg font-semibold text-gray-900 dark:text-white">
                        {{ __('billing.upgrade.paid_title') }}
                    </h3>

                    @if($this->activated())
                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('billing.manage.auto_renews') }}</p>
                        <x-filament::button x-on:click="window.location.reload()">
                            {{ __('billing.upgrade.close') }}
                        </x-filament::button>
                    @else
                        <x-billing.activating />
                    @endif
                </div>
            @else
                <div class="space-y-5">
                    <div class="flex items-center justify-between gap-4">
                        <h3 class="font-display text-lg font-semibold text-gray-900 dark:text-white">
                            {{ __('billing.upgrade.modal_plan_title') }}
                        </h3>

                        <div class="flex rounded-lg border border-gray-200 p-0.5 dark:border-white/10" role="group" aria-label="{{ __('billing.upgrade.billing_period') }}">
                            @foreach(['yearly' => __('billing.pro_plan.yearly'), 'monthly' => __('billing.pro_plan.monthly')] as $value => $label)
                                <button
                                    type="button"
                                    wire:key="interval-{{ $value }}"
                                    x-on:click="$dispatch('upgrade-interval-changed', { interval: @js($value) })"
                                    @class([
                                        'rounded-md px-3 py-1 text-sm font-medium transition',
                                        'bg-primary-600 text-white' => $interval === $value,
                                        'text-gray-600 dark:text-gray-300' => $interval !== $value,
                                    ])
                                >{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>

                    @if($trialEndsAt !== null)
                        <div class="rounded-xl bg-primary/[0.06] p-3 text-sm text-primary-700 dark:text-primary-300">
                            {{ __('billing.upgrade.trial_notice', ['date' => $trialEndsAt->toFormattedDateString()]) }}
                        </div>
                    @endif

                    @if($error !== null)
                        <div class="rounded-xl bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">
                            {{ $error }}
                        </div>
                    @endif

                    {{-- Alpine removes x-on:...window on teardown; a raw
                         addEventListener would outlive every wire:navigate. --}}
                    <div
                        wire:ignore
                        x-data="upgradeCheckout({
                            publishableKey: @js(config('cashier.key')),
                            modalId: @js(\App\Livewire\App\Billing\UpgradeModal::MODAL_ID),
                        })"
                        x-on:open-modal.window="opened($event)"
                        x-on:close-modal.window="closed($event)"
                        x-on:upgrade-interval-changed.window="intervalChanged($event)"
                    >
                        <div x-ref="frame" class="min-h-96"></div>

                        <div x-show="failed" x-cloak class="rounded-xl bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">
                            <p>{{ __('billing.errors.frame_failed') }}</p>
                            <button type="button" x-on:click="retry()" class="mt-2 font-medium underline">
                                {{ __('billing.errors.retry') }}
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        </x-filament::modal>
    @endif

    @script
    <script>
        Alpine.data('upgradeCheckout', (config) => ({
            checkout: null,
            busy: false,
            restartQueued: false,
            failed: false,
            generation: 0,

            init() {
                this.$watch('$store.theme', () => this.reprice());
            },

            opened(event) {
                // Filament renders modal content eagerly (x-show, not x-if), so
                // mounting on init would open a Stripe session per page load.
                if (event.detail?.id === config.modalId) {
                    this.boot();
                }
            },

            closed(event) {
                // A frame left live while closed would let a theme change, including
                // an unattended OS dark-mode switch, silently buy another session.
                if (event.detail?.id === config.modalId) {
                    this.teardown();
                }
            },

            intervalChanged(event) {
                this.$wire.interval = event.detail.interval;
                this.reprice();
            },

            retry() {
                this.failed = false;
                this.boot();
            },

            async boot() {
                if (this.checkout || this.busy) {
                    return;
                }

                const era = this.generation;

                this.busy = true;

                try {
                    await this.loadStripeJs();

                    if (this.stale(era)) {
                        return;
                    }

                    await this.open(await this.secret(), era);
                } catch (error) {
                    this.fail(error, era);
                } finally {
                    this.busy = false;
                }

                await this.drainQueued();
            },

            loadStripeJs() {
                if (window.Stripe) {
                    return Promise.resolve();
                }

                return new Promise((resolve, reject) => {
                    const script = document.createElement('script');
                    script.id = 'stripe-js';
                    script.src = 'https://js.stripe.com/dahlia/stripe.js';
                    script.onload = resolve;
                    // Dropped on failure so a retry re-adds it. A dead tag left in
                    // place would make every later load await a load event never fired.
                    script.onerror = () => {
                        script.remove();
                        reject(new Error('Stripe.js failed to load.'));
                    };
                    document.head.appendChild(script);
                });
            },

            async secret() {
                const secret = await this.$wire.createSession(
                    this.$wire.interval,
                    Alpine.store('theme'),
                );

                if (! secret) {
                    // The component already rendered why; a second banner would
                    // give the same failure two different explanations.
                    throw Object.assign(new Error('No checkout session.'), { reported: true });
                }

                return secret;
            },

            async open(secret, era) {
                if (this.stale(era)) {
                    return;
                }

                const stripe = window.Stripe(config.publishableKey);

                const checkout = await stripe.createEmbeddedCheckoutPage({
                    fetchClientSecret: () => Promise.resolve(secret),
                    onComplete: () => this.$wire.markPaid(),
                });

                // Closing the modal or navigating away during the round trip must not
                // leave a frame mounted behind it, priced and billable.
                if (this.stale(era) || ! this.$el.isConnected) {
                    checkout.destroy();

                    return;
                }

                this.checkout = checkout;
                this.checkout.mount(this.$refs.frame);
                this.failed = false;
            },

            async reprice() {
                if (this.busy) {
                    this.restartQueued = true;

                    return;
                }

                // Nothing to re-price until the modal has actually been opened.
                if (! this.checkout) {
                    return;
                }

                const era = this.generation;

                this.busy = true;

                try {
                    // The new session is bought before the working frame is discarded,
                    // so a refusal leaves the customer with the one they already had.
                    const secret = await this.secret();

                    if (this.stale(era)) {
                        return;
                    }

                    this.checkout.destroy();
                    this.checkout = null;

                    await this.open(secret, era);
                } catch (error) {
                    this.fail(error, era);
                } finally {
                    this.busy = false;
                }

                await this.drainQueued();
            },

            async drainQueued() {
                if (! this.restartQueued) {
                    return;
                }

                this.restartQueued = false;

                await (this.checkout ? this.reprice() : this.boot());
            },

            stale(era) {
                return era !== this.generation;
            },

            fail(error, era) {
                console.error(error);

                if (this.stale(era) || error?.reported) {
                    return;
                }

                this.failed = this.checkout === null;
            },

            teardown() {
                // Bumped so any in-flight request resolves into a no-op instead of
                // mounting or reporting against a modal the user already closed.
                this.generation++;
                this.checkout?.destroy();
                this.checkout = null;
                this.restartQueued = false;
                this.failed = false;
                this.busy = false;
            },

            destroy() {
                this.teardown();
            },
        }))
    </script>
    @endscript
</div>
```

- [ ] **Step 4: Verify the page still renders**

Run: `php artisan test --compact --filter="BillingPageTest|UpgradeModalTest"`
Expected: PASS. The extracted component must not change what the Billing page renders.

- [ ] **Step 5: Run the gates and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
git add resources/views lang/en/billing.php
git commit -m "feat: render the embedded stripe frame inside the upgrade modal"
```

---

### Task 4: Entry points and removal of the redirect path

**Files:**
- Modify: `app/Providers/Filament/AppPanelProvider.php` (render hooks block)
- Modify: `resources/views/filament/app/sidebar-footer.blade.php` (the billing row)
- Modify: `resources/views/filament/pages/billing.blade.php` (the upgrade card block)
- Modify: `app/Filament/Pages/Billing.php` (delete `upgrade()`)
- Modify: `lang/en/billing.php`
- Modify: `docs/billing.md`
- Test: `tests/Feature/Billing/UpgradeModalTest.php`

**Interfaces:**
- Consumes: `UpgradeModal::MODAL_ID` and `canUpgrade()` from Task 2.
- Produces: nothing new. `App\Filament\Pages\Billing::upgrade()` no longer exists after this task.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Billing/UpgradeModalTest.php`:

```php
it('no longer exposes the redirect upgrade method on the billing page', function (): void {
    expect(method_exists(App\Filament\Pages\Billing::class, 'upgrade'))->toBeFalse();
});

it('mounts the modal for an owner who can upgrade', function (): void {
    $this->get(App\Filament\Pages\Billing::getUrl(panel: 'app', tenant: $this->workspace))
        ->assertOk()
        ->assertSeeLivewire(UpgradeModal::class);
});

it('does not mount the modal for a member who cannot upgrade', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => 'admin']);
    $this->actingAs($member);

    $this->get(App\Filament\Pages\Billing::getUrl(panel: 'app', tenant: $this->workspace))
        ->assertOk()
        ->assertDontSeeLivewire(UpgradeModal::class);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=UpgradeModalTest`
Expected: FAIL. `upgrade()` still exists and the modal is not mounted anywhere.

- [ ] **Step 3: Mount the modal from the panel**

In `app/Providers/Filament/AppPanelProvider.php`, add a render hook next to the existing `BODY_END` one:

```php
/**
 * BODY_END rather than the sidebar footer, because a grandfathered free
 * workspace gets no sidebar prompt yet is still offered an upgrade on the
 * Billing page. Mounting inside the footer would leave it a button with no
 * modal, and keeps a Filament modal out of the collapsible sidebar.
 */
->renderHook(
    PanelsRenderHook::BODY_END,
    function (): View|Factory|string {
        $workspace = Filament::getTenant();
        $user = Filament::auth()->user();

        if (! $workspace instanceof Workspace || ! $user instanceof User || ! $user->ownsWorkspace($workspace)) {
            return '';
        }

        return Blade::render('@livewire(\App\Livewire\App\Billing\UpgradeModal::class)');
    }
)
```

Add `use App\Models\Workspace;`, `use App\Models\User;` and `use Illuminate\Support\Facades\Blade;` if they are not already imported. The ownership check here only avoids mounting the component for people who can never use it; `canUpgrade()` inside the component is the authoritative gate and already renders nothing when it is false.

- [ ] **Step 4: Point the sidebar row at the modal**

In `resources/views/filament/app/sidebar-footer.blade.php`, replace the `<a href="{{ \App\Filament\Pages\Billing::getUrl() }}" class="{{ $rowClasses }} group">` element with the block below. An owner opens the modal; anyone else keeps the link, because the Billing page already explains that only the owner can act. A past-due workspace also keeps the link, because `urgent` means the fix is a card update in the billing portal, not a new subscription.

```blade
@php
    $opensModal = $user->ownsWorkspace($workspace) && ! $billing['urgent'];
@endphp

<div class="mt-2 border-t border-gray-200 pt-2 dark:border-white/10">
    @if($opensModal)
        <button
            type="button"
            class="{{ $rowClasses }} group w-full text-left"
            x-on:click="$dispatch('open-modal', { id: @js(\App\Livewire\App\Billing\UpgradeModal::MODAL_ID) })"
        >
            <x-heroicon-o-arrow-up-circle class="h-5 w-5 flex-shrink-0 text-gray-400 dark:text-gray-500" />
            <span class="flex-1 truncate">{{ $billing['label'] }}</span>
            <span class="flex-shrink-0 rounded-md border text-xs font-medium transition {{ $actionClasses }}">
                {{ $billing['action'] }}
            </span>
        </button>
    @else
        <a href="{{ \App\Filament\Pages\Billing::getUrl() }}" class="{{ $rowClasses }} group">
            <x-heroicon-o-arrow-up-circle class="h-5 w-5 flex-shrink-0 text-gray-400 dark:text-gray-500" />
            <span class="flex-1 truncate">{{ $billing['label'] }}</span>
            <span class="flex-shrink-0 rounded-md border text-xs font-medium transition {{ $actionClasses }}">
                {{ $billing['action'] }}
            </span>
        </a>
    @endif
</div>
```

- [ ] **Step 5: Point the Billing page card at the modal**

In `resources/views/filament/pages/billing.blade.php`, inside the `x-data="{ yearly: true, confirming: false }"` card, delete the whole `x-show="confirming"` confirmation block and the `x-show="! confirming"` wrapper around the buttons. Change the card's `x-data` to `x-data="{ yearly: true }"`. Replace both button handlers so they open the modal:

```blade
@if($trialAvailable)
    <x-filament::button wire:click="startTrial" size="lg" class="w-full justify-center">
        {{ __('billing.trial.start_button') }}
    </x-filament::button>
    <button type="button"
        x-on:click="$dispatch('open-modal', { id: @js(\App\Livewire\App\Billing\UpgradeModal::MODAL_ID) })"
        class="mt-3 w-full text-center text-sm font-medium text-primary-600 transition hover:text-primary-500 dark:text-primary-400">
        {{ __('billing.upgrade.now') }}
    </button>
@else
    <x-filament::button type="button" size="lg" class="w-full justify-center"
        x-on:click="$dispatch('open-modal', { id: @js(\App\Livewire\App\Billing\UpgradeModal::MODAL_ID) })">
        {{ $onTrial
            ? __('billing.subscribe.button')
            : ($isPaused ? __('billing.upgrade.unlock') : __('billing.upgrade.button')) }}
    </x-filament::button>
@endif
```

- [ ] **Step 6: Delete the redirect method and its dead copy**

In `app/Filament/Pages/Billing.php`, delete the whole `upgrade()` method and the now-unused `use App\Actions\Billing\CreateProCheckout;` import. Leave `managePortal()`, `buyCredits()`, `startTrial()` and the `checkout` URL property alone. The `checkout` property still receives a redirect-based payment method returning to `?checkout=success`.

Remove the `confirm_title`, `confirm_body`, `confirm_button` and `confirm_cancel` keys from `lang/en/billing.php`. Then check whether the interval toggle component is still referenced anywhere before deleting it:

```bash
grep -rn "interval-toggle\|billing.upgrade.confirm" app resources packages lang tests
```

Delete `resources/views/components/billing/interval-toggle.blade.php` only if that search returns nothing outside the file itself.

- [ ] **Step 7: Update the architecture doc**

In `docs/billing.md`, change the upgrade line of the flow diagram so it names the modal and the embedded session rather than hosted Checkout. Then update the "Provider" paragraph to record why: Managed Payments is enabled per Checkout Session, and Stripe supports it only on `hosted_page` and `embedded_page`, which is what rules out a custom Payment Element.

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --compact --filter="UpgradeModalTest|BillingPageTest|BillingCheckoutRedirectTest|SidebarBillingStateTest"`
Expected: PASS.

- [ ] **Step 9: Run the gates and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
git add app resources lang docs/billing.md tests
git commit -m "feat: open the upgrade modal from the sidebar and the billing page"
```

---

### Task 5: Billing page tells the truth about a carried trial

**Files:**
- Modify: `app/Filament/Pages/Billing.php` (`getViewData()`)
- Modify: `resources/views/filament/pages/billing.blade.php` (the tagline chain)
- Test: `tests/Feature/Billing/BillingPageTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `getViewData()` gains an `onStripeTrial` boolean key.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Billing/BillingPageTest.php`, matching the `beforeEach` already in that file. Add `config()->set('services.stripe.prices.pro_yearly', 'price_pro_yearly_test');` to it if it is not there.

```php
it('says when the first charge lands instead of claiming the plan renews', function (): void {
    $this->workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test_trialing',
        'stripe_status' => 'trialing',
        'stripe_price' => 'price_pro_yearly_test',
        'quantity' => 1,
        'trial_ends_at' => now()->addDays(9),
    ]);

    livewire(App\Filament\Pages\Billing::class)
        ->assertSee(__('billing.manage.first_charge', ['date' => now()->addDays(9)->toFormattedDateString()]))
        ->assertDontSee(__('billing.manage.auto_renews'));
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=BillingPageTest`
Expected: FAIL. The page renders "Renews automatically".

- [ ] **Step 3: Expose the Stripe trial**

In `app/Filament/Pages/Billing.php`, inside `getViewData()`, add to the returned array:

```php
'onStripeTrial' => $subscription?->onTrial() ?? false,
```

- [ ] **Step 4: Use it in the tagline**

In `resources/views/filament/pages/billing.blade.php`, in the tagline chain, insert a branch directly above `@elseif($isSubscribed)`:

```blade
@elseif($onStripeTrial)
    {{ __('billing.manage.first_charge', ['date' => $subscription?->trial_ends_at?->toFormattedDateString()]) }}
```

Cashier's webhook nulls `workspaces.trial_ends_at` when a subscription is created, so `onGenericTrial()` goes false while the Stripe subscription still sits in `trialing`. Without this branch the page claims the plan renews before any money has moved.

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter=BillingPageTest`
Expected: PASS.

- [ ] **Step 6: Run the gates and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
composer test:type-coverage
git add app/Filament/Pages/Billing.php resources/views/filament/pages/billing.blade.php tests/Feature/Billing/BillingPageTest.php
git commit -m "fix: say when the first charge lands on a carried trial"
```

---

### Task 6: Browser verification and full gate

**Files:** none changed unless verification finds a defect.

**Interfaces:**
- Consumes: everything from Tasks 1 through 5.
- Produces: screenshots and a verified upgrade walk.

- [ ] **Step 1: Give the test Pro product a tax code**

Managed Payments rejects a session whose product has no tax code, with "the product tax code is missing". The real test-mode Pro product carries `tax_code: NULL`, so the production-shaped path cannot be walked locally without this. Confirm with the user before changing their Stripe test data, then:

```bash
php artisan tinker --execute '
$stripe = new \Stripe\StripeClient(config("cashier.secret"));
foreach (["pro_monthly", "pro_yearly"] as $key) {
    $price = $stripe->prices->retrieve(config("services.stripe.prices.{$key}"), ["expand" => ["product"]]);
    $stripe->products->update($price->product->id, ["tax_code" => "txcd_10103000"]);
    echo "{$key}: {$price->product->id} tagged\n";
}'
```

`txcd_10103000` is "Software as a service (SaaS), business use".

- [ ] **Step 2: Switch local billing to the production shape**

In `.env`, set `STRIPE_MANAGED_PAYMENTS=true`, then `php artisan config:clear`. Start Horizon and confirm `QUEUE_CONNECTION=redis`, so the `customer.subscription.created` webhook is processed the way production processes it. Sync-queue testing hides exactly the ordering bugs this flow can have.

Forward webhooks:

```bash
stripe listen --forward-to https://relaticle.test/stripe/webhook
```

- [ ] **Step 3: Build assets**

```bash
pnpm run build
```

- [ ] **Step 4: Walk the upgrade in the browser**

Use the `agent-browser-relaticle` skill. Sign in as a workspace owner on a trialing workspace. From the sidebar, click the "Keep Pro" row and confirm the modal opens over the app with no navigation.

Check each of these and screenshot:

1. Light mode, modal open, Stripe frame mounted.
2. Dark mode, toggled while the modal is open, frame remounts with the dark background.
3. Billing period toggled from yearly to monthly, frame remounts and the price changes.
4. The trial notice names the workspace's real trial end date.
5. Card `4242424242424242` completes, the modal switches to the paid state, the activating panel appears and then clears.
6. The sidebar row disappears once the subscription lands.
7. Mobile viewport, modal open.

Then repeat the payment step on a fresh trialing workspace with:

- `4000002500003155` (3DS). Stripe's challenge must resolve inside the frame.
- `4000000000009995` (decline). The error must render inside the frame and the modal must stay open and retryable.

- [ ] **Step 5: Confirm the trial was carried, not charged**

```bash
php artisan tinker --execute '
$w = App\Models\Workspace::query()->latest("id")->first();
$s = $w->subscription();
echo "status={$s->stripe_status} trial_ends_at={$s->trial_ends_at} plan={$w->plan->value}\n";'
```

Expected: `status=trialing`, `trial_ends_at` matching the workspace's original trial end (or 48 hours out if it was closer than that), `plan=pro`.

- [ ] **Step 6: Confirm a paused workspace is charged immediately**

Repeat the walk on a workspace whose `trial_ends_at` is in the past. Expected: `status=active` and a real charge in the Stripe test dashboard. This is the case that would silently hand out a free trial if Task 1's branch regressed.

- [ ] **Step 7: Run the full suite**

```bash
composer test:lint
composer test:pest:full
```

Expected: PASS. `composer test:pest` alone is not sufficient here, because TIA replays cached passes and cannot see the changed Stripe payloads.

- [ ] **Step 8: Restore local config**

Put `STRIPE_MANAGED_PAYMENTS` back to whatever the user wants locally. `.env` is not committed either way. Commit any fixes verification produced.

---

## Self-review

**Spec coverage.** Entry points, Task 4. Server session creation, Task 1. Client mount and theme, Task 3. Completion and the shared activating panel, Task 3. Billing period toggle, Task 3. Trial carry, Task 1. Billing page copy fix, Task 5. Guards and rate limit, Task 2. Docs, Task 4 step 7. Local prerequisites, Task 6 steps 1 and 2. Testing, Tasks 1, 2, 4, 5 and 6.

**Naming consistency.** `MODAL_ID` is referenced identically in Tasks 3 and 4. `createSession(string $interval, string $theme): ?string` has the same signature in Tasks 2 and 3. `markPaid()` is the only name used for the completion handler, and the component property is `paid` everywhere. `canUpgrade()` is the authoritative gate in both the view (Task 3) and the render hook (Task 4).

**Known gap.** The spec notes the public pricing page still hardcodes $19 and $24. That is out of scope and no task touches it.
