# Workspace Billing (Cashier + Managed Payments) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rework the Polar billing foundation (PR #334) onto Laravel Cashier + Stripe Managed Payments and ship the full self-serve billing surface (trial, billing page, checkout, pricing page, sysadmin) dark behind a feature flag.

**Architecture:** Team is the Cashier billable model; Stripe webhooks flow through Cashier's stock controller into a `WebhookHandled` listener that maps Stripe price ids to `App\Enums\Plan` and grants AI-credit allowances via the existing `CreditService`. Managed Payments (merchant of record) is enabled per checkout session through one config switch (`services.stripe.managed_payments`) — the future DIY-tax off-ramp. Trials are app-side Cashier generic trials (`teams.trial_ends_at`), no card, one per user.

**Tech Stack:** Laravel 13, laravel/cashier ^16.5 (Stripe), Filament v5 (app panel page + sysadmin resource), Pennant (feature flag), Pest (TDD), PostgreSQL.

**Spec:** `docs/superpowers/specs/2026-06-12-workspace-billing-design.md` — read it first.

**Branch:** all work on `feat/billing-foundation` (PR #334, unmerged — history may be reworked freely).

**Conventions that apply to every task** (from CLAUDE.md — do not skip):
- Migrations: `up()` only, `declare(strict_types=1)`, typed closures.
- After each task: `vendor/bin/pint --dirty --format agent`, then targeted `php artisan test --compact ...`.
- Before the final push (Task 11): `vendor/bin/rector --dry-run`, `vendor/bin/phpstan analyse`, `php -d memory_limit=2G vendor/bin/pest --type-coverage --min=100`.
- Commit messages: conventional, lowercase, ≤72-char subject. NO AI attribution of any kind.
- If a Bash test run dies with "Allowed memory size exhausted", rerun via `php -d memory_limit=2G vendor/bin/pest --compact <paths>`.

**Stripe-boundary testing policy (applies to Tasks 4, 7, 8):** outbound Stripe calls (checkout session creation, billing portal, subscription cancel) are NOT mocked — the repo forbids isolated unit tests and the classes are `final`. Instead: pure option-builder methods get full test coverage, guards/authorization get entry-point tests that return before any Stripe call, and the real round-trips are covered by the staging E2E checklist in `docs/billing.md` (test-mode keys). Webhook *inbound* behavior (where the money correctness lives) is fully covered through the real HTTP endpoint.

---

### Task 1: Swap Polar → Cashier (packages, config, wiring)

**Files:**
- Modify: `composer.json` (via composer commands)
- Delete: `database/migrations/2026_06_12_130000_create_polar_customers_table.php`, `2026_06_12_130001_create_polar_subscriptions_table.php`, `2026_06_12_130002_create_polar_orders_table.php`, `2026_06_12_130003_create_webhook_calls_table.php`
- Modify: `app/Models/Team.php` (trait swap)
- Modify: `app/Providers/AppServiceProvider.php` (imports, listener block, `register()`)
- Modify: `config/services.php` (polar block → stripe block)
- Modify: `.env.example` (Polar block → Stripe block)
- Modify: `bootstrap/app.php` (CSRF exception for `stripe/*`)

- [ ] **Step 1: Swap composer packages**

```bash
composer remove danestves/laravel-polar --no-interaction
composer require laravel/cashier --no-interaction
```

Expected: `danestves/laravel-polar` (and its `spatie/laravel-webhook-client`, `polar-sh/sdk` deps) removed; `laravel/cashier` ^16.5 installed.

- [ ] **Step 2: Delete the four Polar migrations and drop local tables**

```bash
rm database/migrations/2026_06_12_13000*_create_polar_*.php database/migrations/2026_06_12_130003_create_webhook_calls_table.php
php artisan tinker --execute 'Illuminate\Support\Facades\Schema::dropIfExists("polar_subscriptions"); Illuminate\Support\Facades\Schema::dropIfExists("polar_orders"); Illuminate\Support\Facades\Schema::dropIfExists("polar_customers"); Illuminate\Support\Facades\Schema::dropIfExists("webhook_calls"); Illuminate\Support\Facades\DB::table("migrations")->where("migration", "like", "2026_06_12_1300%")->delete(); echo "dropped";'
```

Expected: `dropped`. (Branch is unmerged — these tables never reached any shared environment.)

- [ ] **Step 3: Swap the Billable trait on Team**

In `app/Models/Team.php` replace the import `use Danestves\LaravelPolar\Billable;` with `use Laravel\Cashier\Billable;`. The trait usage block stays:

```php
final class Team extends JetstreamTeam implements HasAvatar
{
    use Billable;

    /** @use HasFactory<TeamFactory> */
    use HasFactory;
    use HasSlug;
    use HasUlids;
```

- [ ] **Step 4: Rewire AppServiceProvider**

In `app/Providers/AppServiceProvider.php`:

Remove the five Polar imports (`Danestves\LaravelPolar\Events\Subscription*`) and the five `Event::listen(Subscription...)` lines added by the Polar foundation. Add imports:

```php
use App\Listeners\Billing\SyncPlanOnStripeSubscriptionChange;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;
```

In the listener block (next to the existing `Event::listen(TeamCreated::class, ...)` lines):

```php
Event::listen(WebhookHandled::class, SyncPlanOnStripeSubscriptionChange::class);
```

In `register()` (Cashier billable model + past-due policy — past-due must keep the plan until Stripe gives up, per spec §5):

```php
Cashier::useCustomerModel(Team::class);
Cashier::keepPastDueSubscriptionsActive();
```

The listener class doesn't exist yet — Task 3 creates it. PHP won't autoload-fail until the event fires, but `php artisan config:clear && php artisan optimize:clear` then `composer dump-autoload` must run clean. (If you prefer strict ordering, create an empty listener now and fill it in Task 3 — acceptable.)

- [ ] **Step 5: Replace the services config block**

In `config/services.php`, replace the `'polar' => [...]` block with:

```php
'stripe' => [
    'managed_payments' => (bool) env('STRIPE_MANAGED_PAYMENTS', true),
    'prices' => [
        'pro_monthly' => env('STRIPE_PRICE_PRO_MONTHLY'),
        'pro_yearly' => env('STRIPE_PRICE_PRO_YEARLY'),
    ],
],
```

(Plan mapping rule: any configured `pro_*` price id resolves to `Plan::Pro`. Enterprise has no self-serve price by design.)

- [ ] **Step 6: Replace the .env.example block**

Replace the Polar block at the end of `.env.example` with:

```dotenv
# Stripe billing (test-mode keys for local dev). Cashier reads STRIPE_KEY/SECRET/WEBHOOK_SECRET.
STRIPE_KEY=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
STRIPE_MANAGED_PAYMENTS=true
STRIPE_PRICE_PRO_MONTHLY=
STRIPE_PRICE_PRO_YEARLY=

# Billing feature flag — keep false in production until the Stripe org is live
RELATICLE_FEATURE_BILLING=false
```

- [ ] **Step 7: CSRF exception for the Cashier webhook route**

In `bootstrap/app.php`, inside `->withMiddleware(function (Middleware $middleware): void {` add:

```php
$middleware->validateCsrfTokens(except: [
    'stripe/*',
]);
```

- [ ] **Step 8: Delete the Polar test suite (reworked in Task 3)**

```bash
rm tests/Feature/Billing/PolarWebhookTest.php
```

- [ ] **Step 9: Sanity + style + commit**

```bash
php artisan route:list --path=stripe
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/FeatureFlagsTest.php
git add -A && git commit -m "refactor(billing): swap polar foundation to laravel cashier"
```

Expected: route list shows `POST stripe/webhook`; tests pass.

---

### Task 2: Cashier tables for the Team billable (ULID keys)

**Files:**
- Create: `database/migrations/2026_06_12_140000_add_cashier_columns_to_teams.php`
- Create: `database/migrations/2026_06_12_140001_create_subscriptions_table.php`
- Create: `database/migrations/2026_06_12_140002_create_subscription_items_table.php`
- Test: `tests/Feature/Migrations/CashierTablesTest.php`

Cashier's published migrations assume a `users` table with bigint keys. Ours bill **teams with ULID keys** — write the three migrations by hand. Cashier's `Subscription::owner()` derives the foreign key from the customer model (`(new Team)->getForeignKey()` → `team_id`), so the column MUST be named `team_id`.

- [ ] **Step 1: Write a failing schema test**

`tests/Feature/Migrations/CashierTablesTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('adds cashier customer columns to teams', function (): void {
    expect(Schema::hasColumns('teams', ['stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at']))->toBeTrue();
});

it('creates cashier subscription tables keyed by team ulid', function (): void {
    expect(Schema::hasColumns('subscriptions', ['team_id', 'type', 'stripe_id', 'stripe_status', 'stripe_price', 'quantity', 'trial_ends_at', 'ends_at']))->toBeTrue()
        ->and(Schema::hasColumns('subscription_items', ['subscription_id', 'stripe_id', 'stripe_product', 'stripe_price', 'quantity']))->toBeTrue()
        ->and(Schema::getColumnType('subscriptions', 'team_id'))->toBe('char');
});
```

- [ ] **Step 2: Run it to make sure it fails**

```bash
php artisan test --compact tests/Feature/Migrations/CashierTablesTest.php
```

Expected: FAIL (columns missing).

- [ ] **Step 3: Write the three migrations**

`database/migrations/2026_06_12_140000_add_cashier_columns_to_teams.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
        });
    }
};
```

`database/migrations/2026_06_12_140001_create_subscriptions_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('team_id');
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'stripe_status']);
        });
    }
};
```

`database/migrations/2026_06_12_140002_create_subscription_items_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id');
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->integer('quantity')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'stripe_price']);
        });
    }
};
```

- [ ] **Step 4: Migrate and re-run the test**

```bash
php artisan migrate --no-interaction
php artisan test --compact tests/Feature/Migrations/CashierTablesTest.php
```

Expected: PASS. (Postgres `char(26)` for ULID reports type `char` — if the assertion fails on driver naming, relax that one expectation to `toContain('char')`.)

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(billing): add cashier tables keyed to team ulids"
```

---

### Task 3: Plan sync rework — webhooks through Cashier (TDD)

**Files:**
- Create: `tests/Feature/Billing/StripeWebhookTest.php`
- Modify: `app/Actions/Billing/SyncTeamPlanFromSubscription.php` (full rewrite shown)
- Create: `app/Listeners/Billing/SyncPlanOnStripeSubscriptionChange.php`
- Delete: `app/Listeners/Billing/SyncPlanOnPolarSubscriptionChange.php`

Cashier's stock `WebhookController` (route `POST /stripe/webhook`, signature-verified) maintains the `subscriptions`/`subscription_items` tables, then fires `Laravel\Cashier\Events\WebhookHandled` with the raw payload array. Our listener reacts to `customer.subscription.*` types only.

- [ ] **Step 1: Write the failing webhook test suite**

`tests/Feature/Billing/StripeWebhookTest.php` — the eight scenarios from the spec, through the real endpoint with real computed signatures:

```php
<?php

declare(strict_types=1);

use App\Actions\Billing\SyncTeamPlanFromSubscription;
use App\Enums\Plan;
use App\Listeners\Billing\SyncPlanOnStripeSubscriptionChange;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Subscription;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;

mutates(SyncTeamPlanFromSubscription::class);
mutates(SyncPlanOnStripeSubscriptionChange::class);

beforeEach(function (): void {
    config()->set('cashier.webhook.secret', 'whsec_test_secret');
    config()->set('services.stripe.prices.pro_monthly', 'price_pro_monthly_test');
    config()->set('services.stripe.prices.pro_yearly', 'price_pro_yearly_test');
});

function sendStripeWebhook(array $payload, string $secret = 'whsec_test_secret'): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    return test()->call('POST', '/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stripeSubscriptionEvent(Team $team, string $event, array $overrides = []): array
{
    $price = $overrides['price'] ?? 'price_pro_monthly_test';

    $object = array_merge([
        'id' => 'sub_test_1',
        'object' => 'subscription',
        'customer' => $team->stripe_id,
        'status' => 'active',
        'cancel_at_period_end' => false,
        'current_period_end' => now()->addMonth()->getTimestamp(),
        'trial_end' => null,
        'ended_at' => null,
        'metadata' => ['type' => 'default'],
        'items' => [
            'object' => 'list',
            'data' => [
                [
                    'id' => 'si_test_1',
                    'object' => 'subscription_item',
                    'price' => [
                        'id' => $price,
                        'object' => 'price',
                        'product' => 'prod_pro_test',
                    ],
                    'quantity' => 1,
                ],
            ],
        ],
    ], $overrides);

    unset($object['price']);

    return [
        'id' => 'evt_'.Str::ulid(),
        'object' => 'event',
        'type' => "customer.subscription.{$event}",
        'data' => ['object' => $object],
    ];
}

function stripeBillingTeam(): Team
{
    /** @var Team $team */
    $team = User::factory()->withPersonalTeam()->create()->currentTeam;
    $team->forceFill(['stripe_id' => 'cus_'.Str::ulid()])->save();

    return $team;
}

it('keeps the team on Free while the subscription is incomplete', function (): void {
    $team = stripeBillingTeam();

    sendStripeWebhook(stripeSubscriptionEvent($team, 'created', ['status' => 'incomplete']))->assertSuccessful();

    expect($team->refresh()->plan)->toBe(Plan::Free)
        ->and(Subscription::query()->where('stripe_id', 'sub_test_1')->exists())->toBeTrue();
});

it('upgrades the team to Pro and grants the allowance when the subscription activates', function (): void {
    $team = stripeBillingTeam();

    sendStripeWebhook(stripeSubscriptionEvent($team, 'created', ['status' => 'incomplete']))->assertSuccessful();
    sendStripeWebhook(stripeSubscriptionEvent($team, 'updated'))->assertSuccessful();

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();

    expect($team->refresh()->plan)->toBe(Plan::Pro)
        ->and($balance->credits_remaining)->toBe(Plan::Pro->credits())
        ->and($balance->credits_used)->toBe(0);
});

it('does not re-reset usage when the same webhook is replayed', function (): void {
    $team = stripeBillingTeam();

    sendStripeWebhook(stripeSubscriptionEvent($team, 'created'))->assertSuccessful();

    AiCreditBalance::query()->where('team_id', $team->getKey())->update([
        'credits_remaining' => Plan::Pro->credits() - 5,
        'credits_used' => 5,
    ]);

    sendStripeWebhook(stripeSubscriptionEvent($team, 'updated'))->assertSuccessful();

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();

    expect($team->refresh()->plan)->toBe(Plan::Pro)
        ->and($balance->credits_remaining)->toBe(Plan::Pro->credits() - 5)
        ->and($balance->credits_used)->toBe(5);
});

it('keeps Pro pricing when the subscription switches between pro prices', function (): void {
    $team = stripeBillingTeam();

    sendStripeWebhook(stripeSubscriptionEvent($team, 'created'))->assertSuccessful();
    sendStripeWebhook(stripeSubscriptionEvent($team, 'updated', ['price' => 'price_pro_yearly_test']))->assertSuccessful();

    expect($team->refresh()->plan)->toBe(Plan::Pro)
        ->and(Subscription::query()->where('stripe_id', 'sub_test_1')->value('stripe_price'))->toBe('price_pro_yearly_test');
});

it('downgrades the team to Free when the subscription is deleted', function (): void {
    $team = stripeBillingTeam();

    sendStripeWebhook(stripeSubscriptionEvent($team, 'created'))->assertSuccessful();
    sendStripeWebhook(stripeSubscriptionEvent($team, 'deleted', [
        'status' => 'canceled',
        'ended_at' => now()->getTimestamp(),
    ]))->assertSuccessful();

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();

    expect($team->refresh()->plan)->toBe(Plan::Free)
        ->and($balance->credits_remaining)->toBe(Plan::Free->credits());
});

it('preserves a sysadmin-granted plan when an unrelated subscription ends', function (): void {
    $team = stripeBillingTeam();

    sendStripeWebhook(stripeSubscriptionEvent($team, 'created'))->assertSuccessful();

    $team->refresh();
    $team->plan = Plan::Enterprise;
    $team->save();
    app(CreditService::class)->resetPeriod($team);

    sendStripeWebhook(stripeSubscriptionEvent($team, 'deleted', [
        'status' => 'canceled',
        'ended_at' => now()->getTimestamp(),
    ]))->assertSuccessful();

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();

    expect($team->refresh()->plan)->toBe(Plan::Enterprise)
        ->and($balance->credits_remaining)->toBe(Plan::Enterprise->credits());
});

it('leaves the plan untouched for a price that maps to no plan', function (): void {
    $team = stripeBillingTeam();

    sendStripeWebhook(stripeSubscriptionEvent($team, 'created', ['price' => 'price_unknown']))->assertSuccessful();

    expect($team->refresh()->plan)->toBe(Plan::Free);
});

it('rejects a webhook with an invalid signature', function (): void {
    $team = stripeBillingTeam();

    $response = sendStripeWebhook(stripeSubscriptionEvent($team, 'created'), secret: 'whsec_wrong');

    expect($response->status())->toBeGreaterThanOrEqual(400)
        ->and(Subscription::query()->where('stripe_id', 'sub_test_1')->exists())->toBeFalse()
        ->and($team->refresh()->plan)->toBe(Plan::Free);
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --compact tests/Feature/Billing/StripeWebhookTest.php
```

Expected: FAIL — `SyncPlanOnStripeSubscriptionChange` doesn't exist / plans never change.

- [ ] **Step 3: Rewrite the sync action**

Replace the body of `app/Actions/Billing/SyncTeamPlanFromSubscription.php` entirely:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Plan;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Subscription;
use Relaticle\Chat\Services\CreditService;

final readonly class SyncTeamPlanFromSubscription
{
    public function __construct(private CreditService $credits) {}

    public function handle(Team $team, Subscription $subscription): void
    {
        $subscriptionPlan = $this->planForPrice($subscription->stripe_price);

        if (! $subscriptionPlan instanceof Plan) {
            Log::warning('Stripe subscription price is not mapped to a plan', [
                'team_id' => $team->getKey(),
                'subscription_id' => $subscription->stripe_id,
                'stripe_price' => $subscription->stripe_price,
            ]);

            return;
        }

        $target = $this->targetPlan($team, $subscription, $subscriptionPlan);

        if (! $target instanceof Plan) {
            return;
        }

        if ($team->plan === $target) {
            return;
        }

        DB::transaction(function () use ($team, $target, $subscription): void {
            $team->plan = $target;
            $team->save();

            $this->credits->resetPeriod($team);

            Log::info('Team plan synced from Stripe subscription', [
                'team_id' => $team->getKey(),
                'plan' => $target->value,
                'subscription_id' => $subscription->stripe_id,
            ]);
        });
    }

    private function targetPlan(Team $team, Subscription $subscription, Plan $subscriptionPlan): ?Plan
    {
        if ($subscription->valid()) {
            return $subscriptionPlan;
        }

        // Only downgrade a plan this subscription granted — a sysadmin-assigned
        // plan (e.g. Enterprise) must survive an unrelated subscription ending.
        if ($team->plan === $subscriptionPlan) {
            return Plan::default();
        }

        return null;
    }

    private function planForPrice(?string $priceId): ?Plan
    {
        if ($priceId === null) {
            return null;
        }

        /** @var array<string, string|null> $prices */
        $prices = config('services.stripe.prices', []);

        foreach ($prices as $key => $mappedPriceId) {
            if ($mappedPriceId !== null && $mappedPriceId === $priceId) {
                return Plan::tryFrom(explode('_', $key)[0]);
            }
        }

        return null;
    }
}
```

(`pro_monthly`/`pro_yearly` → `explode('_')[0]` → `'pro'` → `Plan::Pro`. A future `enterprise_monthly` key would resolve the same way.)

- [ ] **Step 4: Create the listener, delete the Polar one**

`app/Listeners/Billing/SyncPlanOnStripeSubscriptionChange.php`:

```php
<?php

declare(strict_types=1);

namespace App\Listeners\Billing;

use App\Actions\Billing\SyncTeamPlanFromSubscription;
use App\Models\Team;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Subscription;

final readonly class SyncPlanOnStripeSubscriptionChange
{
    public function __construct(private SyncTeamPlanFromSubscription $syncTeamPlan) {}

    public function handle(WebhookHandled $event): void
    {
        $type = $event->payload['type'] ?? '';

        if (! str_starts_with((string) $type, 'customer.subscription.')) {
            return;
        }

        $stripeId = $event->payload['data']['object']['id'] ?? null;

        if (! is_string($stripeId)) {
            return;
        }

        $subscription = Subscription::query()->firstWhere('stripe_id', $stripeId);

        if (! $subscription instanceof Subscription) {
            return;
        }

        $team = $subscription->owner;

        if (! $team instanceof Team) {
            return;
        }

        $this->syncTeamPlan->handle($team, $subscription);
    }
}
```

```bash
rm app/Listeners/Billing/SyncPlanOnPolarSubscriptionChange.php
```

- [ ] **Step 5: Run the suite until green**

```bash
php artisan test --compact tests/Feature/Billing/StripeWebhookTest.php
```

Expected: 8 passed. Debug notes if not: (a) Cashier resolves the billable via `teams.stripe_id` — the helper sets it; (b) Cashier's controller responds 200 even for unhandled types; (c) invalid signature → 403.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(billing): sync team plans from stripe subscription webhooks"
```

---

### Task 4: Checkout + portal actions (Managed Payments switch)

**Files:**
- Create: `app/Actions/Billing/CreateProCheckout.php`
- Create: `tests/Feature/Billing/CheckoutOptionsTest.php`

- [ ] **Step 1: Write the failing options-builder test**

`tests/Feature/Billing/CheckoutOptionsTest.php` (the pure, fully-testable part — the Stripe round-trip itself is staging-E2E per the testing policy):

```php
<?php

declare(strict_types=1);

use App\Actions\Billing\CreateProCheckout;
use App\Models\Team;
use App\Models\User;

mutates(CreateProCheckout::class);

beforeEach(function (): void {
    config()->set('services.stripe.prices.pro_monthly', 'price_pro_monthly_test');
    config()->set('services.stripe.prices.pro_yearly', 'price_pro_yearly_test');
});

function checkoutTeam(): Team
{
    /** @var Team $team */
    $team = User::factory()->withPersonalTeam()->create()->currentTeam;

    return $team;
}

it('builds monthly checkout options with managed payments enabled', function (): void {
    config()->set('services.stripe.managed_payments', true);

    $action = app(CreateProCheckout::class);

    expect($action->priceId('monthly'))->toBe('price_pro_monthly_test')
        ->and($action->sessionOptions(checkoutTeam()))->toHaveKey('managed_payments.enabled', true)
        ->and($action->sessionOptions(checkoutTeam()))->toHaveKey('allow_promotion_codes', true);
});

it('omits managed payments when the switch is off', function (): void {
    config()->set('services.stripe.managed_payments', false);

    $options = app(CreateProCheckout::class)->sessionOptions(checkoutTeam());

    expect($options)->not->toHaveKey('managed_payments');
});

it('selects the yearly price for the yearly interval', function (): void {
    expect(app(CreateProCheckout::class)->priceId('yearly'))->toBe('price_pro_yearly_test');
});

it('points success and cancel urls at the team billing page', function (): void {
    $team = checkoutTeam();

    $options = app(CreateProCheckout::class)->sessionOptions($team);

    expect($options['success_url'])->toContain("/app/{$team->slug}/billing")
        ->and($options['success_url'])->toContain('checkout=success')
        ->and($options['cancel_url'])->toContain("/app/{$team->slug}/billing");
});
```

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test --compact tests/Feature/Billing/CheckoutOptionsTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement the action**

`app/Actions/Billing/CreateProCheckout.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Team;
use InvalidArgumentException;
use Laravel\Cashier\Checkout;

final readonly class CreateProCheckout
{
    /**
     * Create the hosted Stripe Checkout session and return its redirect URL.
     * Stripe round-trip — covered by the staging E2E checklist, not unit tests.
     */
    public function handle(Team $team, string $interval): string
    {
        $checkout = $team
            ->newSubscription('default', $this->priceId($interval))
            ->allowPromotionCodes()
            ->checkout($this->sessionOptions($team));

        /** @var Checkout $checkout */
        return (string) $checkout->url;
    }

    public function priceId(string $interval): string
    {
        $priceId = config("services.stripe.prices.pro_{$interval}");

        if (! is_string($priceId) || $priceId === '') {
            throw new InvalidArgumentException("No Stripe price configured for interval [{$interval}].");
        }

        return $priceId;
    }

    /** @return array<string, mixed> */
    public function sessionOptions(Team $team): array
    {
        $billingUrl = url("/app/{$team->slug}/billing");

        $options = [
            'success_url' => "{$billingUrl}?checkout=success",
            'cancel_url' => $billingUrl,
            'allow_promotion_codes' => true,
        ];

        if (config('services.stripe.managed_payments')) {
            $options['managed_payments'] = ['enabled' => true];
        }

        return $options;
    }
}
```

(Portal redirects need no action class — Cashier's `$team->redirectToBillingPortal($returnUrl)` is called directly from the page in Task 7. **Sandbox spike, runbook item:** first staging run confirms Stripe accepts `managed_payments[enabled]` via Cashier session options; if the SDK strips unknown keys, swap `handle()` to a direct `\Stripe\StripeClient->checkout->sessions->create([...])` call using the same `sessionOptions()` + `line_items` — the public surface of this class doesn't change.)

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact tests/Feature/Billing/CheckoutOptionsTest.php
```

Expected: 4 passed.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(billing): pro checkout action with managed payments switch"
```

---

### Task 5: Trial engine — start action, daily command, T-3 email (TDD)

**Files:**
- Create: `database/migrations/2026_06_12_140003_add_pro_trial_used_at_to_users.php`
- Create: `app/Actions/Billing/StartProTrial.php`
- Create: `app/Console/Commands/ProcessTrialsCommand.php`
- Create: `app/Mail/ProTrialEndingSoonMail.php`
- Create: `resources/views/mail/pro-trial-ending-soon.blade.php`
- Modify: `bootstrap/app.php` (schedule)
- Test: `tests/Feature/Billing/TrialLifecycleTest.php`

- [ ] **Step 1: Migration**

`database/migrations/2026_06_12_140003_add_pro_trial_used_at_to_users.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('pro_trial_used_at')->nullable();
        });
    }
};
```

```bash
php artisan migrate --no-interaction
```

Also add the cast + docblock property to `app/Models/User.php`: in `casts()` add `'pro_trial_used_at' => 'datetime',` and in the class docblock add `* @property Carbon|null $pro_trial_used_at`.

- [ ] **Step 2: Write the failing trial lifecycle tests**

`tests/Feature/Billing/TrialLifecycleTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\Billing\StartProTrial;
use App\Enums\Plan;
use App\Mail\ProTrialEndingSoonMail;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Relaticle\Chat\Models\AiCreditBalance;

