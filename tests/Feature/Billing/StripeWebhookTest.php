<?php

declare(strict_types=1);

use App\Actions\Billing\GrantPurchasedCredits;
use App\Actions\Billing\StartProTrial;
use App\Actions\Billing\SyncTeamPlanFromSubscription;
use App\Enums\Plan;
use App\Listeners\Billing\SyncPlanOnStripeSubscriptionChange;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Subscription;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditService;

mutates(SyncTeamPlanFromSubscription::class);
mutates(SyncPlanOnStripeSubscriptionChange::class);
mutates(GrantPurchasedCredits::class);

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

it('rejects an unsigned webhook when no webhook secret is configured', function (): void {
    config()->set('cashier.webhook.secret', null);

    $team = stripeBillingTeam();
    $body = json_encode(stripeSubscriptionEvent($team, 'created'), JSON_THROW_ON_ERROR);

    $response = test()->call('POST', '/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    expect($response->getStatusCode())->toBeGreaterThanOrEqual(400)
        ->and(Subscription::query()->where('stripe_id', 'sub_test_1')->exists())->toBeFalse()
        ->and($team->refresh()->plan)->toBe(Plan::Free);
});

it('does not consume the generic trial when a checkout is abandoned as incomplete', function (): void {
    $team = stripeBillingTeam();
    $trialEndsAt = now()->addDays(10)->startOfSecond();
    $team->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => $trialEndsAt])->save();

    sendStripeWebhook(stripeSubscriptionEvent($team, 'created', ['status' => 'incomplete']))->assertSuccessful();

    $team->refresh();

    expect($team->plan)->toBe(Plan::Pro)
        ->and($team->trial_ends_at?->timestamp)->toBe($trialEndsAt->timestamp)
        ->and($team->onGenericTrial())->toBeTrue();
});

it('never double-grants across a mid-trial conversion', function (): void {
    test()->travelTo(new DateTimeImmutable('2026-06-25 12:00:00', new DateTimeZone('UTC')));

    $user = User::factory()->withPersonalTeam()->create();
    /** @var Team $team */
    $team = $user->currentTeam;
    $team->forceFill(['stripe_id' => 'cus_convert_anchor'])->save();
    app(StartProTrial::class)->execute($user, $team);

    // Convert mid-trial. The plan is already Pro, so SyncTeamPlanFromSubscription
    // short-circuits: NO new grant at conversion — the trial allowance keeps running.
    test()->travelTo(new DateTimeImmutable('2026-07-01 12:00:00', new DateTimeZone('UTC')));
    sendStripeWebhook(stripeSubscriptionEvent($team->refresh(), 'created'))->assertSuccessful();

    $grantsQuery = AiCreditTransaction::query()
        ->where('team_id', $team->getKey())
        ->where('metadata->action', 'reset_period');

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();
    expect($balance->period_ends_at->toDateTimeString())->toBe('2026-07-09 12:00:00')
        ->and($grantsQuery->count())->toBe(1);

    // When the trial-shaped period lapses, the sweep re-anchors to the subscription.
    test()->travelTo(new DateTimeImmutable('2026-07-10 12:00:00', new DateTimeZone('UTC')));
    test()->artisan('chat:reset-credits')->assertSuccessful();

    $balance->refresh();
    expect($balance->period_starts_at->toDateTimeString())->toBe('2026-07-01 12:00:00')
        ->and($balance->period_ends_at->toDateTimeString())->toBe('2026-08-01 12:00:00')
        ->and($grantsQuery->count())->toBe(2); // trial start + first anniversary cycle — never a third
});

/** @return array<string, mixed> */
function checkoutSessionCompletedEvent(Team $team, array $overrides = []): array
{
    return [
        'id' => 'evt_checkout_test',
        'type' => 'checkout.session.completed',
        'data' => ['object' => array_merge([
            'id' => 'cs_test_pack_1',
            'object' => 'checkout.session',
            'mode' => 'payment',
            'payment_status' => 'paid',
            'customer' => $team->stripe_id,
            'metadata' => [
                'team_id' => (string) $team->getKey(),
                'credit_pack_price' => 'price_credits_1k_test',
            ],
        ], $overrides)],
    ];
}

/** @return array<string, mixed> */
function checkoutSessionAsyncPaymentSucceededEvent(Team $team, array $overrides = []): array
{
    $event = checkoutSessionCompletedEvent($team, $overrides);
    $event['type'] = 'checkout.session.async_payment_succeeded';
    $event['data']['object']['payment_status'] = 'paid';

    return $event;
}

it('grants pack credits exactly once on checkout session completed', function (): void {
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);

    $user = User::factory()->withPersonalTeam()->create();
    /** @var Team $team */
    $team = $user->currentTeam;
    $team->forceFill(['stripe_id' => 'cus_pack_test'])->save();

    sendStripeWebhook(checkoutSessionCompletedEvent($team))->assertOk();
    sendStripeWebhook(checkoutSessionCompletedEvent($team))->assertOk(); // replay

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();
    expect($balance->purchased_credits)->toBe(1000);
});

