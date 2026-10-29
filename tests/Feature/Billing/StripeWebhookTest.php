<?php

declare(strict_types=1);

use App\Actions\Billing\ActivateManagedPlan;
use App\Actions\Billing\CancelWithUnusedTimeRefund;
use App\Actions\Billing\GrantPurchasedCredits;
use App\Actions\Billing\NotifyWorkspaceOfPaymentFailure;
use App\Actions\Billing\StartProTrial;
use App\Actions\Billing\SyncWorkspacePlanFromSubscription;
use App\Enums\BillingStatus;
use App\Enums\Notifications\NotificationReason;
use App\Enums\Plan;
use App\Enums\SubscriptionType;
use App\Features\Billing as BillingFeature;
use App\Filament\Pages\Billing;
use App\Http\Controllers\Billing\StripeWebhookController;
use App\Jobs\RefreshManagedRenewal;
use App\Listeners\Billing\SyncPlanOnStripeSubscriptionChange;
use App\Livewire\App\AppDatabaseNotifications;
use App\Mail\ProEndedMail;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ManagedInvoiceOverdueNotification;
use App\Notifications\PaymentFailedNotification;
use App\Services\Billing\HostedWorkspaceAccess;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Subscription;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditService;
use Relaticle\SystemAdmin\Actions\TransferWorkspaceBilling;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Stripe\Exception\AuthenticationException;
use Tests\Helpers\StripeRecorder;

mutates(SyncWorkspacePlanFromSubscription::class);
mutates(SyncPlanOnStripeSubscriptionChange::class);
mutates(GrantPurchasedCredits::class);
mutates(NotifyWorkspaceOfPaymentFailure::class);
mutates(ActivateManagedPlan::class);
mutates(CancelWithUnusedTimeRefund::class);
mutates(RefreshManagedRenewal::class);
mutates(PaymentFailedNotification::class);
mutates(ManagedInvoiceOverdueNotification::class);
mutates(StripeWebhookController::class);

beforeEach(function (): void {
    config()->set('cashier.webhook.secret', 'whsec_test_secret');
    config()->set('cashier.secret', 'sk_test_fake');
    config()->set('services.stripe.prices.pro_monthly', 'price_pro_monthly_test');
    config()->set('services.stripe.prices.pro_yearly', 'price_pro_yearly_test');
});

afterEach(function (): void {
    StripeRecorder::uninstall();
});

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, mixed>|null  $stripeHolds
 */
function sendStripeWebhook(array $payload, string $secret = 'whsec_test_secret', ?array $stripeHolds = null, array $stripeAlsoAnswers = []): TestResponse
{
    if (($payload['type'] ?? null) === 'customer.subscription.updated') {
        $subscription = $stripeHolds ?? $payload['data']['object'];

        $recorder = StripeRecorder::install()->respondTo("/v1/subscriptions/{$subscription['id']}", $subscription);

        foreach ($stripeAlsoAnswers as $request => $body) {
            $recorder->respondTo($request, $body);
        }
    }

    if (($payload['type'] ?? null) === 'customer.subscription.deleted' && ! StripeRecorder::current() instanceof StripeRecorder) {
        StripeRecorder::install();
    }

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

it('emails the owner once when the subscription ends', function (): void {
    Mail::fake();
    $workspace = stripeBillingWorkspace();
    $deleted = stripeSubscriptionEvent($workspace, 'deleted', [
        'status' => 'canceled',
        'ended_at' => now()->getTimestamp(),
    ]);

    sendStripeWebhook(stripeSubscriptionEvent($workspace, 'created'))->assertSuccessful();

    Mail::assertNotQueued(ProEndedMail::class);

    sendStripeWebhook($deleted)->assertSuccessful();
    sendStripeWebhook($deleted)->assertSuccessful();

    Mail::assertQueued(ProEndedMail::class, 1);
    Mail::assertQueued(ProEndedMail::class, fn (ProEndedMail $mail): bool => $mail->hasTo($workspace->owner->email)
        && $mail->status === BillingStatus::SubscriptionEnded);
});

it('sends no ended email when an Enterprise grant outlives the subscription', function (): void {
    Mail::fake();
    $workspace = stripeBillingWorkspace();
    sendStripeWebhook(stripeSubscriptionEvent($workspace, 'created'))->assertSuccessful();
    $workspace->refresh()->forceFill(['plan' => Plan::Enterprise])->save();

    sendStripeWebhook(stripeSubscriptionEvent($workspace, 'deleted', [
        'status' => 'canceled',
        'ended_at' => now()->getTimestamp(),
    ]))->assertSuccessful();

    Mail::assertNotQueued(ProEndedMail::class);
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

it('maps a late subscription update to the price Stripe holds now', function (): void {
    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(stripeSubscriptionEvent($workspace, 'created', ['price' => 'price_pro_yearly_test']))->assertSuccessful();
    sendStripeWebhook(
        stripeSubscriptionEvent($workspace, 'updated', ['price' => 'price_pro_monthly_test']),
        stripeHolds: stripeSubscriptionEvent($workspace, 'updated', ['price' => 'price_pro_yearly_test'])['data']['object'],
    )->assertSuccessful();

    expect(Subscription::query()->where('stripe_id', 'sub_test_1')->value('stripe_price'))->toBe('price_pro_yearly_test')
        ->and($workspace->refresh()->plan)->toBe(Plan::Pro);
});

it('fails the webhook so Stripe retries when the latest subscription cannot be fetched', function (): void {
    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(stripeSubscriptionEvent($workspace, 'created'))->assertSuccessful();

    config()->set('cashier.secret', null);
    $payload = stripeSubscriptionEvent($workspace, 'updated', ['price' => 'price_pro_yearly_test']);
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = time();

    $this->withoutExceptionHandling();

    expect(fn () => $this->call('POST', '/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", 'whsec_test_secret'),
        'CONTENT_TYPE' => 'application/json',
    ], $body))->toThrow(AuthenticationException::class);

    expect(Subscription::query()->where('stripe_id', 'sub_test_1')->value('stripe_price'))->toBe('price_pro_monthly_test');
});

it('preserves a Concierge grant when an older Pro subscription sends an update', function (): void {
    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(stripeSubscriptionEvent($workspace, 'created'))->assertSuccessful();
    $workspace->forceFill(['plan' => Plan::Concierge])->save();

    sendStripeWebhook(stripeSubscriptionEvent($workspace, 'updated', ['cancel_at_period_end' => true]))->assertSuccessful();

    expect($workspace->refresh()->plan)->toBe(Plan::Concierge);
});

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

it('keeps the generic trial when a managed subscription is created awaiting its first payment', function (): void {
    $workspace = stripeBillingWorkspace();
    $trialEndsAt = now()->addDays(10)->startOfSecond();
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => $trialEndsAt])->save();

    config()->set('services.stripe.products.concierge', 'prod_concierge_test');
    $event = stripeSubscriptionEvent($workspace, 'created', [
        'price' => 'price_managed_inline_test',
        'metadata' => ['type' => 'managed_pending', 'workspace_id' => (string) $workspace->getKey()],
    ]);
    $event['data']['object']['items']['data'][0]['price']['product'] = Plan::Concierge->stripeProduct();

    sendStripeWebhook($event)->assertSuccessful();

    $workspace->refresh();

    expect(Subscription::query()->where('stripe_id', 'sub_test_1')->sole()->type)->toBe('managed_pending')
        ->and($workspace->plan)->toBe(Plan::Pro)
        ->and($workspace->trial_ends_at?->timestamp)->toBe($trialEndsAt->timestamp);
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

    $notification = PaymentFailedNotification::present($workspace->owner->notifications()->sole());

    expect($notification->getTitle())->toContain($workspace->name)
        ->and($notification->getBody())->toBe(__('billing.payment_failed.notification_body'))
        ->and($notification->getActions()[0]->getUrl())->toBe(Billing::getUrl(panel: 'app', tenant: $workspace));
});