mutates(StartProTrial::class);
mutates(App\Console\Commands\ProcessTrialsCommand::class);

function trialOwnerAndTeam(): array
{
    $user = User::factory()->withPersonalTeam()->create();

    /** @var Team $team */
    $team = $user->currentTeam;

    return [$user, $team];
}

it('starts a pro trial for an eligible owner', function (): void {
    [$user, $team] = trialOwnerAndTeam();

    app(StartProTrial::class)->handle($user, $team);

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();

    expect($team->refresh()->plan)->toBe(Plan::Pro)
        ->and($team->trial_ends_at?->isSameDay(now()->addDays(14)))->toBeTrue()
        ->and($user->refresh()->pro_trial_used_at)->not->toBeNull()
        ->and($balance->credits_remaining)->toBe(Plan::Pro->credits());
});

it('refuses a second trial for the same user even on another team', function (): void {
    [$user, $team] = trialOwnerAndTeam();
    app(StartProTrial::class)->handle($user, $team);

    $otherTeam = Team::factory()->create(['user_id' => $user->getKey(), 'personal_team' => false]);

    app(StartProTrial::class)->handle($user, $otherTeam->refresh());
})->throws(AuthorizationException::class);

it('refuses a trial to a non-owner', function (): void {
    [, $team] = trialOwnerAndTeam();
    $member = User::factory()->create();

    app(StartProTrial::class)->handle($member, $team);
})->throws(AuthorizationException::class);