it('grants nothing for an unpaid checkout session completed event', function (): void {
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);

    $user = User::factory()->withPersonalTeam()->create();
    /** @var Team $team */
    $team = $user->currentTeam;
    $team->forceFill(['stripe_id' => 'cus_pack_unpaid'])->save();

    sendStripeWebhook(checkoutSessionCompletedEvent($team, ['payment_status' => 'unpaid']))->assertOk();

    expect(AiCreditBalance::query()->where('team_id', $team->getKey())->value('purchased_credits') ?? 0)->toBe(0);
});

it('grants pack credits once the delayed payment confirms asynchronously', function (): void {
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);

    $user = User::factory()->withPersonalTeam()->create();
    /** @var Team $team */
    $team = $user->currentTeam;
    $team->forceFill(['stripe_id' => 'cus_pack_async'])->save();

    sendStripeWebhook(checkoutSessionAsyncPaymentSucceededEvent($team))->assertOk();

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();
    expect($balance->purchased_credits)->toBe(1000);
});

it('grants exactly once when an unpaid checkout later confirms asynchronously', function (): void {
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);

    $user = User::factory()->withPersonalTeam()->create();
    /** @var Team $team */
    $team = $user->currentTeam;
    $team->forceFill(['stripe_id' => 'cus_pack_delayed'])->save();

    sendStripeWebhook(checkoutSessionCompletedEvent($team, ['payment_status' => 'unpaid']))->assertOk();
    sendStripeWebhook(checkoutSessionAsyncPaymentSucceededEvent($team))->assertOk();
    sendStripeWebhook(checkoutSessionAsyncPaymentSucceededEvent($team))->assertOk(); // replay

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();
    expect($balance->purchased_credits)->toBe(1000);

    $grantsQuery = AiCreditTransaction::query()
        ->where('team_id', $team->getKey())
        ->where('idempotency_key', 'pack-cs_test_pack_1');

    expect($grantsQuery->count())->toBe(1);
});

it('ignores subscription-mode checkout sessions', function (): void {
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);

    $user = User::factory()->withPersonalTeam()->create();
    /** @var Team $team */
    $team = $user->currentTeam;
    $team->forceFill(['stripe_id' => 'cus_pack_sub'])->save();

    sendStripeWebhook(checkoutSessionCompletedEvent($team, ['mode' => 'subscription']))->assertOk();

    expect(AiCreditBalance::query()->where('team_id', $team->getKey())->value('purchased_credits') ?? 0)->toBe(0);
});

it('grants nothing when the session customer does not match the team', function (): void {
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);

    $user = User::factory()->withPersonalTeam()->create();
    /** @var Team $team */
    $team = $user->currentTeam;
    $team->forceFill(['stripe_id' => 'cus_real'])->save();

    sendStripeWebhook(checkoutSessionCompletedEvent($team, ['customer' => 'cus_attacker']))->assertOk();

    expect(AiCreditBalance::query()->where('team_id', $team->getKey())->value('purchased_credits') ?? 0)->toBe(0);
});

it('grants nothing for an unknown pack price', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    /** @var Team $team */
    $team = $user->currentTeam;
    $team->forceFill(['stripe_id' => 'cus_pack_unknown'])->save();

    sendStripeWebhook(checkoutSessionCompletedEvent($team, [
        'metadata' => ['team_id' => (string) $team->getKey(), 'credit_pack_price' => 'price_nonexistent'],
    ]))->assertOk();

    expect(AiCreditBalance::query()->where('team_id', $team->getKey())->value('purchased_credits') ?? 0)->toBe(0);
});

it('logs and grants nothing when a payment-mode session is missing pack metadata', function (): void {
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);

    $user = User::factory()->withPersonalTeam()->create();
    /** @var Team $team */
    $team = $user->currentTeam;
    $team->forceFill(['stripe_id' => 'cus_pack_missing_meta'])->save();

    Log::spy();

    // credit_pack_price is well-formed and configured; team_id is missing. This
    // must trip the metadata guard specifically, not the unknown-price branch
    // (which is never reached) or the customer-mismatch branch (which requires
    // a resolved team, and this metadata never resolves one).
    sendStripeWebhook(checkoutSessionCompletedEvent($team, [
        'metadata' => ['credit_pack_price' => 'price_credits_1k_test'],
    ]))->assertOk();

    expect(AiCreditBalance::query()->where('team_id', $team->getKey())->value('purchased_credits') ?? 0)->toBe(0);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'missing or malformed metadata')
            && $context['session_id'] === 'cs_test_pack_1'
            && in_array('metadata.team_id', $context['missing_fields'], true)
            && ! in_array('metadata.credit_pack_price', $context['missing_fields'], true)
        );
});