it('keeps a managed plan clear in an older subscription payment notification', function (Plan $plan): void {
    $workspace = stripeBillingWorkspace();
    $workspace->forceFill(['plan' => $plan])->save();

    sendStripeWebhook(invoicePaymentFailedEvent($workspace))->assertOk();

    $notification = PaymentFailedNotification::present($workspace->owner->notifications()->sole());

    expect($notification->getBody())->toContain('Your current plan is unchanged.')
        ->not->toContain('to keep Pro');
})->with([Plan::Enterprise, Plan::Concierge]);

it('still reads an Enterprise payment notification stored under the earlier key', function (): void {
    $workspace = stripeBillingWorkspace();
    $workspace->forceFill(['plan' => Plan::Enterprise])->save();

    sendStripeWebhook(invoicePaymentFailedEvent($workspace))->assertOk();

    $row = $workspace->owner->notifications()->sole();
    $row->forceFill(['data' => collect($row->data)->except('managed')->put('enterprise', true)->all()])->save();

    expect(PaymentFailedNotification::present($row->fresh())->getBody())->toContain('Your current plan is unchanged.');
});

it('notifies once per invoice, not once per Stripe retry attempt', function (): void {
    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(invoicePaymentFailedEvent($workspace, [
        'id' => 'in_test_retried',
        'attempt_count' => 1,
    ]))->assertOk();

    $workspace->owner->notifications()->sole()->markAsRead();

    foreach ([2, 3] as $attempt) {
        sendStripeWebhook(invoicePaymentFailedEvent($workspace, [
            'id' => 'in_test_retried',
            'attempt_count' => $attempt,
        ]))->assertOk();
    }

    expect($workspace->owner->notifications()->count())->toBe(1);
});

it('stores one account-level payment failure row for the owner', function (): void {
    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(invoicePaymentFailedEvent($workspace))->assertOk();
    sendStripeWebhook(invoicePaymentFailedEvent($workspace))->assertOk();

    $owner = $workspace->owner;
    $row = $owner->notifications()->first();

    expect($owner->notifications()->count())->toBe(1)
        ->and($row->workspace_id)->toBeNull()
        ->and($row->data['workspace_id'])->toBe($workspace->getKey())
        ->and($row->reason)->toBe(NotificationReason::System);
});

it('shows a payment failure in the bell of every workspace its owner opens', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $current = $owner->currentWorkspace;
    $failing = Workspace::factory()->create([
        'user_id' => $owner->getKey(),
        'personal_workspace' => false,
        'name' => 'Failing Workspace',
        'stripe_id' => 'cus_'.Str::ulid(),
    ]);
    resolve(CurrentWorkspace::class)->set($current);

    sendStripeWebhook(invoicePaymentFailedEvent($failing))->assertOk();

    Livewire::actingAs($owner)->test(AppDatabaseNotifications::class)
        ->assertSee(__('billing.payment_failed.notification_title', ['workspace' => 'Failing Workspace']))
        ->assertSee(Billing::getUrl(panel: 'app', tenant: $failing), false)
        ->assertDontSee(Billing::getUrl(panel: 'app', tenant: $current), false);
});

it('offers no billing link once the failing workspace is gone', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $failing = Workspace::factory()->create([
        'user_id' => $owner->getKey(),
        'personal_workspace' => false,
        'stripe_id' => 'cus_'.Str::ulid(),
    ]);

    sendStripeWebhook(invoicePaymentFailedEvent($failing))->assertOk();
    $failing->delete();

    expect(PaymentFailedNotification::present($owner->notifications()->sole())->getActions())->toBe([]);
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

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function invoicePaidEvent(Workspace $workspace, array $overrides = []): array
{
    return [
        'id' => 'evt_'.Str::ulid(),
        'type' => 'invoice.paid',
        'data' => [
            'object' => array_merge([
                'id' => 'in_managed_1',
                'object' => 'invoice',
                'customer' => $workspace->stripe_id,
                'billing_reason' => 'subscription_create',
                'parent' => [
                    'type' => 'subscription_details',
                    'subscription_details' => ['subscription' => 'sub_managed_1'],
                ],
            ], $overrides),
        ],
    ];
}

function pendingManagedSubscription(Workspace $workspace, string $stripeId = 'sub_managed_1', string $type = 'managed_pending'): Subscription
{
    return $workspace->subscriptions()->create([
        'type' => $type,
        'stripe_id' => $stripeId,
        'stripe_status' => 'active',
        'stripe_price' => 'price_inline_'.$stripeId,
        'quantity' => 1,
    ]);
}

function proSubscriptionOn(Workspace $workspace, string $stripeId = 'sub_pro_1', string $status = 'active'): Subscription
{
    return $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => $stripeId,
        'stripe_status' => $status,
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'trial_ends_at' => $status === 'trialing' ? now()->addDays(5) : null,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stripeManagedSubscriptionBody(Workspace $workspace, Plan $plan = Plan::Concierge, string $id = 'sub_managed_1', array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'object' => 'subscription',
        'customer' => $workspace->stripe_id,
        'status' => 'active',
        'metadata' => ['workspace_id' => (string) $workspace->getKey(), 'type' => 'managed_pending'],
        'items' => [
            'object' => 'list',
            'data' => [[
                'id' => 'si_'.$id,
                'object' => 'subscription_item',
                'current_period_end' => now()->addYear()->getTimestamp(),
                'price' => [
                    'id' => 'price_inline_'.$id,
                    'object' => 'price',
                    'product' => $plan->stripeProduct(),
                    'unit_amount' => 70800,
                    'currency' => 'usd',
                ],
            ]],
        ],
    ], $overrides);
}

/**
 * @param  list<array<string, mixed>>|null  $lines
 * @return array<string, mixed>
 */
function stripePaidProInvoice(int $amountPaid, ?array $lines = null): array
{
    $period = [
        'start' => now()->subDays(15)->getTimestamp(),
        'end' => now()->addDays(15)->getTimestamp(),
    ];

    return [
        'object' => 'list',
        'has_more' => false,
        'data' => [[
            'id' => 'in_pro_1',
            'object' => 'invoice',
            'amount_paid' => $amountPaid,
            'lines' => [
                'object' => 'list',
                'data' => $lines ?? [
                    [
                        'period' => ['start' => now()->subDays(1)->getTimestamp(), 'end' => now()->addDays(1)->getTimestamp()],
                        'parent' => ['type' => 'subscription_item_details', 'subscription_item_details' => ['proration' => true]],
                    ],
                    [
                        'period' => $period,
                        'parent' => ['type' => 'subscription_item_details', 'subscription_item_details' => ['proration' => false]],
                    ],
                ],
            ],
        ]],
    ];
}