it('refuses a trial when the team is not on Free', function (): void {
    [$user, $team] = trialOwnerAndTeam();
    $team->plan = Plan::Pro;
    $team->save();

    app(StartProTrial::class)->handle($user, $team->refresh());
})->throws(AuthorizationException::class);

it('refuses a trial when the team ever had a subscription', function (): void {
    [$user, $team] = trialOwnerAndTeam();
    $team->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_past',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'ends_at' => now()->subMonth(),
    ]);

    app(StartProTrial::class)->handle($user, $team);
})->throws(AuthorizationException::class);

it('downgrades expired trials and resets the allowance', function (): void {
    [, $team] = trialOwnerAndTeam();
    $team->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->subDay()])->save();

    $this->artisan('billing:process-trials')->assertSuccessful();

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();

    expect($team->refresh()->plan)->toBe(Plan::Free)
        ->and($team->trial_ends_at)->toBeNull()
        ->and($balance->credits_remaining)->toBe(Plan::Free->credits());
});

it('does not downgrade an expired trial that converted to a subscription', function (): void {
    [, $team] = trialOwnerAndTeam();
    $team->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->subDay()])->save();
    $team->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_live',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    $this->artisan('billing:process-trials')->assertSuccessful();

    expect($team->refresh()->plan)->toBe(Plan::Pro);
});

