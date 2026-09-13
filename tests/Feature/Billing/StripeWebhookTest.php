<?php

declare(strict_types=1);

use App\Actions\Billing\GrantPurchasedCredits;
use App\Actions\Billing\NotifyWorkspaceOfPaymentFailure;
use App\Actions\Billing\StartProTrial;
use App\Actions\Billing\SyncWorkspacePlanFromSubscription;
use App\Enums\Plan;
use App\Http\Controllers\Billing\StripeWebhookController;
use App\Listeners\Billing\SyncPlanOnStripeSubscriptionChange;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Subscription;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditService;
use Relaticle\SystemAdmin\Actions\TransferWorkspaceBilling;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(SyncWorkspacePlanFromSubscription::class);
mutates(SyncPlanOnStripeSubscriptionChange::class);
mutates(GrantPurchasedCredits::class);
mutates(NotifyWorkspaceOfPaymentFailure::class);

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
function stripeSubscriptionEvent(Workspace $workspace, string $event, array $overrides = []): array
{
    $price = $overrides['price'] ?? 'price_pro_monthly_test';

    $object = array_merge([
        'id' => 'sub_test_1',
        'object' => 'subscription',
        'customer' => $workspace->stripe_id,
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

function stripeBillingWorkspace(): Workspace
{
    /** @var Workspace $workspace */
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $workspace->forceFill(['stripe_id' => 'cus_'.Str::ulid()])->save();

    return $workspace;
}

it('keeps the workspace on Free while the subscription is incomplete', function (): void {
    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(stripeSubscriptionEvent($workspace, 'created', ['status' => 'incomplete']))->assertSuccessful();

    expect($workspace->refresh()->plan)->toBe(Plan::Free)
        ->and(Subscription::query()->where('stripe_id', 'sub_test_1')->exists())->toBeTrue();
});

it('upgrades the workspace to Pro and grants the allowance when the subscription activates', function (): void {
    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(stripeSubscriptionEvent($workspace, 'created', ['status' => 'incomplete']))->assertSuccessful();
    sendStripeWebhook(stripeSubscriptionEvent($workspace, 'updated'))->assertSuccessful();

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();

    expect($workspace->refresh()->plan)->toBe(Plan::Pro)
        ->and($balance->credits_remaining)->toBe(Plan::Pro->credits())
        ->and($balance->credits_used)->toBe(0);
});

it('does not re-reset usage when the same webhook is replayed', function (): void {
    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(stripeSubscriptionEvent($workspace, 'created'))->assertSuccessful();

    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->update([
        'credits_remaining' => Plan::Pro->credits() - 5,
        'credits_used' => 5,
    ]);

    sendStripeWebhook(stripeSubscriptionEvent($workspace, 'updated'))->assertSuccessful();

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();

    expect($workspace->refresh()->plan)->toBe(Plan::Pro)
        ->and($balance->credits_remaining)->toBe(Plan::Pro->credits() - 5)
        ->and($balance->credits_used)->toBe(5);
});

it('keeps Pro pricing when the subscription switches between pro prices', function (): void {
    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(stripeSubscriptionEvent($workspace, 'created'))->assertSuccessful();
    sendStripeWebhook(stripeSubscriptionEvent($workspace, 'updated', ['price' => 'price_pro_yearly_test']))->assertSuccessful();

    expect($workspace->refresh()->plan)->toBe(Plan::Pro)
        ->and(Subscription::query()->where('stripe_id', 'sub_test_1')->value('stripe_price'))->toBe('price_pro_yearly_test');
});

it('downgrades the workspace to Free when the subscription is deleted', function (): void {
    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(stripeSubscriptionEvent($workspace, 'created'))->assertSuccessful();
    sendStripeWebhook(stripeSubscriptionEvent($workspace, 'deleted', [
        'status' => 'canceled',
        'ended_at' => now()->getTimestamp(),
    ]))->assertSuccessful();

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();

    expect($workspace->refresh()->plan)->toBe(Plan::Free)
        ->and($balance->credits_remaining)->toBe(Plan::Free->credits());
});

it('preserves a sysadmin-granted plan when an unrelated subscription ends', function (): void {
    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(stripeSubscriptionEvent($workspace, 'created'))->assertSuccessful();

    $workspace->refresh();
    $workspace->plan = Plan::Enterprise;
    $workspace->save();
    app(CreditService::class)->resetPeriod($workspace);

    sendStripeWebhook(stripeSubscriptionEvent($workspace, 'deleted', [
        'status' => 'canceled',
        'ended_at' => now()->getTimestamp(),
    ]))->assertSuccessful();

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();

    expect($workspace->refresh()->plan)->toBe(Plan::Enterprise)
        ->and($balance->credits_remaining)->toBe(Plan::Enterprise->credits());
});

it('preserves an Enterprise grant when an older Pro subscription sends an update', function (string $status): void {
    $workspace = stripeBillingWorkspace();
    sendStripeWebhook(stripeSubscriptionEvent($workspace, 'created'))->assertSuccessful();
    $workspace->refresh()->forceFill(['plan' => Plan::Enterprise])->save();
    app(CreditService::class)->resetPeriod($workspace);

    sendStripeWebhook(stripeSubscriptionEvent($workspace, 'updated', ['status' => $status]))->assertSuccessful();

    expect($workspace->refresh()->plan)->toBe(Plan::Enterprise);

    app(CreditService::class)->resetPeriod($workspace);

    expect(AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole()->credits_remaining)->toBe(10_000);
})->with(['active', 'past_due']);

it('leaves the plan untouched for a price that maps to no plan', function (): void {
    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(stripeSubscriptionEvent($workspace, 'created', ['price' => 'price_unknown']))->assertSuccessful();

    expect($workspace->refresh()->plan)->toBe(Plan::Free);
});

it('rejects a webhook with an invalid signature', function (): void {
    $workspace = stripeBillingWorkspace();

    $response = sendStripeWebhook(stripeSubscriptionEvent($workspace, 'created'), secret: 'whsec_wrong');

    expect($response->status())->toBeGreaterThanOrEqual(400)
        ->and(Subscription::query()->where('stripe_id', 'sub_test_1')->exists())->toBeFalse()
        ->and($workspace->refresh()->plan)->toBe(Plan::Free);
});

it('rejects an unsigned webhook when no webhook secret is configured', function (): void {
    config()->set('cashier.webhook.secret', null);

    $workspace = stripeBillingWorkspace();
    $body = json_encode(stripeSubscriptionEvent($workspace, 'created'), JSON_THROW_ON_ERROR);

    $response = test()->call('POST', '/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    expect($response->getStatusCode())->toBeGreaterThanOrEqual(400)
        ->and(Subscription::query()->where('stripe_id', 'sub_test_1')->exists())->toBeFalse()
        ->and($workspace->refresh()->plan)->toBe(Plan::Free);
});

it('does not consume the generic trial when a checkout is abandoned as incomplete', function (): void {
    $workspace = stripeBillingWorkspace();
    $trialEndsAt = now()->addDays(10)->startOfSecond();
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => $trialEndsAt])->save();

    sendStripeWebhook(stripeSubscriptionEvent($workspace, 'created', ['status' => 'incomplete']))->assertSuccessful();

    $workspace->refresh();

    expect($workspace->plan)->toBe(Plan::Pro)
        ->and($workspace->trial_ends_at?->timestamp)->toBe($trialEndsAt->timestamp)
        ->and($workspace->onGenericTrial())->toBeTrue();
});

it('never double-grants across a mid-trial conversion', function (): void {
    test()->travelTo(new DateTimeImmutable('2026-06-25 12:00:00', new DateTimeZone('UTC')));

    $workspace = stripeBillingWorkspace();
    app(StartProTrial::class)->execute($workspace->owner, $workspace);

    // Convert mid-trial. The plan is already Pro, so SyncWorkspacePlanFromSubscription
    // short-circuits: NO new grant at conversion, and the trial allowance keeps running.
    test()->travelTo(new DateTimeImmutable('2026-07-01 12:00:00', new DateTimeZone('UTC')));
    sendStripeWebhook(stripeSubscriptionEvent($workspace->refresh(), 'created'))->assertSuccessful();

    $grantsQuery = AiCreditTransaction::query()
        ->where('workspace_id', $workspace->getKey())
        ->where('metadata->action', 'reset_period');

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();
    expect($balance->period_ends_at->toDateTimeString())->toBe('2026-07-09 12:00:00')
        ->and($grantsQuery->count())->toBe(1);

    // When the trial-shaped period lapses, the sweep re-anchors to the subscription.
    test()->travelTo(new DateTimeImmutable('2026-07-10 12:00:00', new DateTimeZone('UTC')));
    test()->artisan('chat:reset-credits')->assertSuccessful();

    $balance->refresh();
    expect($balance->period_starts_at->toDateTimeString())->toBe('2026-07-01 12:00:00')
        ->and($balance->period_ends_at->toDateTimeString())->toBe('2026-08-01 12:00:00')
        ->and($grantsQuery->count())->toBe(2); // trial start + first anniversary cycle, never a third
});

/** @return array<string, mixed> */
function checkoutSessionCompletedEvent(Workspace $workspace, array $overrides = []): array
{
    return [
        'id' => 'evt_checkout_test',
        'type' => 'checkout.session.completed',
        'data' => ['object' => array_merge([
            'id' => 'cs_test_pack_1',
            'object' => 'checkout.session',
            'mode' => 'payment',
            'payment_status' => 'paid',
            'customer' => $workspace->stripe_id,
            'metadata' => [
                'team_id' => (string) $workspace->getKey(),
                'credit_pack_price' => 'price_credits_1k_test',
            ],
        ], $overrides)],
    ];
}

/** @return array<string, mixed> */
function checkoutSessionAsyncPaymentSucceededEvent(Workspace $workspace, array $overrides = []): array
{
    $event = checkoutSessionCompletedEvent($workspace, $overrides);
    $event['type'] = 'checkout.session.async_payment_succeeded';
    $event['data']['object']['payment_status'] = 'paid';

    return $event;
}

it('grants pack credits exactly once on checkout session completed', function (): void {
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);

    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(checkoutSessionCompletedEvent($workspace))->assertOk();
    sendStripeWebhook(checkoutSessionCompletedEvent($workspace))->assertOk(); // replay

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();
    expect($balance->purchased_credits)->toBe(1000);
});

it('grants nothing for an unpaid checkout session completed event', function (): void {
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);

    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(checkoutSessionCompletedEvent($workspace, ['payment_status' => 'unpaid']))->assertOk();

    expect(AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->value('purchased_credits') ?? 0)->toBe(0);
});