function stripeManagedSwitch(Workspace $workspace, int $amountPaid = 2400, Plan $plan = Plan::Concierge, string $id = 'sub_managed_1'): StripeRecorder
{
    config()->set('services.stripe.products.concierge', 'prod_concierge_test');
    config()->set('services.stripe.products.enterprise', 'prod_enterprise_test');

    return StripeRecorder::install()
        ->respondTo("/v1/subscriptions/{$id}", stripeManagedSubscriptionBody($workspace, $plan, $id))
        ->respondTo('/v1/invoices/in_managed_1', [
            'id' => 'in_managed_1',
            'object' => 'invoice',
            'status_transitions' => ['paid_at' => now()->getTimestamp()],
        ])
        ->respondTo('POST /v1/invoices/create_preview', upcomingManagedInvoice(76464))
        ->respondTo('/v1/invoices', stripePaidProInvoice($amountPaid))
        ->respondTo('/v1/invoice_payments', [
            'object' => 'list',
            'has_more' => false,
            'data' => [['id' => 'inpay_1', 'object' => 'invoice_payment', 'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_pro_1']]],
        ])
        ->respondTo('GET /v1/refunds', ['object' => 'list', 'has_more' => false, 'data' => []])
        ->respondTo('POST /v1/refunds', ['id' => 're_1', 'object' => 'refund', 'status' => 'succeeded']);
}

function resetRowsFor(Workspace $workspace): int
{
    return AiCreditTransaction::query()
        ->where('workspace_id', $workspace->getKey())
        ->get()
        ->filter(fn (AiCreditTransaction $transaction): bool => ($transaction->metadata['action'] ?? null) === 'reset_period')
        ->count();
}

function proWorkspaceAwaitingManagedPayment(): Workspace
{
    $workspace = stripeBillingWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro])->save();
    proSubscriptionOn($workspace);
    pendingManagedSubscription($workspace);

    return $workspace;
}

it('switches a managed plan on when its first invoice is paid', function (): void {
    $workspace = stripeBillingWorkspace();
    pendingManagedSubscription($workspace);
    stripeManagedSwitch($workspace);

    sendStripeWebhook(invoicePaidEvent($workspace))->assertOk();

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();

    expect(Subscription::query()->where('stripe_id', 'sub_managed_1')->sole()->type)->toBe(SubscriptionType::Managed->value)
        ->and($workspace->refresh()->plan)->toBe(Plan::Concierge)
        ->and($balance->credits_remaining)->toBe(5000);
});

it('marks the Stripe subscription as managed once it is paid', function (): void {
    $workspace = stripeBillingWorkspace();
    pendingManagedSubscription($workspace);
    $recorder = stripeManagedSwitch($workspace);

    sendStripeWebhook(invoicePaidEvent($workspace))->assertOk();

    expect($recorder->lastParamsFor('POST /v1/subscriptions/sub_managed_1'))->toBe(['metadata' => ['type' => 'managed']]);
});

it('cancels Pro and refunds its unused time when the managed invoice is paid', function (): void {
    $this->travelTo(now()->startOfSecond());

    $workspace = proWorkspaceAwaitingManagedPayment();
    $recorder = stripeManagedSwitch($workspace);
    $key = 'managed-switch-in_managed_1-sub_pro_1-in_pro_1';

    sendStripeWebhook(invoicePaidEvent($workspace))->assertOk();

    $proRow = Subscription::query()->where('stripe_id', 'sub_pro_1')->sole();

    expect($recorder->paramsFor('POST /v1/refunds'))->toBe([
        'payment_intent' => 'pi_pro_1',
        'amount' => 1200,
        'metadata' => ['switch_key' => $key],
    ])
        ->and($recorder->headersFor('POST /v1/refunds'))->toContain("Idempotency-Key: {$key}")
        ->and($recorder->paramsFor('DELETE /v1/subscriptions/sub_pro_1'))->toBe(['prorate' => 'false'])
        ->and($recorder->paramsFor('GET /v1/invoice_payments'))->toBe(['invoice' => 'in_pro_1', 'status' => 'paid'])
        ->and($proRow->stripe_status)->toBe('canceled')
        ->and($proRow->ended())->toBeTrue()
        ->and($workspace->refresh()->plan)->toBe(Plan::Concierge);
});

it('switches a Pro trial with no refund', function (): void {
    $workspace = stripeBillingWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro])->save();
    proSubscriptionOn($workspace, status: 'trialing');
    pendingManagedSubscription($workspace);
    $recorder = stripeManagedSwitch($workspace, amountPaid: 0);

    sendStripeWebhook(invoicePaidEvent($workspace))->assertOk();

    expect($recorder->countFor('POST /v1/refunds'))->toBe(0)
        ->and($recorder->countFor('DELETE /v1/subscriptions/sub_pro_1'))->toBe(1)
        ->and(Subscription::query()->where('stripe_id', 'sub_pro_1')->sole()->ended())->toBeTrue()
        ->and($workspace->refresh()->plan)->toBe(Plan::Concierge);
});

it('grants nothing for a managed product subscription created outside Start managed plan', function (): void {
    $workspace = stripeBillingWorkspace();

    sendStripeWebhook(managedSubscriptionEvent($workspace, 'created', overrides: [
        'metadata' => ['workspace_id' => (string) $workspace->getKey()],
    ]))->assertSuccessful();

    expect($workspace->refresh()->plan)->toBe(Plan::Free)
        ->and(Subscription::query()->where('stripe_id', 'sub_managed_1')->sole()->type)->toBe('default');
});

it('ends the trial when the managed invoice is paid, so a cancelled managed plan does not stay on for free', function (): void {
    Mail::fake();
    $workspace = stripeBillingWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(10)])->save();
    managedSubscriptionOn($workspace, type: 'managed_pending');
    stripeManagedSwitch($workspace, amountPaid: 0);

    sendStripeWebhook(invoicePaidEvent($workspace))->assertOk();

    expect($workspace->refresh()->plan)->toBe(Plan::Concierge)
        ->and($workspace->trial_ends_at)->toBeNull();

    sendStripeWebhook(managedSubscriptionEvent($workspace, 'deleted', overrides: [
        'status' => 'canceled',
        'ended_at' => now()->getTimestamp(),
    ]))->assertSuccessful();

    expect($workspace->refresh()->plan)->toBe(Plan::Free);
});

it('replaces an older managed subscription without ending the plan', function (): void {
    $this->travelTo(now()->startOfSecond());
    Mail::fake();

    $workspace = stripeBillingWorkspace();
    $workspace->forceFill(['plan' => Plan::Concierge])->save();
    pendingManagedSubscription($workspace, 'sub_managed_old', 'managed');
    pendingManagedSubscription($workspace, 'sub_managed_new');
    $recorder = stripeManagedSwitch($workspace, plan: Plan::Enterprise, id: 'sub_managed_new');

    sendStripeWebhook(invoicePaidEvent($workspace, [
        'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_managed_new']],
    ]))->assertOk();

    expect($recorder->paramsFor('POST /v1/refunds'))->toMatchArray(['amount' => 1200])
        ->and($recorder->headersFor('POST /v1/refunds'))->toContain('Idempotency-Key: managed-switch-in_managed_1-sub_managed_old-in_pro_1')
        ->and(Subscription::query()->where('stripe_id', 'sub_managed_old')->sole()->ended())->toBeTrue()
        ->and(Subscription::query()->where('stripe_id', 'sub_managed_new')->sole()->type)->toBe(SubscriptionType::Managed->value)
        ->and($recorder->countFor('DELETE /v1/subscriptions/sub_managed_new'))->toBe(0)
        ->and($workspace->refresh()->plan)->toBe(Plan::Enterprise);

    Mail::assertNotQueued(ProEndedMail::class);
});