it('emails the owner exactly when the trial ends in three days', function (): void {
    Mail::fake();

    [, $team] = trialOwnerAndTeam();
    $team->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(3)->addHour()])->save();

    $this->artisan('billing:process-trials')->assertSuccessful();
    Mail::assertQueued(ProTrialEndingSoonMail::class, 1);

    // Second run on the same day does not re-send (window check is day-scoped, command is daily).
    $this->artisan('billing:process-trials')->assertSuccessful();
    Mail::assertQueued(ProTrialEndingSoonMail::class, 1);
})->todo('second-run idempotency: implement via day-window query, see command code');
```

Note on the last test: implement the command so the T-3 window is "ends between start and end of the day three days from now"; the daily scheduler runs once per day, so the in-day idempotency `todo` may be converted to a plain passing test by asserting the window query, or dropped if the team accepts at-most-daily semantics. Decide during implementation; do not ship a `todo`.

- [ ] **Step 3: Run to verify failure**

```bash
php artisan test --compact tests/Feature/Billing/TrialLifecycleTest.php
```

Expected: FAIL — classes missing.

- [ ] **Step 4: Implement StartProTrial**

`app/Actions/Billing/StartProTrial.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Plan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Services\CreditService;

final readonly class StartProTrial
{
    public const int TRIAL_DAYS = 14;

    public function __construct(private CreditService $credits) {}

    /** @throws AuthorizationException */
    public function handle(User $user, Team $team): void
    {
        if (! $user->ownsTeam($team)) {
            throw new AuthorizationException('Only the workspace owner can start a trial.');
        }

        if ($user->pro_trial_used_at !== null) {
            throw new AuthorizationException('This account has already used its Pro trial.');
        }

        if ($team->plan !== Plan::Free) {
            throw new AuthorizationException('Trials are only available on the Free plan.');
        }

        if ($team->subscriptions()->exists()) {
            throw new AuthorizationException('This workspace has already had a subscription.');
        }

        DB::transaction(function () use ($user, $team): void {
            $team->forceFill([
                'plan' => Plan::Pro,
                'trial_ends_at' => now()->addDays(self::TRIAL_DAYS),
            ])->save();

            $user->forceFill(['pro_trial_used_at' => now()])->save();

            $this->credits->resetPeriod($team);
        });
    }
}
```

- [ ] **Step 5: Implement the command + mailable + view**

`app/Console/Commands/ProcessTrialsCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Plan;
use App\Mail\ProTrialEndingSoonMail;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Relaticle\Chat\Services\CreditService;

#[Description('Send trial-ending reminders and downgrade expired Pro trials')]
#[Signature('billing:process-trials')]
final class ProcessTrialsCommand extends Command
{
    public function handle(CreditService $credits): int
    {
        $this->sendEndingSoonReminders();
        $this->downgradeExpired($credits);

        return self::SUCCESS;
    }

    private function sendEndingSoonReminders(): void
    {
        $windowStart = now()->addDays(3)->startOfDay();
        $windowEnd = now()->addDays(3)->endOfDay();
        $count = 0;

        Team::query()
            ->whereBetween('trial_ends_at', [$windowStart, $windowEnd])
            ->whereDoesntHave('subscriptions', function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->with('owner')
            ->chunkById(100, function (Collection $teams) use (&$count): void {
                $teams->each(function (Team $team) use (&$count): void {
                    /** @var User $owner */
                    $owner = $team->owner;

                    Mail::to($owner->email)->queue(new ProTrialEndingSoonMail($team));
                    $count++;
                });
            });

        $this->comment("Sent {$count} trial-ending reminder(s).");
    }

    private function downgradeExpired(CreditService $credits): void
    {
        $count = 0;

        Team::query()
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now())
            ->chunkById(100, function (Collection $teams) use ($credits, &$count): void {
                $teams->each(function (Team $team) use ($credits, &$count): void {
                    if ($team->subscriptions()->where(function ($query): void {
                        $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
                    })->exists()) {
                        $team->forceFill(['trial_ends_at' => null])->save();

                        return;
                    }

                    DB::transaction(function () use ($team, $credits): void {
                        $team->forceFill(['plan' => Plan::Free, 'trial_ends_at' => null])->save();
                        $credits->resetPeriod($team);
                    });

                    $this->info("Trial expired, downgraded team: {$team->name}");
                    $count++;
                });
            });

        $this->comment("Downgraded {$count} expired trial(s).");
    }
}
```

`app/Mail/ProTrialEndingSoonMail.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class ProTrialEndingSoonMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Team $team,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your {$this->team->name} Pro trial ends in 3 days",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.pro-trial-ending-soon',
        );
    }
}
```

`resources/views/mail/pro-trial-ending-soon.blade.php`:

```blade
<x-mail::message>
# Your Pro trial ends in 3 days