it('grants pack credits once the delayed payment confirms asynchronously', function (): void {
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);

    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(checkoutSessionAsyncPaymentSucceededEvent($workspace))->assertOk();

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();
    expect($balance->purchased_credits)->toBe(1000);
});

it('grants exactly once when an unpaid checkout later confirms asynchronously', function (): void {
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);

    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(checkoutSessionCompletedEvent($workspace, ['payment_status' => 'unpaid']))->assertOk();
    sendStripeWebhook(checkoutSessionAsyncPaymentSucceededEvent($workspace))->assertOk();
    sendStripeWebhook(checkoutSessionAsyncPaymentSucceededEvent($workspace))->assertOk(); // replay

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();
    expect($balance->purchased_credits)->toBe(1000);

    $grantsQuery = AiCreditTransaction::query()
        ->where('workspace_id', $workspace->getKey())
        ->where('idempotency_key', 'pack-cs_test_pack_1');

    expect($grantsQuery->count())->toBe(1);
});

it('ignores subscription-mode checkout sessions', function (): void {
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);

    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(checkoutSessionCompletedEvent($workspace, ['mode' => 'subscription']))->assertOk();

    expect(AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->value('purchased_credits') ?? 0)->toBe(0);
});

it('grants nothing when the session customer does not match the workspace', function (): void {
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);

    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(checkoutSessionCompletedEvent($workspace, ['customer' => 'cus_attacker']))->assertOk();

    expect(AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->value('purchased_credits') ?? 0)->toBe(0);
});