it('cancels an unpaid managed subscription when a newer managed invoice is paid', function (): void {
    $this->travelTo(now()->startOfSecond());

    $workspace = stripeBillingWorkspace();
    pendingManagedSubscription($workspace, 'sub_managed_old', 'managed')->forceFill(['stripe_status' => 'unpaid'])->save();
    pendingManagedSubscription($workspace, 'sub_managed_new');
    $recorder = stripeManagedSwitch($workspace, plan: Plan::Enterprise, id: 'sub_managed_new')
        ->respondTo('/v1/invoices', stripePaidProInvoice(70800, [[
            'period' => ['start' => now()->subYear()->subDays(30)->getTimestamp(), 'end' => now()->subDays(30)->getTimestamp()],
            'parent' => ['type' => 'subscription_item_details', 'subscription_item_details' => ['proration' => false]],
        ]]));

    sendStripeWebhook(invoicePaidEvent($workspace, [
        'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_managed_new']],
    ]))->assertOk();

    expect($recorder->countFor('DELETE /v1/subscriptions/sub_managed_old'))->toBe(1)
        ->and($recorder->countFor('POST /v1/refunds'))->toBe(0)
        ->and(Subscription::query()->where('stripe_id', 'sub_managed_old')->sole()->ended())->toBeTrue()
        ->and($workspace->refresh()->plan)->toBe(Plan::Enterprise);
});

it('voids the open invoice of a replaced subscription before cancelling it', function (): void {
    $this->travelTo(now()->startOfSecond());

    $workspace = stripeBillingWorkspace();
    pendingManagedSubscription($workspace, 'sub_managed_old', 'managed')->forceFill(['stripe_status' => 'unpaid'])->save();
    pendingManagedSubscription($workspace, 'sub_managed_new');
    $invoices = stripePaidProInvoice(70800, [[
        'period' => ['start' => now()->subYear()->subDays(30)->getTimestamp(), 'end' => now()->subDays(30)->getTimestamp()],
        'parent' => ['type' => 'subscription_item_details', 'subscription_item_details' => ['proration' => false]],
    ]]);
    $invoices['data'][] = ['id' => 'in_old_open', 'object' => 'invoice', 'status' => 'open', 'amount_paid' => 0];
    $recorder = stripeManagedSwitch($workspace, plan: Plan::Enterprise, id: 'sub_managed_new')
        ->respondTo('/v1/invoices/in_old_open/void', ['id' => 'in_old_open', 'object' => 'invoice', 'status' => 'void'])
        ->respondTo('/v1/invoices', $invoices);

    sendStripeWebhook(invoicePaidEvent($workspace, [
        'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_managed_new']],
    ]))->assertOk();

    $writes = collect($recorder->requests)
        ->filter(fn (array $request): bool => $request['method'] !== 'GET' && str_contains($request['url'], 'old'))
        ->map(fn (array $request): string => $request['method'].' '.Str::after($request['url'], 'api.stripe.com'))
        ->values()
        ->all();

    expect($writes)->toBe(['POST /v1/invoices/in_old_open/void', 'DELETE /v1/subscriptions/sub_managed_old'])
        ->and($workspace->refresh()->plan)->toBe(Plan::Enterprise);
});

it('replaces an older managed subscription for a workspace that once paid for Pro', function (): void {
    $this->travelTo(now()->startOfSecond());

    $workspace = stripeBillingWorkspace();
    $workspace->forceFill(['plan' => Plan::Concierge])->save();
    proSubscriptionOn($workspace, status: 'canceled');
    pendingManagedSubscription($workspace, 'sub_managed_old', 'managed');
    pendingManagedSubscription($workspace, 'sub_managed_new');
    $recorder = stripeManagedSwitch($workspace, plan: Plan::Enterprise, id: 'sub_managed_new');

    sendStripeWebhook(invoicePaidEvent($workspace, [
        'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_managed_new']],
    ]))->assertOk();

    expect(Subscription::query()->where('stripe_id', 'sub_managed_old')->sole()->ended())->toBeTrue()
        ->and($recorder->countFor('DELETE /v1/subscriptions/sub_managed_old'))->toBe(1)
        ->and($workspace->refresh()->plan)->toBe(Plan::Enterprise);
});

it('grants nothing when a concurrent delivery switched the plan on first', function (): void {
    $workspace = proWorkspaceAwaitingManagedPayment();
    stripeManagedSwitch($workspace);

    Workspace::retrieved(function (Workspace $retrieved) use ($workspace): void {
        if ($retrieved->is($workspace) && DB::transactionLevel() > 1) {
            Subscription::query()->where('stripe_id', 'sub_managed_1')->update(['type' => SubscriptionType::Managed->value]);
        }
    });

    sendStripeWebhook(invoicePaidEvent($workspace))->assertOk();

    expect($workspace->refresh()->plan)->toBe(Plan::Pro)
        ->and(resetRowsFor($workspace))->toBe(0);
});

it('grants and refunds once when Stripe redelivers the paid invoice', function (): void {
    $this->travelTo(now()->startOfSecond());

    $workspace = proWorkspaceAwaitingManagedPayment();
    $recorder = stripeManagedSwitch($workspace);

    sendStripeWebhook(invoicePaidEvent($workspace))->assertOk();

    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->update([
        'credits_remaining' => Plan::Concierge->credits() - 5,
        'credits_used' => 5,
    ]);

    $recorder->respondTo('/v1/subscriptions/sub_managed_1', stripeManagedSubscriptionBody($workspace, overrides: [
        'metadata' => ['workspace_id' => (string) $workspace->getKey(), 'type' => 'managed'],
    ]));
    $afterFirstDelivery = count($recorder->requests);

    sendStripeWebhook(invoicePaidEvent($workspace))->assertOk();

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();

    expect($recorder->writesSince($afterFirstDelivery))->toBe([])
        ->and($recorder->countFor('POST /v1/refunds'))->toBe(1)
        ->and($recorder->countFor('DELETE /v1/subscriptions/sub_pro_1'))->toBe(1)
        ->and(resetRowsFor($workspace))->toBe(1)
        ->and($balance->credits_used)->toBe(5)
        ->and($workspace->refresh()->plan)->toBe(Plan::Concierge);
});

it('ignores a paid invoice that does not belong to a pending managed subscription', function (): void {
    $workspace = stripeBillingWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro])->save();
    proSubscriptionOn($workspace);
    pendingManagedSubscription($workspace, 'sub_managed_paid', 'managed');
    $recorder = StripeRecorder::install();

    $proFirstInvoice = invoicePaidEvent($workspace, [
        'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_pro_1']],
    ]);
    $proRenewal = invoicePaidEvent($workspace, [
        'billing_reason' => 'subscription_cycle',
        'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_pro_1']],
    ]);
    $managedRenewal = invoicePaidEvent($workspace, [
        'billing_reason' => 'subscription_cycle',
        'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_managed_paid']],
    ]);
    $creditPack = invoicePaidEvent($workspace, ['parent' => null]);
    $unknownSubscription = invoicePaidEvent($workspace, [
        'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_unknown']],
    ]);

    foreach ([$proFirstInvoice, $proRenewal, $managedRenewal, $creditPack, $unknownSubscription] as $event) {
        sendStripeWebhook($event)->assertOk();
    }

    expect($workspace->refresh()->plan)->toBe(Plan::Pro)
        ->and($recorder->requests)->toBe([])
        ->and(Subscription::query()->where('stripe_id', 'sub_pro_1')->sole()->ended())->toBeFalse();
});