The 14-day Pro trial for **{{ $team->name }}** ends on {{ $team->trial_ends_at?->toFormattedDateString() }}.
Keep all AI models, 2,000 monthly credits, and higher rate limits by subscribing — still no per-seat pricing, one flat price for the whole workspace.

<x-mail::button :url="url('/app/'.$team->slug.'/billing')">
Keep Pro
</x-mail::button>

If you do nothing, the workspace simply returns to the Free plan. Your data is untouched.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
```

- [ ] **Step 6: Schedule the command**

In `bootstrap/app.php`, inside `->withSchedule(...)` next to `chat:reset-credits`:

```php
$schedule->command('billing:process-trials')->dailyAt('00:15')->withoutOverlapping()->onOneServer();
```

- [ ] **Step 7: Run tests until green, resolve the `todo` per its note, commit**

```bash
php artisan test --compact tests/Feature/Billing/TrialLifecycleTest.php
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(billing): pro trial lifecycle — start action, expiry command, t-3 email"
```

---

### Task 6: `Billing` feature flag

**Files:**
- Create: `app/Features/Billing.php`
- Modify: `config/relaticle.php`
- Modify: `tests/Feature/FeatureFlagsTest.php` (add a describe block; also add `Billing::class` to its `mutates(...)` list)

- [ ] **Step 1: Feature class + config**

`app/Features/Billing.php`:

```php
<?php

declare(strict_types=1);

namespace App\Features;

final readonly class Billing
{
    public function resolve(): bool
    {
        return (bool) config('relaticle.features.billing', false);
    }
}
```

In `config/relaticle.php` `features` array add:

```php
'billing' => (bool) env('RELATICLE_FEATURE_BILLING', false),
```

- [ ] **Step 2: Tests**

Append to `tests/Feature/FeatureFlagsTest.php` (and extend its `mutates(...)`):

```php
describe('Billing', function (): void {
    it('is off by default', function (): void {
        expect(Feature::active(App\Features\Billing::class))->toBeFalse();
    });

    it('activates via config', function (): void {
        config()->set('relaticle.features.billing', true);
        Feature::flushCache();

        expect(Feature::active(App\Features\Billing::class))->toBeTrue();
    });
});
```

- [ ] **Step 3: Run, commit**

```bash
php artisan test --compact tests/Feature/FeatureFlagsTest.php
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(billing): pennant billing feature flag"
```

---

### Task 7: `/app/{team}/billing` page + chat entry points

**Files:**
- Create: `app/Filament/Pages/Billing.php`
- Create: `resources/views/filament/pages/billing.blade.php`
- Create: `lang/en/billing.php`
- Modify: `app/Providers/Filament/AppPanelProvider.php` (tenant menu item)
- Modify: `packages/Chat/resources/views/livewire/app/chat/chat-side-panel.blade.php:155-163`
- Modify: `packages/Chat/src/Http/Controllers/ChatController.php:96,117`
- Test: `tests/Feature/Billing/BillingPageTest.php`

- [ ] **Step 1: Write the failing page tests**

`tests/Feature/Billing/BillingPageTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Features\Billing as BillingFeature;
use App\Filament\Pages\Billing;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Pennant\Feature;

mutates(Billing::class);

beforeEach(function (): void {
    Feature::define(BillingFeature::class, true);
    config()->set('services.stripe.prices.pro_monthly', 'price_pro_monthly_test');
    config()->set('services.stripe.prices.pro_yearly', 'price_pro_yearly_test');
});

function billingPageOwner(): array
{
    $user = User::factory()->withPersonalTeam()->create();

    /** @var Team $team */
    $team = $user->currentTeam;

    test()->actingAs($user);
    Filament::setTenant($team);

    return [$user, $team];
}

it('is hidden when the billing feature is off', function (): void {
    Feature::define(BillingFeature::class, false);
    billingPageOwner();

    livewire(Billing::class)->assertForbidden();
});

it('shows trial CTA to an owner who never trialed', function (): void {
    billingPageOwner();

    livewire(Billing::class)
        ->assertSee(__('billing.trial.start_button'))
        ->assertSee(__('billing.plans.free'));
});

it('hides the trial CTA once the user used a trial', function (): void {
    [$user] = billingPageOwner();
    $user->forceFill(['pro_trial_used_at' => now()])->save();

    livewire(Billing::class)
        ->assertDontSee(__('billing.trial.start_button'))
        ->assertSee(__('billing.upgrade.button'));
});

it('starts a trial via the page action', function (): void {
    [, $team] = billingPageOwner();

    livewire(Billing::class)->call('startTrial');

    expect($team->refresh()->plan)->toBe(Plan::Pro)
        ->and($team->onGenericTrial())->toBeTrue();
});

it('blocks the trial action for non-owners', function (): void {
    [, $team] = billingPageOwner();
    $member = User::factory()->create();
    $team->users()->attach($member, ['role' => 'admin']);

    test()->actingAs($member);
    Filament::setTenant($team->refresh());

    livewire(Billing::class)->call('startTrial');

    expect($team->refresh()->plan)->toBe(Plan::Free);
});

it('shows trialing state with days remaining', function (): void {
    [, $team] = billingPageOwner();
    $team->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(10)])->save();

    livewire(Billing::class)
        ->assertSee(__('billing.trial.active_title'))
        ->assertSee(__('billing.subscribe.button'));
});

it('shows manage state for an active subscription', function (): void {
    [, $team] = billingPageOwner();
    $team->forceFill(['plan' => Plan::Pro])->save();
    $team->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_live',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    livewire(Billing::class)
        ->assertSee(__('billing.manage.button'))
        ->assertDontSee(__('billing.upgrade.button'));
});

it('shows cancellation-scheduled state on grace period', function (): void {
    [, $team] = billingPageOwner();
    $team->forceFill(['plan' => Plan::Pro])->save();
    $team->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_grace',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'ends_at' => now()->addDays(9),
    ]);

    livewire(Billing::class)->assertSee(__('billing.manage.cancel_scheduled_title'));
});

it('shows past-due warning', function (): void {
    [, $team] = billingPageOwner();
    $team->forceFill(['plan' => Plan::Pro])->save();
    $team->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_due',
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    livewire(Billing::class)->assertSee(__('billing.manage.past_due_title'));
});

it('shows enterprise manual state without portal actions', function (): void {
    [, $team] = billingPageOwner();
    $team->forceFill(['plan' => Plan::Enterprise])->save();

    livewire(Billing::class)
        ->assertSee(__('billing.enterprise.title'))
        ->assertDontSee(__('billing.upgrade.button'));
});

it('renders read-only info for members', function (): void {
    [, $team] = billingPageOwner();
    $member = User::factory()->create();
    $team->users()->attach($member, ['role' => 'editor']);

    test()->actingAs($member);
    Filament::setTenant($team->refresh());

    livewire(Billing::class)
        ->assertSee(__('billing.member.ask_owner', ['owner' => $team->owner->name]))
        ->assertDontSee(__('billing.trial.start_button'));
});
```

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test --compact tests/Feature/Billing/BillingPageTest.php
```

Expected: FAIL — page class missing.

- [ ] **Step 3: Language file**

`lang/en/billing.php`:

```php
<?php

declare(strict_types=1);

return [
    'title' => 'Billing',
    'plans' => [
        'free' => 'Free',
        'pro' => 'Pro',
        'enterprise' => 'Enterprise',
    ],
    'usage' => [
        'title' => 'AI credits this period',
        'of' => ':used of :allowance used',
        'resets' => 'Resets :date',
    ],
    'trial' => [
        'start_button' => 'Start 14-day Pro trial — no card needed',
        'active_title' => 'Pro trial active',
        'days_left' => ':days day left|:days days left',
        'started' => 'Your Pro trial is active — enjoy!',
    ],
    'upgrade' => [
        'button' => 'Upgrade to Pro',
        'monthly' => '$29 / month',
        'yearly' => '$290 / year — 2 months free',
        'activating' => 'Payment received — activating Pro…',
    ],
    'subscribe' => [
        'button' => 'Subscribe now',
    ],
    'manage' => [
        'button' => 'Manage subscription',
        'renews' => 'Renews :date',
        'cancel_scheduled_title' => 'Cancellation scheduled',
        'cancel_scheduled_body' => 'Pro stays active until :date. You can resume from the portal.',
        'past_due_title' => 'Payment issue',
        'past_due_body' => 'Your last payment failed. Update your payment method to keep Pro.',
    ],
    'enterprise' => [
        'title' => 'Enterprise plan',
        'body' => 'Your plan is managed by Relaticle. Contact us for changes.',
    ],
    'member' => [
        'ask_owner' => 'Billing is managed by :owner, the workspace owner.',
    ],
];
```