it('grants nothing for an unknown pack price', function (): void {
    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(checkoutSessionCompletedEvent($workspace, [
        'metadata' => ['team_id' => (string) $workspace->getKey(), 'credit_pack_price' => 'price_nonexistent'],
    ]))->assertOk();

    expect(AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->value('purchased_credits') ?? 0)->toBe(0);
});

it('logs and grants nothing when a payment-mode session is missing pack metadata', function (): void {
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);

    $workspace = stripeBillingWorkspace();

    Log::spy();

    // credit_pack_price is well-formed and configured; workspace_id is missing. This
    // must trip the metadata guard specifically, not the unknown-price branch
    // (which is never reached) or the customer-mismatch branch (which requires
    // a resolved workspace, and this metadata never resolves one).
    sendStripeWebhook(checkoutSessionCompletedEvent($workspace, [
        'metadata' => ['credit_pack_price' => 'price_credits_1k_test'],
    ]))->assertOk();

    expect(AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->value('purchased_credits') ?? 0)->toBe(0);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'missing or malformed metadata')
            && $context['session_id'] === 'cs_test_pack_1'
            && in_array('metadata.team_id', $context['missing_fields'], true)
            && ! in_array('metadata.credit_pack_price', $context['missing_fields'], true)
        );
});

/**
 * Shaped after a real event off the sandbox (API 2026-05-27.dahlia): that
 * version carries the subscription at parent.subscription_details and has no
 * top-level `subscription` key at all.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function invoicePaymentFailedEvent(Workspace $workspace, array $overrides = []): array
{
    return [
        'type' => 'invoice.payment_failed',
        'data' => [
            'object' => array_merge([
                'id' => 'in_'.Str::ulid(),
                'object' => 'invoice',
                'customer' => $workspace->stripe_id,
                'billing_reason' => 'subscription_cycle',
                'attempt_count' => 1,
                'parent' => [
                    'type' => 'subscription_details',
                    'subscription_details' => ['subscription' => 'sub_test_1'],
                ],
            ], $overrides),
        ],
    ];
}

it('notifies the workspace owner when a renewal charge fails', function (): void {
    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(invoicePaymentFailedEvent($workspace))->assertOk();

    $notification = $workspace->owner->notifications()->sole();

    expect($notification->data['title'])->toContain($workspace->name)
        ->and($notification->data['body'])->toBe(__('billing.payment_failed.notification_body'));
});

it('keeps Enterprise access clear in an older subscription payment notification', function (): void {
    $workspace = stripeBillingWorkspace();
    $workspace->forceFill(['plan' => Plan::Enterprise])->save();

    sendStripeWebhook(invoicePaymentFailedEvent($workspace))->assertOk();

    $notification = $workspace->owner->notifications()->sole();

    expect($notification->data['body'])->toContain('Your Enterprise access is unchanged.')
        ->not->toContain('to keep Pro');
});

it('notifies once per invoice, not once per Stripe retry attempt', function (): void {
    $workspace = stripeBillingWorkspace();

    foreach ([1, 2, 3] as $attempt) {
        sendStripeWebhook(invoicePaymentFailedEvent($workspace, [
            'id' => 'in_test_retried',
            'attempt_count' => $attempt,
        ]))->assertOk();
    }

    expect($workspace->owner->notifications()->count())->toBe(1);
});

it('alarms on a plan change that failed to bill', function (): void {
    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(invoicePaymentFailedEvent($workspace, [
        'billing_reason' => 'subscription_update',
    ]))->assertOk();

    expect($workspace->owner->notifications()->count())->toBe(1);
});

it('stays quiet when the very first subscription charge fails', function (): void {
    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(invoicePaymentFailedEvent($workspace, [
        'billing_reason' => 'subscription_create',
    ]))->assertOk();

    expect($workspace->owner->notifications()->count())->toBe(0);
});

it('raises no subscription alarm for a one-off invoice', function (): void {
    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(invoicePaymentFailedEvent($workspace, [
        'billing_reason' => 'manual',
        'parent' => null,
    ]))->assertOk();

    expect($workspace->owner->notifications()->count())->toBe(0);
});

it('notifies nobody for a customer that belongs to no workspace', function (): void {
    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(invoicePaymentFailedEvent($workspace, ['customer' => 'cus_unknown']))->assertOk();

    expect($workspace->owner->notifications()->count())->toBe(0);
});

it('subscribes the Stripe endpoint to the failed-payment event', function (): void {
    expect(config('cashier.webhook.events'))->toContain('invoice.payment_failed');
});

it('subscribes the Stripe endpoint to every checkout event the controller handles', function (): void {
    // `cashier:webhook` provisions the endpoint from config('cashier.webhook.events').
    // A handler that isn't in that list never fires in production, however well
    // it is covered here; these tests POST to the route directly.
    $handled = collect((new ReflectionClass(StripeWebhookController::class))->getMethods(ReflectionMethod::IS_PROTECTED))
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->filter(fn (string $name): bool => str_starts_with($name, 'handleCheckoutSession'))
        ->map(fn (string $name): string => Str::replaceFirst(
            'checkout_session_',
            'checkout.session.',
            Str::snake(Str::replaceFirst('handle', '', $name)),
        ))
        ->values()
        ->all();

    expect($handled)->toContain('checkout.session.completed', 'checkout.session.async_payment_succeeded')
        ->and(config('cashier.webhook.events'))->toContain(...$handled);
});

it('syncs later stripe events to the workspace that received a transferred subscription', function (): void {
    $owner = User::factory()->create();

    /** @var Workspace $source */
    $source = Workspace::factory()->create([
        'user_id' => $owner->getKey(),
        'plan' => Plan::Pro,
        'stripe_id' => 'cus_webhook_transfer',
    ]);

    /** @var Workspace $target */
    $target = Workspace::factory()->create([
        'user_id' => $owner->getKey(),
        'plan' => Plan::Free,
    ]);

    $source->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_webhook_transfer',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    $admin = SystemAdministrator::factory()->create();
    app(TransferWorkspaceBilling::class)->execute($source, $target, (string) $admin->getKey());

    $payload = stripeSubscriptionEvent($target, 'updated', [
        'price' => 'price_pro_yearly_test',
    ]);
    $payload['data']['object']['id'] = 'sub_webhook_transfer';
    $payload['data']['object']['customer'] = 'cus_webhook_transfer';

    sendStripeWebhook($payload)->assertOk();

    expect(Subscription::query()->where('stripe_id', 'sub_webhook_transfer')->count())->toBe(1)
        ->and(Subscription::query()->where('stripe_id', 'sub_webhook_transfer')->sole()->workspace_id)->toBe($target->getKey())
        ->and($target->refresh()->plan)->toBe(Plan::Pro)
        ->and($source->refresh()->plan)->toBe(Plan::Free);
});