it('ignores a paid invoice whose customer is not the workspace\'s', function (): void {
    $workspace = stripeBillingWorkspace();
    pendingManagedSubscription($workspace);
    $recorder = StripeRecorder::install();

    Log::spy();
    Exceptions::fake();

    sendStripeWebhook(invoicePaidEvent($workspace, ['customer' => 'cus_someone_else']))->assertOk();

    Exceptions::assertReported(fn (UnexpectedValueException $exception): bool => str_contains($exception->getMessage(), 'in_managed_1')
        && str_contains($exception->getMessage(), 'customer'));

    expect($workspace->refresh()->plan)->toBe(Plan::Free)
        ->and(Subscription::query()->where('stripe_id', 'sub_managed_1')->sole()->type)->toBe(SubscriptionType::ManagedPending->value)
        ->and($recorder->requests)->toBe([]);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'customer mismatch')
            && $context['customer'] === 'cus_someone_else'
            && $context['invoice_id'] === 'in_managed_1');
});

it('grants nothing when the Stripe subscription names another workspace', function (): void {
    $workspace = stripeBillingWorkspace();
    pendingManagedSubscription($workspace);
    $recorder = stripeManagedSwitch($workspace);
    $recorder->respondTo('/v1/subscriptions/sub_managed_1', stripeManagedSubscriptionBody($workspace, overrides: [
        'metadata' => ['workspace_id' => 'someone-elses-workspace', 'type' => 'managed_pending'],
    ]));

    Log::spy();
    Exceptions::fake();

    sendStripeWebhook(invoicePaidEvent($workspace))->assertOk();

    Exceptions::assertReported(fn (UnexpectedValueException $exception): bool => str_contains($exception->getMessage(), 'workspace mismatch')
        && str_contains($exception->getMessage(), 'in_managed_1'));

    expect($workspace->refresh()->plan)->toBe(Plan::Free)
        ->and(Subscription::query()->where('stripe_id', 'sub_managed_1')->sole()->type)->toBe(SubscriptionType::ManagedPending->value)
        ->and($recorder->writesSince(0))->toBe([]);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'workspace mismatch'));
});

it('reports and grants nothing when the paid subscription is already canceled at Stripe', function (): void {
    Exceptions::fake();

    $workspace = proWorkspaceAwaitingManagedPayment();
    $recorder = stripeManagedSwitch($workspace);
    $recorder->respondTo('/v1/subscriptions/sub_managed_1', stripeManagedSubscriptionBody($workspace, overrides: ['status' => 'canceled']));

    sendStripeWebhook(invoicePaidEvent($workspace))->assertOk();

    Exceptions::assertReported(fn (UnexpectedValueException $exception): bool => str_contains($exception->getMessage(), 'in_managed_1')
        && str_contains($exception->getMessage(), 'canceled'));

    expect($workspace->refresh()->plan)->toBe(Plan::Pro)
        ->and(Subscription::query()->where('stripe_id', 'sub_managed_1')->sole()->type)->toBe(SubscriptionType::ManagedPending->value)
        ->and($recorder->writesSince(0))->toBe([]);
});

it('grants the plan and retries the switch refund when the refund call fails', function (): void {
    $this->travelTo(now()->startOfSecond());

    $workspace = proWorkspaceAwaitingManagedPayment();
    $recorder = stripeManagedSwitch($workspace)
        ->respondTo('POST /v1/refunds', ['error' => ['type' => 'api_error', 'message' => 'Stripe is down']], 500);

    sendStripeWebhook(invoicePaidEvent($workspace))->assertStatus(500);

    expect($workspace->refresh()->plan)->toBe(Plan::Concierge)
        ->and(Subscription::query()->where('stripe_id', 'sub_managed_1')->sole()->type)->toBe(SubscriptionType::Managed->value)
        ->and(Subscription::query()->where('stripe_id', 'sub_pro_1')->sole()->ended())->toBeFalse()
        ->and($recorder->countFor('DELETE /v1/subscriptions/sub_pro_1'))->toBe(0);

    $this->travelTo(now()->addHour());
    $recorder->respondTo('POST /v1/refunds', ['id' => 're_1', 'object' => 'refund', 'status' => 'succeeded']);

    sendStripeWebhook(invoicePaidEvent($workspace))->assertOk();

    $attempts = $recorder->allParamsFor('POST /v1/refunds');

    expect($attempts)->toHaveCount(2)
        ->and($attempts[1])->toBe($attempts[0])
        ->and($attempts[1]['amount'])->toBe(1200)
        ->and(Subscription::query()->where('stripe_id', 'sub_pro_1')->sole()->ended())->toBeTrue()
        ->and(resetRowsFor($workspace))->toBe(1)
        ->and($workspace->refresh()->plan)->toBe(Plan::Concierge);
});

it('refunds Pro once when its cancellation fails after the refund went through', function (): void {
    $this->travelTo(now()->startOfSecond());

    $workspace = proWorkspaceAwaitingManagedPayment();
    $recorder = stripeManagedSwitch($workspace)
        ->respondTo('DELETE /v1/subscriptions/sub_pro_1', ['error' => ['type' => 'api_error', 'message' => 'Stripe is down']], 500);

    sendStripeWebhook(invoicePaidEvent($workspace))->assertStatus(500);

    expect($workspace->refresh()->plan)->toBe(Plan::Concierge)
        ->and(Subscription::query()->where('stripe_id', 'sub_pro_1')->sole()->ended())->toBeFalse();

    $key = 'managed-switch-in_managed_1-sub_pro_1-in_pro_1';

    $this->travelTo(now()->addHour());
    $recorder
        ->respondTo('GET /v1/refunds', [
            'object' => 'list',
            'has_more' => false,
            'data' => [['id' => 're_1', 'object' => 'refund', 'amount' => 1200, 'metadata' => ['switch_key' => $key]]],
        ])
        ->respondTo('DELETE /v1/subscriptions/sub_pro_1', ['id' => 'sub_pro_1', 'object' => 'subscription', 'status' => 'canceled']);

    sendStripeWebhook(invoicePaidEvent($workspace))->assertOk();

    expect($recorder->countFor('POST /v1/refunds'))->toBe(1)
        ->and(Subscription::query()->where('stripe_id', 'sub_pro_1')->sole()->ended())->toBeTrue()
        ->and($workspace->refresh()->plan)->toBe(Plan::Concierge);
});

it('leaves a subscription started after the paid invoice alone when the invoice is redelivered', function (): void {
    $this->travelTo(now()->startOfSecond());

    $workspace = stripeBillingWorkspace();
    pendingManagedSubscription($workspace);
    $recorder = stripeManagedSwitch($workspace);

    sendStripeWebhook(invoicePaidEvent($workspace))->assertOk();

    $this->travelTo(now()->addHours(2));
    proSubscriptionOn($workspace, 'sub_pro_later');
    $recorder->respondTo('/v1/subscriptions/sub_managed_1', stripeManagedSubscriptionBody($workspace, overrides: [
        'metadata' => ['workspace_id' => (string) $workspace->getKey(), 'type' => 'managed'],
    ]));
    $afterFirstDelivery = count($recorder->requests);

    sendStripeWebhook(invoicePaidEvent($workspace))->assertOk();

    expect($recorder->writesSince($afterFirstDelivery))->toBe([])
        ->and(Subscription::query()->where('stripe_id', 'sub_pro_later')->sole()->ended())->toBeFalse();
});

