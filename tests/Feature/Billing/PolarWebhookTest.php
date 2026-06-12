<?php

declare(strict_types=1);

use App\Actions\Billing\SyncTeamPlanFromSubscription;
use App\Enums\Plan;
use App\Listeners\Billing\SyncPlanOnPolarSubscriptionChange;
use App\Models\Team;
use App\Models\User;
use Danestves\LaravelPolar\Subscription;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;
use StandardWebhooks\Webhook;

mutates(SyncTeamPlanFromSubscription::class);
mutates(SyncPlanOnPolarSubscriptionChange::class);

beforeEach(function (): void {
    config()->set('webhook-client.configs.0.signing_secret', 'test-polar-secret');
    config()->set('services.polar.products.pro', 'prod_pro_test');
    config()->set('services.polar.products.enterprise', 'prod_enterprise_test');
});

function sendPolarWebhook(array $payload, string $secret = 'test-polar-secret'): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $messageId = 'msg_'.Str::ulid();
    $timestamp = (string) now()->getTimestamp();

    $signature = (new Webhook(base64_encode($secret)))->sign($messageId, $timestamp, $body);

    return test()->call('POST', '/polar/webhook', [], [], [], [
        'HTTP_WEBHOOK_ID' => $messageId,
        'HTTP_WEBHOOK_TIMESTAMP' => $timestamp,
        'HTTP_WEBHOOK_SIGNATURE' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function polarSubscriptionEvent(Team $team, string $event, array $overrides = []): array
{
    $now = now();
    $productId = $overrides['product_id'] ?? 'prod_pro_test';

    $data = array_merge([
        'id' => 'sub_test_1',
        'created_at' => $now->toIso8601String(),
        'modified_at' => null,
        'amount' => 1900,
        'currency' => 'usd',
        'recurring_interval' => 'month',
        'recurring_interval_count' => 1,
        'status' => 'active',
        'current_period_start' => $now->toIso8601String(),
        'current_period_end' => $now->copy()->addMonth()->toIso8601String(),
        'cancel_at_period_end' => false,
        'canceled_at' => null,
        'started_at' => $now->toIso8601String(),
        'ends_at' => null,
        'ended_at' => null,
        'customer_id' => 'cus_test_1',
        'product_id' => $productId,
        'discount_id' => null,
        'checkout_id' => null,
        'customer_cancellation_reason' => null,
        'customer_cancellation_comment' => null,
        'metadata' => [],
        'custom_field_data' => [],
        'customer' => [
            'id' => 'cus_test_1',
            'created_at' => $now->toIso8601String(),
            'modified_at' => null,
            'metadata' => [
                'billable_id' => (string) $team->getKey(),
                'billable_type' => $team->getMorphClass(),
            ],
            'external_id' => null,
            'email' => 'billing@example.test',
            'email_verified' => true,
            'name' => 'Billing Manager',
            'billing_address' => null,
            'tax_id' => null,
            'organization_id' => 'org_test_1',
            'deleted_at' => null,
            'avatar_url' => 'https://example.test/avatar.png',
        ],
        'product' => [
            'id' => $productId,
            'created_at' => $now->toIso8601String(),
            'modified_at' => null,
            'name' => 'Relaticle Pro',
            'description' => null,
            'recurring_interval' => 'month',
            'is_recurring' => true,
            'is_archived' => false,
            'organization_id' => 'org_test_1',
            'metadata' => [],
            'prices' => [],
            'benefits' => [],
            'medias' => [],
            'attached_custom_fields' => [],
        ],
        'prices' => [],
        'meters' => [],
        'seats' => null,
        'trial_start' => null,
        'trial_end' => null,
    ], $overrides);

    return [
        'type' => "subscription.{$event}",
        'timestamp' => $now->toIso8601String(),
        'data' => $data,
    ];
}

function billingTeam(): Team
{
    /** @var Team $team */
    $team = User::factory()->withPersonalTeam()->create()->currentTeam;

    return $team;
}

it('keeps the team on Free while the subscription is incomplete', function (): void {
    $team = billingTeam();

    sendPolarWebhook(polarSubscriptionEvent($team, 'created', ['status' => 'incomplete']))->assertSuccessful();

    expect($team->refresh()->plan)->toBe(Plan::Free)
        ->and(Subscription::query()->where('polar_id', 'sub_test_1')->exists())->toBeTrue();
});

it('upgrades the team to Pro and grants the allowance when the subscription activates', function (): void {
    $team = billingTeam();

    sendPolarWebhook(polarSubscriptionEvent($team, 'created', ['status' => 'incomplete']))->assertSuccessful();
    sendPolarWebhook(polarSubscriptionEvent($team, 'active'))->assertSuccessful();

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();

    expect($team->refresh()->plan)->toBe(Plan::Pro)
        ->and($balance->credits_remaining)->toBe(Plan::Pro->credits())
        ->and($balance->credits_used)->toBe(0);
});

it('does not re-reset usage when the same active webhook is replayed', function (): void {
    $team = billingTeam();

    sendPolarWebhook(polarSubscriptionEvent($team, 'created', ['status' => 'incomplete']))->assertSuccessful();
    sendPolarWebhook(polarSubscriptionEvent($team, 'active'))->assertSuccessful();

    AiCreditBalance::query()->where('team_id', $team->getKey())->update([
        'credits_remaining' => Plan::Pro->credits() - 5,
        'credits_used' => 5,
    ]);

    sendPolarWebhook(polarSubscriptionEvent($team, 'active'))->assertSuccessful();

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();

    expect($team->refresh()->plan)->toBe(Plan::Pro)
        ->and($balance->credits_remaining)->toBe(Plan::Pro->credits() - 5)
        ->and($balance->credits_used)->toBe(5);
});

it('switches the plan when the subscription moves to another mapped product', function (): void {
    $team = billingTeam();

    sendPolarWebhook(polarSubscriptionEvent($team, 'created', ['status' => 'incomplete']))->assertSuccessful();
    sendPolarWebhook(polarSubscriptionEvent($team, 'active'))->assertSuccessful();
    sendPolarWebhook(polarSubscriptionEvent($team, 'updated', ['product_id' => 'prod_enterprise_test']))->assertSuccessful();

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();

    expect($team->refresh()->plan)->toBe(Plan::Enterprise)
        ->and($balance->credits_remaining)->toBe(Plan::Enterprise->credits());
});

it('downgrades the team to Free when the subscription is revoked', function (): void {
    $team = billingTeam();

    sendPolarWebhook(polarSubscriptionEvent($team, 'created', ['status' => 'incomplete']))->assertSuccessful();
    sendPolarWebhook(polarSubscriptionEvent($team, 'active'))->assertSuccessful();

    sendPolarWebhook(polarSubscriptionEvent($team, 'revoked', [
        'status' => 'canceled',
        'ends_at' => now()->subMinute()->toIso8601String(),
        'ended_at' => now()->subMinute()->toIso8601String(),
    ]))->assertSuccessful();

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();

    expect($team->refresh()->plan)->toBe(Plan::Free)
        ->and($balance->credits_remaining)->toBe(Plan::Free->credits());
});

it('preserves a sysadmin-granted plan when an unrelated subscription is revoked', function (): void {
    $team = billingTeam();

    sendPolarWebhook(polarSubscriptionEvent($team, 'created', ['status' => 'incomplete']))->assertSuccessful();
    sendPolarWebhook(polarSubscriptionEvent($team, 'active'))->assertSuccessful();

    $team->refresh();
    $team->plan = Plan::Enterprise;
    $team->save();
    app(CreditService::class)->resetPeriod($team);

    sendPolarWebhook(polarSubscriptionEvent($team, 'revoked', [
        'status' => 'canceled',
        'ends_at' => now()->subMinute()->toIso8601String(),
        'ended_at' => now()->subMinute()->toIso8601String(),
    ]))->assertSuccessful();

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();

    expect($team->refresh()->plan)->toBe(Plan::Enterprise)
        ->and($balance->credits_remaining)->toBe(Plan::Enterprise->credits());
});

it('leaves the plan untouched for a product that maps to no plan', function (): void {
    $team = billingTeam();

    sendPolarWebhook(polarSubscriptionEvent($team, 'created', ['status' => 'incomplete', 'product_id' => 'prod_unknown']))->assertSuccessful();
    sendPolarWebhook(polarSubscriptionEvent($team, 'active', ['product_id' => 'prod_unknown']))->assertSuccessful();

    expect($team->refresh()->plan)->toBe(Plan::Free);
});

it('rejects a webhook with an invalid signature', function (): void {
    $team = billingTeam();

    $response = sendPolarWebhook(polarSubscriptionEvent($team, 'active'), secret: 'wrong-secret');

    expect($response->status())->toBeGreaterThanOrEqual(400)
        ->and(Subscription::query()->where('polar_id', 'sub_test_1')->exists())->toBeFalse()
        ->and($team->refresh()->plan)->toBe(Plan::Free);
});