- [ ] **Step 4: The page class**

`app/Filament/Pages/Billing.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Billing\CreateProCheckout;
use App\Actions\Billing\StartProTrial;
use App\Enums\Plan;
use App\Features\Billing as BillingFeature;
use App\Models\Team;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Laravel\Cashier\Subscription;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Url;
use Override;
use Relaticle\Chat\Models\AiCreditBalance;

final class Billing extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $slug = 'billing';

    protected string $view = 'filament.pages.billing';

    #[Url]
    public ?string $checkout = null;

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getLabel(): string
    {
        return __('billing.title');
    }

    public function mount(): void
    {
        abort_unless(Feature::active(BillingFeature::class), 403);
    }

    public function startTrial(StartProTrial $startProTrial): void
    {
        try {
            $startProTrial->handle(auth()->user(), $this->team());
        } catch (AuthorizationException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title(__('billing.trial.started'))->success()->send();
    }

    public function upgrade(CreateProCheckout $createCheckout, string $interval = 'monthly'): ?RedirectResponse
    {
        $team = $this->team();

        if (! auth()->user()->ownsTeam($team) || $team->subscribed()) {
            return null;
        }

        return redirect()->away($createCheckout->handle($team, $interval));
    }

    public function managePortal(): ?RedirectResponse
    {
        $team = $this->team();

        if (! auth()->user()->ownsTeam($team)) {
            return null;
        }

        return $team->redirectToBillingPortal(url("/app/{$team->slug}/billing"));
    }

    public function getViewData(): array
    {
        $team = $this->team();
        $subscription = $team->subscription();

        return [
            'team' => $team,
            'isOwner' => auth()->user()->ownsTeam($team),
            'subscription' => $subscription,
            'pastDue' => $subscription?->pastDue() ?? false,
            'onGrace' => $subscription?->onGracePeriod() ?? false,
            'trialAvailable' => $team->plan === Plan::Free
                && auth()->user()->pro_trial_used_at === null
                && ! $team->subscriptions()->exists(),
            'balance' => AiCreditBalance::query()->where('team_id', $team->getKey())->first(),
            'activating' => $this->checkout === 'success' && ! $team->subscribed(),
        ];
    }

    private function team(): Team
    {
        /** @var Team */
        return Filament::getTenant();
    }
}
```

(Cashier API notes: `subscription()` returns the default-type `Subscription|null`; `pastDue()`, `onGracePeriod()`, `subscribed()`, `onGenericTrial()` are Cashier-provided. `redirectToBillingPortal()` returns a `RedirectResponse`.)

- [ ] **Step 5: The view**

`resources/views/filament/pages/billing.blade.php` (functional first pass — design polish is a later `/design-review` run, not this plan):

```blade
<x-filament-panels::page>
    <div class="space-y-6" @if($activating) wire:poll.3s @endif>

        @if($activating)
            <x-filament::section>
                <div class="flex items-center gap-3">
                    <x-filament::loading-indicator class="h-5 w-5"/>
                    <span>{{ __('billing.upgrade.activating') }}</span>
                </div>
            </x-filament::section>
        @endif

        {{-- Current plan + usage --}}
        <x-filament::section :heading="__('billing.title')">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-2xl font-bold">{{ $team->plan->label() }}</div>
                    @if($team->onGenericTrial())
                        <div class="text-sm text-primary-600">
                            {{ __('billing.trial.active_title') }} —
                            {{ trans_choice('billing.trial.days_left', (int) ceil(now()->floatDiffInDays($team->trial_ends_at)), ['days' => (int) ceil(now()->floatDiffInDays($team->trial_ends_at))]) }}
                        </div>
                    @elseif($subscription?->active() && ! $onGrace)
                        <div class="text-sm text-gray-500">{{ __('billing.manage.renews', ['date' => $team->trial_ends_at?->toFormattedDateString() ?? '—']) }}</div>
                    @endif
                </div>

                @if($balance)
                    <div class="text-right">
                        <div class="text-sm font-medium">{{ __('billing.usage.title') }}</div>
                        <div class="text-sm text-gray-500">
                            {{ __('billing.usage.of', ['used' => number_format($balance->credits_used), 'allowance' => number_format($team->plan->credits())]) }}
                        </div>
                        <div class="text-xs text-gray-400">{{ __('billing.usage.resets', ['date' => $balance->period_ends_at?->toFormattedDateString()]) }}</div>
                    </div>
                @endif
            </div>
        </x-filament::section>

        @if($pastDue)
            <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger" :heading="__('billing.manage.past_due_title')">
                <p>{{ __('billing.manage.past_due_body') }}</p>
                @if($isOwner)
                    <x-filament::button wire:click="managePortal" class="mt-3">{{ __('billing.manage.button') }}</x-filament::button>
                @endif
            </x-filament::section>
        @endif

        @if($onGrace)
            <x-filament::section :heading="__('billing.manage.cancel_scheduled_title')">
                <p>{{ __('billing.manage.cancel_scheduled_body', ['date' => $subscription->ends_at?->toFormattedDateString()]) }}</p>
            </x-filament::section>
        @endif

        {{-- Actions --}}
        @if(! $isOwner)
            <x-filament::section>
                <p>{{ __('billing.member.ask_owner', ['owner' => $team->owner->name]) }}</p>
            </x-filament::section>
        @elseif($team->plan === \App\Enums\Plan::Enterprise && ! $subscription)
            <x-filament::section :heading="__('billing.enterprise.title')">
                <p>{{ __('billing.enterprise.body') }}</p>
            </x-filament::section>
        @elseif($subscription && $subscription->valid())
            <x-filament::section>
                <x-filament::button wire:click="managePortal">{{ __('billing.manage.button') }}</x-filament::button>
            </x-filament::section>
        @else
            <x-filament::section>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                    @if($trialAvailable)
                        <x-filament::button wire:click="startTrial" size="lg">
                            {{ __('billing.trial.start_button') }}
                        </x-filament::button>
                    @endif

                    @if($team->onGenericTrial())
                        <x-filament::button wire:click="upgrade('monthly')" size="lg">{{ __('billing.subscribe.button') }}</x-filament::button>
                    @else
                        <x-filament::button wire:click="upgrade('monthly')" :color="$trialAvailable ? 'gray' : 'primary'">
                            {{ __('billing.upgrade.button') }} · {{ __('billing.upgrade.monthly') }}
                        </x-filament::button>
                        <x-filament::button wire:click="upgrade('yearly')" color="gray">
                            {{ __('billing.upgrade.yearly') }}
                        </x-filament::button>
                    @endif
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
```

