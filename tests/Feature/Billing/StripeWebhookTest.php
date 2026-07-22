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