it('cancels nothing on a redelivery once the managed subscription itself has ended', function (): void {
    $this->travelTo(now()->startOfSecond());

    $workspace = proWorkspaceAwaitingManagedPayment();
    $recorder = stripeManagedSwitch($workspace)
        ->respondTo('DELETE /v1/subscriptions/sub_pro_1', ['error' => ['type' => 'api_error', 'message' => 'Stripe is down']], 500);

    sendStripeWebhook(invoicePaidEvent($workspace))->assertStatus(500);

    Subscription::query()->where('stripe_id', 'sub_managed_1')->update(['stripe_status' => 'canceled', 'ends_at' => now()]);
    $recorder->respondTo('/v1/subscriptions/sub_managed_1', stripeManagedSubscriptionBody($workspace, overrides: [
        'metadata' => ['workspace_id' => (string) $workspace->getKey(), 'type' => 'managed'],
    ]));
    $afterFirstDelivery = count($recorder->requests);

    sendStripeWebhook(invoicePaidEvent($workspace))->assertOk();

    expect($recorder->writesSince($afterFirstDelivery))->toBe([])
        ->and(Subscription::query()->where('stripe_id', 'sub_pro_1')->sole()->ended())->toBeFalse();
});

it('skips the refund but still switches when Stripe cannot refund the Pro invoice for good', function (Closure $arrange): void {
    $this->travelTo(now()->startOfSecond());
    Exceptions::fake();

    $workspace = proWorkspaceAwaitingManagedPayment();
    $recorder = stripeManagedSwitch($workspace);
    $arrange($recorder);

    sendStripeWebhook(invoicePaidEvent($workspace))->assertOk();

    Exceptions::assertReported(fn (UnexpectedValueException $exception): bool => str_contains($exception->getMessage(), 'in_pro_1')
        && str_contains($exception->getMessage(), 'sub_pro_1')
        && str_contains($exception->getMessage(), (string) $workspace->getKey()));

    expect($recorder->countFor('DELETE /v1/subscriptions/sub_pro_1'))->toBe(1)
        ->and(Subscription::query()->where('stripe_id', 'sub_pro_1')->sole()->ended())->toBeTrue()
        ->and($workspace->refresh()->plan)->toBe(Plan::Concierge);
})->with([
    'refund rejected' => [fn (StripeRecorder $recorder) => $recorder->respondTo(
        'POST /v1/refunds',
        ['error' => ['type' => 'invalid_request_error', 'message' => 'Charge has already been refunded.']],
        400,
    )],
    'out of band payment' => [fn (StripeRecorder $recorder) => $recorder->respondTo('/v1/invoice_payments', [
        'object' => 'list',
        'has_more' => false,
        'data' => [['id' => 'inpay_1', 'object' => 'invoice_payment', 'payment' => ['type' => 'out_of_band_payment']]],
    ])],
    'no subscription period' => [fn (StripeRecorder $recorder) => $recorder->respondTo('/v1/invoices', stripePaidProInvoice(2400, lines: [
        [
            'period' => ['start' => now()->subDays(1)->getTimestamp(), 'end' => now()->addDays(1)->getTimestamp()],
            'parent' => ['type' => 'subscription_item_details', 'subscription_item_details' => ['proration' => true]],
        ],
    ]))],
]);

it('retries the switch when Stripe fails the refund for a passing reason', function (int $status, array|string $body): void {
    $this->travelTo(now()->startOfSecond());

    $workspace = proWorkspaceAwaitingManagedPayment();
    $recorder = stripeManagedSwitch($workspace)->respondTo('POST /v1/refunds', $body, $status);

    sendStripeWebhook(invoicePaidEvent($workspace))->assertStatus(500);

    expect($recorder->countFor('DELETE /v1/subscriptions/sub_pro_1'))->toBe(0)
        ->and(Subscription::query()->where('stripe_id', 'sub_pro_1')->sole()->ended())->toBeFalse();
})->with([
    'rate limited' => [429, ['error' => ['type' => 'invalid_request_error', 'code' => 'rate_limit', 'message' => 'Too many requests']]],
    'garbled response' => [200, 'not json'],
]);

it('cancels nothing on a retry once Stripe has canceled the managed subscription', function (): void {
    $this->travelTo(now()->startOfSecond());

    $workspace = proWorkspaceAwaitingManagedPayment();
    $recorder = stripeManagedSwitch($workspace)
        ->respondTo('DELETE /v1/subscriptions/sub_pro_1', ['error' => ['type' => 'api_error', 'message' => 'Stripe is down']], 500);

    sendStripeWebhook(invoicePaidEvent($workspace))->assertStatus(500);

    $recorder->respondTo('/v1/subscriptions/sub_managed_1', stripeManagedSubscriptionBody($workspace, overrides: [
        'status' => 'canceled',
        'metadata' => ['workspace_id' => (string) $workspace->getKey(), 'type' => 'managed'],
    ]));
    $afterFirstDelivery = count($recorder->requests);

    sendStripeWebhook(invoicePaidEvent($workspace))->assertOk();

    expect($recorder->writesSince($afterFirstDelivery))->toBe([])
        ->and(Subscription::query()->where('stripe_id', 'sub_pro_1')->sole()->ended())->toBeFalse();
});

it('remembers the renewal date and amount for the billing page', function (): void {
    $this->travelTo(now()->startOfSecond());

    $workspace = stripeBillingWorkspace();
    pendingManagedSubscription($workspace);
    stripeManagedSwitch($workspace);

    sendStripeWebhook(invoicePaidEvent($workspace))->assertOk();

    $renewsAt = stripeManagedSubscriptionBody($workspace)['items']['data'][0]['current_period_end'];

    expect(Cache::get(RefreshManagedRenewal::CACHE_PREFIX.'sub_managed_1'))
        ->toBe(['renews_at' => $renewsAt, 'amount' => 76464, 'currency' => 'usd']);
});