(Renewal-date display nuance: Cashier doesn't store `current_period_end` locally; first pass shows "Renews —" for active subs or omits it. If product wants the real date, fetch `$subscription->asStripeSubscription()->current_period_end` lazily on the page — acceptable Stripe read — or store it from the webhook payload in a later iteration. Note this in the PR description.)

- [ ] **Step 6: Tenant menu + chat entry points**

In `app/Providers/Filament/AppPanelProvider.php` add to `->tenantMenuItems([...])` (import `App\Features\Billing as BillingFeature`, `App\Filament\Pages\Billing`, `Laravel\Pennant\Feature`):

```php
Action::make('billing')
    ->label(__('billing.title'))
    ->icon(Heroicon::OutlinedCreditCard)
    ->url(fn (): string => Billing::getUrl())
    ->visible(fn (): bool => Feature::active(BillingFeature::class)),
```

In `packages/Chat/resources/views/livewire/app/chat/chat-side-panel.blade.php` replace the `@if ($this->plan === \App\Enums\Plan::Free) <a href="{{ url('/app/billing') }}" ...` block with:

```blade
@if ($this->plan === \App\Enums\Plan::Free && \Laravel\Pennant\Feature::active(\App\Features\Billing::class))
    <a
        href="{{ \App\Filament\Pages\Billing::getUrl() }}"
        class="mt-1 inline-block text-xs text-primary-600 hover:underline dark:text-primary-400"
    >
        Upgrade to Pro →
    </a>
@endif
```

In `packages/Chat/src/Http/Controllers/ChatController.php` both `'upgrade_url' => $isFree ? url('/app/billing') : null,` lines become:

```php
'upgrade_url' => $isFree && Feature::active(\App\Features\Billing::class)
    ? url("/app/{$team->slug}/billing")
    : null,
```

(add `use Laravel\Pennant\Feature;` to the controller imports).

- [ ] **Step 7: Run page tests + the chat suite slice until green**

```bash
php -d memory_limit=2G vendor/bin/pest --compact tests/Feature/Billing/BillingPageTest.php tests/Feature/Chat/BroadcastReverbConfigTest.php
```

Expected: PASS. Iterate on Filament v5 component names if the view errors (check `vendor/filament` blade components on failure).

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(billing): tenant billing page with trial, checkout and portal entry points"
```

---

### Task 8: Cancel subscriptions on team deletion

**Files:**
- Modify: `app/Actions/Jetstream/ScheduleTeamDeletion.php`
- Modify: `app/Actions/Jetstream/DeleteTeam.php`
- Modify: `app/Livewire/App/Teams/DeleteTeam.php` (confirmation copy — read the file first, add one trans line near the existing warning text)
- Test: extend `tests/Feature/Billing/TrialLifecycleTest.php`? No — create `tests/Feature/Billing/TeamDeletionBillingTest.php`

Stripe calls here can't run in CI (testing policy) — the *decision logic* is the guard "only call Stripe when a non-ended subscription exists". Structure both hooks through one tiny action so the guard is testable and the Stripe line is one statement.

- [ ] **Step 1: Create the cancel action**

`app/Actions/Billing/CancelTeamSubscription.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Team;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class CancelTeamSubscription
{
    public function handle(Team $team, bool $immediately = false): void
    {
        $subscription = $team->subscription();

        if ($subscription === null || $subscription->ended()) {
            return;
        }

        try {
            $immediately ? $subscription->cancelNow() : $subscription->cancel();
        } catch (Throwable $exception) {
            Log::error('Failed to cancel Stripe subscription during team deletion', [
                'team_id' => $team->getKey(),
                'subscription_id' => $subscription->stripe_id,
                'immediately' => $immediately,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
```

(Failure policy: deletion proceeds, error logged → Sentry. A stuck Stripe sub is recoverable from the dashboard; blocking account deletion on a Stripe hiccup is worse.)

- [ ] **Step 2: Wire the hooks**

Read `app/Actions/Jetstream/ScheduleTeamDeletion.php` and `app/Actions/Jetstream/DeleteTeam.php` first; inject and call the action:

- `ScheduleTeamDeletion`: after persisting `scheduled_deletion_at` → `$this->cancelSubscription->handle($team);` (constructor-inject `CancelTeamSubscription $cancelSubscription`).
- `DeleteTeam` (the Jetstream `DeletesTeams` implementation): first line of the deletion flow → `$this->cancelSubscription->handle($team, immediately: true);`.
- `app/Livewire/App/Teams/DeleteTeam.php`: add to the confirmation copy (find the existing warning paragraph in its blade/strings): `Any active Pro subscription is canceled — Pro stays until the end of the paid period.` (put the string in `lang/en/billing.php` under `'deletion_notice'` and reference it).

- [ ] **Step 3: Tests — the guard logic (no Stripe calls fire for ended/missing subs)**

`tests/Feature/Billing/TeamDeletionBillingTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\Billing\CancelTeamSubscription;
use App\Models\Team;
use App\Models\User;

mutates(CancelTeamSubscription::class);

it('does nothing when the team has no subscription', function (): void {
    /** @var Team $team */
    $team = User::factory()->withPersonalTeam()->create()->currentTeam;

    app(CancelTeamSubscription::class)->handle($team);
})->throwsNoExceptions();

it('does nothing when the subscription already ended', function (): void {
    /** @var Team $team */
    $team = User::factory()->withPersonalTeam()->create()->currentTeam;
    $team->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_done',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'ends_at' => now()->subDay(),
    ]);

    app(CancelTeamSubscription::class)->handle($team, immediately: true);
})->throwsNoExceptions();

it('logs instead of throwing when stripe is unreachable for a live subscription', function (): void {
    /** @var Team $team */
    $team = User::factory()->withPersonalTeam()->create()->currentTeam;
    $team->forceFill(['stripe_id' => 'cus_x'])->save();
    $team->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_live',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    // No STRIPE_SECRET configured in tests → the Cashier call throws → action logs and returns.
    app(CancelTeamSubscription::class)->handle($team);

    expect($team->fresh()->subscription()->stripe_status)->toBe('active');
})->throwsNoExceptions();
```

- [ ] **Step 4: Run, then run the existing team-deletion suite for regressions**

```bash
php -d memory_limit=2G vendor/bin/pest --compact tests/Feature/Billing/TeamDeletionBillingTest.php
grep -rln "ScheduleTeamDeletion\|PurgeScheduledDeletions" tests/Feature | xargs php -d memory_limit=2G vendor/bin/pest --compact
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(billing): cancel stripe subscriptions on team deletion"
```

---

### Task 9: Pricing page — three cards behind the flag

**Files:**
- Modify: `resources/views/pricing.blade.php`
- Create: `resources/views/partials/pricing-plans.blade.php` (new 3-card layout)
- Create: `resources/views/partials/pricing-legacy.blade.php` (today's 2-card markup, extracted verbatim)
- Test: `tests/Feature/PricingPageTest.php`

- [ ] **Step 1: Failing tests**

`tests/Feature/PricingPageTest.php`:

```php
<?php

declare(strict_types=1);

use App\Features\Billing as BillingFeature;
use Laravel\Pennant\Feature;

it('shows the legacy two-card page when billing is off', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee('No per-seat pricing. Ever.')
        ->assertDontSee('$29');
});

it('shows the pro tier when billing is on', function (): void {
    Feature::define(BillingFeature::class, true);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee('No per-seat pricing. Ever.')
        ->assertSee('$29')
        ->assertSee('2,000 AI credits')
        ->assertSee('300 AI credits')
        ->assertSee('Try Pro free for 14 days');
});
```

- [ ] **Step 2: Restructure the blade**

`resources/views/pricing.blade.php` keeps the guest layout, badge, header (H1 untouched), trust signals, and help CTA; the card grid becomes:

```blade
@if(\Laravel\Pennant\Feature::active(\App\Features\Billing::class))
    @include('partials.pricing-plans')
@else
    @include('partials.pricing-legacy')
@endif
```

Change the layout meta description props at the top of the file from `description="Relaticle pricing. Free forever — self-hosted or cloud. No per-seat pricing. No usage limits."` to `description="Relaticle pricing. No per-seat pricing — flat workspace plans. Unlimited users and records. Self-host free forever."`.

`pricing-legacy.blade.php`: cut-paste the existing two-card `<div class="grid ...">...</div>` block verbatim.

`pricing-plans.blade.php` — three-card grid in the same visual language (reuse the existing card classes from the legacy markup; key content):

- **Free** card: `$0/mo` · features: `Unlimited users and records`, `300 AI credits / month`, `Fast AI models`, `MCP server with 30 tools`, `REST API with full CRUD` · CTA `Start for free` → `route('register')`.
- **Pro** card (Recommended badge, primary border — copy the existing "Cloud" card treatment): Alpine toggle `x-data="{ yearly: false }"` switching `$29 /mo` ↔ `$290 /yr` with a `2 months free` pill on yearly · features: `Everything in Free`, `2,000 AI credits / month`, `All AI models, including premium`, `30 requests / minute`, `Email support` · CTA `Try Pro free for 14 days — no card` → `route('register')`.
- **Self-Hosted** card: keep the legacy card content verbatim.
- Below the grid, enterprise band: `Need higher AI allowances or custom invoicing? <a href="{{ route('contact') }}">Talk to us</a>.` and the credit footnote: `1 credit ≈ one AI chat message or record summary. Allowances may evolve with notice.`

Write the full markup by adapting the legacy card's classes — every Tailwind class already exists in `pricing.blade.php`; this is a copy-adapt job, not new design.

- [ ] **Step 3: Run tests + build assets if styles shifted**

```bash
php artisan test --compact tests/Feature/PricingPageTest.php
```

Expected: PASS (pure Blade/Tailwind — `npm run build` only if you added classes not present anywhere else; check with a quick visual on https://plucky-sparrow.test/pricing with the flag toggled via `RELATICLE_FEATURE_BILLING=true` in `.env`).

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(billing): pricing page pro tier behind billing flag"
```

---

### Task 10: Sysadmin — subscriptions visibility + grant warning

**Files:**
- Create: `packages/SystemAdmin/src/Filament/Resources/SubscriptionResource.php`
- Create: `packages/SystemAdmin/src/Filament/Resources/SubscriptionResource/Pages/ListSubscriptions.php`
- Create: `packages/SystemAdmin/src/Policies/SubscriptionPolicy.php` (mirror `AiCreditTransactionPolicy` — view/viewAny only; check how that policy is registered in the SystemAdmin package provider and register identically)
- Modify: `packages/SystemAdmin/src/Filament/Resources/AiCreditBalanceResource.php` (origin column + reset warning)
- Test: `tests/Feature/Filament/SystemAdmin/SubscriptionResourceTest.php` (place next to existing sysadmin resource tests — `ls tests/Feature` for the exact sysadmin test directory before creating)

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Relaticle\SystemAdmin\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

it('lists subscriptions with team and plan for sysadmins', function (): void {
    $admin = SystemAdministrator::factory()->create();

    /** @var Team $team */
    $team = User::factory()->withPersonalTeam()->create()->currentTeam;
    $subscription = $team->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_list',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    $this->actingAs($admin, 'sysadmin');

    livewire(ListSubscriptions::class)
        ->assertCanSeeTableRecords([$subscription])
        ->assertSee($team->name)
        ->assertSee('active');
});
```

(Mirror the `actingAs` guard + factory usage from the existing sysadmin tests — `grep -rl "sysadmin" tests/Feature | head` and copy the established setup precisely; adjust if the existing suite uses a helper.)

- [ ] **Step 2: Resource (read-only), list page, policy**

`SubscriptionResource.php` essentials (follow `AiCreditBalanceResource`'s structure/imports; key parts):

```php
final class SubscriptionResource extends Resource
{
    protected static ?string $model = \Laravel\Cashier\Subscription::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $slug = 'billing/subscriptions';

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('owner.name')->label('Team')->searchable(),
                TextColumn::make('stripe_price')
                    ->label('Plan')
                    ->formatStateUsing(fn (?string $state): string => match (true) {
                        $state === config('services.stripe.prices.pro_monthly') => 'Pro · monthly',
                        $state === config('services.stripe.prices.pro_yearly') => 'Pro · yearly',
                        default => $state ?? '—',
                    }),
                TextColumn::make('stripe_status')->badge()->color(fn (string $state): string => match ($state) {
                    'active', 'trialing' => 'success',
                    'past_due' => 'warning',
                    default => 'gray',
                }),
                TextColumn::make('ends_at')->dateTime()->label('Ends')->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->label('Started')->sortable(),
            ])
            ->filters([
                SelectFilter::make('stripe_status')->options([
                    'active' => 'Active', 'past_due' => 'Past due', 'canceled' => 'Canceled', 'incomplete' => 'Incomplete',
                ]),
            ])
            ->recordActions([
                Action::make('stripe')
                    ->label('Open in Stripe')
                    ->url(fn (\Laravel\Cashier\Subscription $record): string => "https://dashboard.stripe.com/subscriptions/{$record->stripe_id}")
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    #[Override]
    public static function getPages(): array
    {
        return ['index' => ListSubscriptions::route('/')];
    }
}
```

`ListSubscriptions.php` mirrors `ListAiCreditBalances`. The policy mirrors `AiCreditTransactionPolicy` (viewAny/view true for sysadmins, everything else false) and registers the same way that one does.

- [ ] **Step 3: Origin column + reset warning on AiCreditBalanceResource**

Add a table column (after the plan column):

```php
TextColumn::make('origin')
    ->state(function (AiCreditBalance $record): string {
        $team = $record->team;

        return match (true) {
            $team->subscription()?->valid() ?? false => 'subscription',
            $team->trial_ends_at?->isFuture() ?? false => 'trial',
            $team->plan !== Plan::default() => 'manual',
            default => '—',
        };
    })
    ->badge()
    ->color(fn (string $state): string => match ($state) {
        'subscription' => 'success', 'trial' => 'info', 'manual' => 'warning', default => 'gray',
    }),
```

On both `resetPeriod` actions (single + bulk), extend the existing `->modalDescription(...)` with the conditional warning — single action:

```php
->modalDescription(fn (AiCreditBalance $record): string => 'Wipes credits_used and grants the allowance for the chosen plan. Starts a fresh monthly period.'
    .(($record->team->subscription()?->valid() ?? false)
        ? ' WARNING: this team has an active Stripe subscription — webhook sync will re-assert the subscribed plan. Cancel the subscription in Stripe first.'
        : ''))
```

(bulk action keeps the static description plus a generic sentence: `Teams with an active Stripe subscription will be re-asserted by webhook sync.`)

- [ ] **Step 4: Run sysadmin tests, commit**

```bash
php -d memory_limit=2G vendor/bin/pest --compact tests/Feature/Filament/SystemAdmin/SubscriptionResourceTest.php
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(sysadmin): read-only stripe subscriptions resource and origin column"
```

---

### Task 11: Docs, artifacts, full gates, ship

**Files:**
- Rewrite: `docs/billing.md`
- Modify: `.context` nothing — but update issue #95 + PR #334 via `gh`

- [ ] **Step 1: Rewrite `docs/billing.md`** — Stripe-shaped version of the existing doc: keep the "no per-seat" framing section verbatim; replace the provider section with Cashier + Managed Payments (flow diagram mirroring the spec §3), the env table (`STRIPE_*`, `RELATICLE_FEATURE_BILLING`), the trial section, the staging E2E checklist (test-mode keys: checkout monthly + yearly, trial start→convert, portal cancel→resume, team deletion cancels, webhook replay), and the ops calendar (Delaware franchise June 1, Form 5472/1120 April, agent renewal). Source content: spec §3–§14 — compress, don't duplicate decision rationale.

- [ ] **Step 2: Full quality gates**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse --no-progress; echo "EXIT: $?"
php -d memory_limit=2G vendor/bin/pest --type-coverage --min=100 2>&1 | tail -3
php -d memory_limit=2G vendor/bin/pest --compact tests/Feature/Billing tests/Feature/Migrations tests/Feature/FeatureFlagsTest.php tests/Feature/PricingPageTest.php tests/Arch
```

Expected: all clean/green. Apply rector suggestions if any (`vendor/bin/rector`), re-run pint, re-test.

- [ ] **Step 3: Commit, push, update PR + issue**

```bash
git add -A && git commit -m "docs(billing): stripe-shaped billing architecture doc"
git push origin feat/billing-foundation
gh pr edit 334 --repo relaticle/relaticle --title "feat(billing): stripe cashier + managed payments billing foundation"
```

Then update the PR body (rewrite of the existing one: provider = Stripe Cashier + Managed Payments, what's included per task list above, test plan = suites + gates, "Part of #95") via `gh pr edit 334 --body-file`, and edit issue #95 body's Decisions section: provider line becomes "Stripe (Laravel Cashier) under a US entity, with Stripe Managed Payments as merchant of record; Polar superseded — transaction-level MoR keeps the DIY off-ramp", and the `danestves/laravel-polar` mention becomes `laravel/cashier`. Keep everything else.

- [ ] **Step 4: Hand off** — report PR URL, remaining human-track items (Atlas, MP ToS, banking, accountant, products/prices, webhook endpoint + `STRIPE_WEBHOOK_SECRET`, flag flip), and the staging E2E checklist location.

---

## Self-review (done at plan-writing time)

- **Spec coverage:** §3 architecture → T1/3/4; §4 data → T2/T5; §5 sync → T3; §6 trial → T5/T7; §7 page → T7; §8 checkout/portal → T4/T7; §9 deletion → T8; §10 pricing → T9; §11 sysadmin → T10; §12 observability → log lines in T3 + Sentry (no code, by decision); §13 testing → embedded per task; §14 rollout → T6/T11 + runbook in docs/billing.md. Gap check: spec's "renews {date}" display — flagged inline in T7 step 5 as a follow-up note in the PR description.
- **Placeholders:** none — every code step carries full code; T10 resource intentionally says "mirror AiCreditBalanceResource imports" for boilerplate imports only, with all novel logic shown.
- **Type consistency:** `SyncTeamPlanFromSubscription::handle(Team, Laravel\Cashier\Subscription)` used consistently (T3 listener, T3 tests); `CreateProCheckout::{handle,priceId,sessionOptions}` match between T4 tests and T7 page; `StartProTrial::handle(User, Team)` matches T5 tests and T7 page; `CancelTeamSubscription::handle(Team, bool)` matches T8 hooks/tests; lang keys referenced in T7 tests all exist in T7 step 3.