it('subscribes the Stripe endpoint to paid invoices', function (): void {
    expect(config('cashier.webhook.events'))->toContain('invoice.paid');
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

function managedSubscriptionOn(Workspace $workspace, Plan $plan = Plan::Concierge, string $stripeId = 'sub_managed_1', string $type = 'managed', string $status = 'active'): Subscription
{
    config()->set('services.stripe.products.concierge', 'prod_concierge_test');
    config()->set('services.stripe.products.enterprise', 'prod_enterprise_test');

    $subscription = pendingManagedSubscription($workspace, $stripeId, $type);
    $subscription->forceFill(['stripe_status' => $status])->save();
    $subscription->items()->create([
        'stripe_id' => "si_{$stripeId}",
        'stripe_product' => $plan->stripeProduct(),
        'stripe_price' => $subscription->stripe_price,
        'quantity' => 1,
    ]);

    return $subscription;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function managedSubscriptionEvent(Workspace $workspace, string $event, Plan $plan = Plan::Concierge, string $stripeId = 'sub_managed_1', array $overrides = []): array
{
    config()->set('services.stripe.products.concierge', 'prod_concierge_test');
    config()->set('services.stripe.products.enterprise', 'prod_enterprise_test');

    $payload = stripeSubscriptionEvent($workspace, $event, array_merge([
        'id' => $stripeId,
        'price' => "price_inline_{$stripeId}",
        'metadata' => ['type' => 'managed', 'workspace_id' => (string) $workspace->getKey()],
    ], $overrides));

    $payload['data']['object']['items']['data'][0]['id'] = "si_{$stripeId}";
    $payload['data']['object']['items']['data'][0]['price']['product'] = $plan->stripeProduct();

    return $payload;
}

it('voids the open invoice of a managed subscription Stripe cancels, so it can no longer be paid', function (): void {
    Mail::fake();
    $workspace = stripeBillingWorkspace();
    $workspace->forceFill(['plan' => Plan::Concierge])->save();
    managedSubscriptionOn($workspace);
    $stripe = StripeRecorder::install()
        ->respondTo('GET /v1/invoices', ['object' => 'list', 'has_more' => false, 'data' => [['id' => 'in_renewal', 'object' => 'invoice', 'status' => 'open']]]);

    sendStripeWebhook(managedSubscriptionEvent($workspace, 'deleted', overrides: [
        'status' => 'canceled',
        'ended_at' => now()->getTimestamp(),
    ]))->assertSuccessful();

    expect($stripe->countFor('POST /v1/invoices/in_renewal/void'))->toBe(1)
        ->and($workspace->refresh()->plan)->toBe(Plan::Free);
});

it('reports a renewal paid on a managed subscription that already ended', function (): void {
    Exceptions::fake();
    $workspace = stripeBillingWorkspace();
    managedSubscriptionOn($workspace, status: 'canceled')->forceFill(['ends_at' => now()->subDay()])->save();

    sendStripeWebhook(invoicePaidEvent($workspace, ['id' => 'in_late_renewal', 'billing_reason' => 'subscription_cycle']))->assertOk();

    Exceptions::assertReported(fn (UnexpectedValueException $exception): bool => str_contains($exception->getMessage(), 'in_late_renewal')
        && str_contains($exception->getMessage(), 'sub_managed_1')
        && str_contains($exception->getMessage(), (string) $workspace->getKey()));
    expect($workspace->refresh()->plan)->toBe(Plan::Free);
});

it('drops a lapsed Concierge workspace to Free and tells the owner', function (): void {
    Mail::fake();
    Feature::define(BillingFeature::class, true);
    $workspace = stripeBillingWorkspace();
    $workspace->forceFill(['plan' => Plan::Concierge])->save();
    managedSubscriptionOn($workspace);

    expect(resolve(HostedWorkspaceAccess::class)->allows($workspace->refresh()))->toBeTrue();

    sendStripeWebhook(managedSubscriptionEvent($workspace, 'deleted', overrides: [
        'status' => 'canceled',
        'ended_at' => now()->getTimestamp(),
    ]))->assertSuccessful();

    expect($workspace->refresh()->plan)->toBe(Plan::Free)
        ->and(resolve(HostedWorkspaceAccess::class)->allows($workspace))->toBeFalse();

    Mail::assertQueued(ProEndedMail::class, 1);
    Mail::assertQueued(ProEndedMail::class, fn (ProEndedMail $mail): bool => $mail->hasTo($workspace->owner->email)
        && $mail->envelope()->subject === 'Your Cloud Concierge plan has ended');
});

it('keeps a past-due Concierge workspace on its plan', function (): void {
    Mail::fake();
    Feature::define(BillingFeature::class, true);
    $workspace = stripeBillingWorkspace();
    $workspace->forceFill(['plan' => Plan::Concierge])->save();
    managedSubscriptionOn($workspace);

    sendStripeWebhook(managedSubscriptionEvent($workspace, 'updated', overrides: ['status' => 'past_due']))->assertSuccessful();

    expect($workspace->refresh()->plan)->toBe(Plan::Concierge)
        ->and(resolve(HostedWorkspaceAccess::class)->allows($workspace))->toBeTrue();

    Mail::assertNotQueued(ProEndedMail::class);
});

it('leaves a hand-granted plan alone when an unpaid managed invoice is cancelled', function (): void {
    Mail::fake();
    $workspace = stripeBillingWorkspace();
    $workspace->forceFill(['plan' => Plan::Enterprise])->save();
    managedSubscriptionOn($workspace, Plan::Enterprise, type: 'managed_pending');

    sendStripeWebhook(managedSubscriptionEvent($workspace, 'deleted', Plan::Enterprise, overrides: [
        'status' => 'canceled',
        'ended_at' => now()->getTimestamp(),
        'metadata' => ['type' => 'managed_pending', 'workspace_id' => (string) $workspace->getKey()],
    ]))->assertSuccessful();

    expect($workspace->refresh()->plan)->toBe(Plan::Enterprise);

    Mail::assertNotQueued(ProEndedMail::class);
});

it('grants nothing when a subscription that is still unpaid reports an update', function (): void {
    $workspace = stripeBillingWorkspace();
    managedSubscriptionOn($workspace, Plan::Enterprise, type: 'managed_pending');

    sendStripeWebhook(managedSubscriptionEvent($workspace, 'updated', Plan::Enterprise, overrides: [
        'metadata' => ['type' => 'managed_pending', 'workspace_id' => (string) $workspace->getKey()],
    ]))->assertSuccessful();

    expect($workspace->refresh()->plan)->toBe(Plan::Free);
});

it('restores a managed plan when Stripe marks it unpaid and a payment later brings it back', function (): void {
    Mail::fake();
    Feature::define(BillingFeature::class, true);
    $workspace = stripeBillingWorkspace();
    $workspace->forceFill(['plan' => Plan::Concierge])->save();
    managedSubscriptionOn($workspace);

    sendStripeWebhook(managedSubscriptionEvent($workspace, 'updated', overrides: ['status' => 'unpaid']))->assertSuccessful();

    expect($workspace->refresh()->plan)->toBe(Plan::Free);

    sendStripeWebhook(managedSubscriptionEvent($workspace, 'updated', overrides: ['status' => 'active']))->assertSuccessful();

    expect($workspace->refresh()->plan)->toBe(Plan::Concierge)
        ->and($workspace->aiCreditBalance->credits_remaining)->toBe(Plan::Concierge->credits());
});

it('keeps a hand-granted Enterprise plan when a valid Concierge subscription reports an update', function (): void {
    $workspace = stripeBillingWorkspace();
    $workspace->forceFill(['plan' => Plan::Enterprise])->save();
    managedSubscriptionOn($workspace);

    sendStripeWebhook(managedSubscriptionEvent($workspace, 'updated'))->assertSuccessful();

    expect($workspace->refresh()->plan)->toBe(Plan::Enterprise);
});

it('does not end the plan when a replaced managed subscription is deleted', function (Plan $oldPlan, Plan $newPlan): void {
    $this->travelTo(now()->startOfSecond());
    Mail::fake();

    $workspace = stripeBillingWorkspace();
    $workspace->forceFill(['plan' => $oldPlan])->save();
    managedSubscriptionOn($workspace, $oldPlan, 'sub_managed_old');
    managedSubscriptionOn($workspace, $newPlan, 'sub_managed_new', 'managed_pending');
    stripeManagedSwitch($workspace, plan: $newPlan, id: 'sub_managed_new');

    sendStripeWebhook(invoicePaidEvent($workspace, [
        'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_managed_new']],
    ]))->assertOk();

    expect($workspace->refresh()->plan)->toBe($newPlan)
        ->and(Subscription::query()->where('stripe_id', 'sub_managed_old')->sole()->ended())->toBeTrue();

    sendStripeWebhook(managedSubscriptionEvent($workspace, 'deleted', $oldPlan, 'sub_managed_old', [
        'status' => 'canceled',
        'ended_at' => now()->getTimestamp(),
    ]))->assertSuccessful();

    expect($workspace->refresh()->plan)->toBe($newPlan);

    Mail::assertNotQueued(ProEndedMail::class);
})->with([
    'another plan' => [Plan::Concierge, Plan::Enterprise],
    'a new price' => [Plan::Concierge, Plan::Concierge],
]);

it('ends a Concierge plan even when another workspace holds a valid managed subscription', function (): void {
    Mail::fake();
    $workspace = stripeBillingWorkspace();
    $workspace->forceFill(['plan' => Plan::Concierge])->save();
    managedSubscriptionOn($workspace, stripeId: 'sub_managed_mine');

    $other = stripeBillingWorkspace();
    $other->forceFill(['plan' => Plan::Concierge])->save();
    managedSubscriptionOn($other, stripeId: 'sub_managed_other');

    sendStripeWebhook(managedSubscriptionEvent($workspace, 'deleted', stripeId: 'sub_managed_mine', overrides: [
        'status' => 'canceled',
        'ended_at' => now()->getTimestamp(),
    ]))->assertSuccessful();

    expect($workspace->refresh()->plan)->toBe(Plan::Free)
        ->and($other->refresh()->plan)->toBe(Plan::Concierge);

    Mail::assertQueued(ProEndedMail::class, 1);
    Mail::assertQueued(ProEndedMail::class, fn (ProEndedMail $mail): bool => $mail->hasTo($workspace->owner->email)
        && $mail->envelope()->subject === 'Your Cloud Concierge plan has ended');
});

/** @return array<string, mixed> */
function managedBecamePastDueEvent(Workspace $workspace, Plan $plan = Plan::Concierge): array
{
    $payload = managedSubscriptionEvent($workspace, 'updated', $plan, overrides: ['status' => 'past_due']);
    $payload['data']['previous_attributes'] = ['status' => 'active'];

    return $payload;
}

it('tells the owner once when a managed invoice becomes overdue', function (): void {
    $workspace = stripeBillingWorkspace();
    $workspace->forceFill(['plan' => Plan::Concierge])->save();
    managedSubscriptionOn($workspace);

    sendStripeWebhook(managedBecamePastDueEvent($workspace))->assertSuccessful();
    sendStripeWebhook(managedBecamePastDueEvent($workspace))->assertSuccessful();

    $row = $workspace->owner->notifications()->sole();
    $notification = ManagedInvoiceOverdueNotification::present($row);

    expect($row->type)->toBe(ManagedInvoiceOverdueNotification::class)
        ->and($row->workspace_id)->toBeNull()
        ->and($row->reason)->toBe(NotificationReason::System)
        ->and($row->group_key)->toBe("managed-overdue:{$workspace->getKey()}")
        ->and($row->data)->toMatchArray(['workspace_id' => $workspace->getKey(), 'plan' => 'concierge'])
        ->and($notification->getTitle())->toBe(__('billing.managed.overdue_title', ['plan' => 'Cloud Concierge']))
        ->and($notification->getBody())->toContain('Pay it from '.__('billing.manage.button').'.')
        ->and($notification->getBody())->toContain($workspace->name)
        ->and($notification->getActions()[0]->getUrl())->toBe(Billing::getUrl(panel: 'app', tenant: $workspace));
});

it('stays quiet about an overdue invoice for a plan the workspace does not hold', function (string $type, Plan $held): void {
    $workspace = stripeBillingWorkspace();
    $workspace->forceFill(['plan' => $held])->save();
    managedSubscriptionOn($workspace, Plan::Concierge, type: $type);

    $payload = managedBecamePastDueEvent($workspace);
    $payload['data']['object']['metadata']['type'] = $type;

    sendStripeWebhook($payload)->assertSuccessful();

    expect($workspace->owner->notifications()->count())->toBe(0);
})->with([
    'a first invoice still unpaid' => ['managed_pending', Plan::Concierge],
    'a different managed plan' => ['managed', Plan::Enterprise],
]);

it('stays quiet when a managed subscription was already past due', function (): void {
    $workspace = stripeBillingWorkspace();
    $workspace->forceFill(['plan' => Plan::Concierge])->save();
    managedSubscriptionOn($workspace, status: 'past_due');

    $payload = managedBecamePastDueEvent($workspace);
    $payload['data']['previous_attributes'] = ['status' => 'past_due'];

    sendStripeWebhook($payload)->assertSuccessful();

    expect($workspace->owner->notifications()->count())->toBe(0);
});

it('stays quiet about an update that does not change the status', function (): void {
    $workspace = stripeBillingWorkspace();
    $workspace->forceFill(['plan' => Plan::Concierge])->save();
    managedSubscriptionOn($workspace, status: 'past_due');

    sendStripeWebhook(managedSubscriptionEvent($workspace, 'updated', overrides: ['status' => 'past_due']))->assertSuccessful();

    expect($workspace->owner->notifications()->count())->toBe(0);
});

it('remembers the renewal date and the total Stripe will invoice, tax included, when a managed subscription updates', function (): void {
    $workspace = stripeBillingWorkspace();
    $workspace->forceFill(['plan' => Plan::Concierge])->save();
    managedSubscriptionOn($workspace);

    $payload = managedSubscriptionEvent($workspace, 'updated');
    $payload['data']['object']['items']['data'][0]['current_period_end'] = 1_800_000_000;

    sendStripeWebhook($payload, stripeAlsoAnswers: ['POST /v1/invoices/create_preview' => upcomingManagedInvoice(76464)])->assertSuccessful();

    expect(Cache::get(RefreshManagedRenewal::CACHE_PREFIX.'sub_managed_1'))
        ->toBe(['renews_at' => 1_800_000_000, 'amount' => 76464, 'currency' => 'usd']);
});

it('forgets the remembered renewal once Stripe no longer bills the managed subscription', function (): void {
    $workspace = stripeBillingWorkspace();
    $workspace->forceFill(['plan' => Plan::Concierge])->save();
    managedSubscriptionOn($workspace);
    Cache::forever(RefreshManagedRenewal::CACHE_PREFIX.'sub_managed_1', ['renews_at' => 1_800_000_000, 'amount' => 70800, 'currency' => 'usd']);

    sendStripeWebhook(managedSubscriptionEvent($workspace, 'updated', overrides: ['status' => 'unpaid']))->assertSuccessful();

    expect(Cache::get(RefreshManagedRenewal::CACHE_PREFIX.'sub_managed_1'))->toBeNull();
});

/** @return array<string, mixed> */
function upcomingManagedInvoice(int $total): array
{
    return ['object' => 'invoice', 'total' => $total, 'currency' => 'usd'];
}

it('remembers no renewal for a Pro subscription update', function (): void {
    $workspace = stripeBillingWorkspace();
    proSubscriptionOn($workspace, 'sub_test_1');

    $payload = stripeSubscriptionEvent($workspace, 'updated');
    $payload['data']['object']['items']['data'][0]['current_period_end'] = 1_800_000_000;
    $payload['data']['object']['items']['data'][0]['price'] += ['unit_amount' => 1900, 'currency' => 'usd'];

    sendStripeWebhook($payload)->assertSuccessful();

    expect(Cache::get(RefreshManagedRenewal::CACHE_PREFIX.'sub_test_1'))->toBeNull();
});
