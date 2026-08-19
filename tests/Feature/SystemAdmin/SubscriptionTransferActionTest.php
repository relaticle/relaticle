<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\Team;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Laravel\Cashier\Subscription;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\SystemAdmin\Actions\TransferWorkspaceBilling;
use Relaticle\SystemAdmin\Exceptions\TransferRefused;
use Relaticle\SystemAdmin\Filament\Resources\SubscriptionResource;
use Relaticle\SystemAdmin\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

mutates(TransferWorkspaceBilling::class);

beforeEach(function (): void {
    $this->admin = SystemAdministrator::factory()->create();
    $this->actingAs($this->admin, 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
    config()->set('services.stripe.prices.pro_monthly', 'price_pro_monthly_test');
    config()->set('cashier.secret', 'sk_test_fake');

    $this->stripeCalls = new ArrayObject;
    fakeStripeCustomerApi($this->stripeCalls);
});

afterEach(function (): void {
    ApiRequestor::setHttpClient(null);
});

/**
 * Stripe's SDK talks HTTP directly, so the customer rename is only observable
 * by handing it a client that records what went out. Everything above it,
 * Cashier included, runs for real.
 *
 * @param  ArrayObject<int, array{url: string, params: array<string, mixed>}>  $calls
 */
function fakeStripeCustomerApi(ArrayObject $calls, bool $fails = false): void
{
    ApiRequestor::setHttpClient(new class($calls, $fails) implements ClientInterface
    {
        /** @param  ArrayObject<int, array{url: string, params: array<string, mixed>}>  $calls */
        public function __construct(private readonly ArrayObject $calls, private readonly bool $fails) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $this->calls[] = ['url' => (string) $absUrl, 'params' => (array) $params];

            if ($this->fails) {
                throw new RuntimeException('Stripe is unreachable');
            }

            return [json_encode(['id' => 'cus_transfer_source', 'object' => 'customer']), 200, []];
        }
    });
}

/**
 * A workspace pair owned by one person: the source holds the Stripe customer
 * and an active Pro subscription, the target holds nothing.
 *
 * @return array{0: Team, 1: Team, 2: Subscription}
 */
function transferPair(array $subscriptionOverrides = []): array
{
    $owner = User::factory()->create();

    /** @var Team $source */
    $source = Team::factory()->create([
        'user_id' => $owner->getKey(),
        'plan' => Plan::Pro,
        'stripe_id' => 'cus_transfer_source',
        'pm_type' => 'visa',
        'pm_last_four' => '4242',
    ]);

    /** @var Team $target */
    $target = Team::factory()->create([
        'user_id' => $owner->getKey(),
        'plan' => Plan::Free,
    ]);

    /** @var Subscription $subscription */
    $subscription = $source->subscriptions()->create(array_merge([
        'type' => 'default',
        'stripe_id' => 'sub_transfer_1',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ], $subscriptionOverrides));

    return [$source, $target, $subscription];
}

it('moves the stripe customer, the subscription and both plans to the target workspace', function (): void {
    [$source, $target, $subscription] = transferPair();

    livewire(ListSubscriptions::class)
        ->callAction(TestAction::make('transfer')->table($subscription), [
            'target_team_id' => $target->getKey(),
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Billing transferred');

    $source->refresh();
    $target->refresh();

    expect($target->stripe_id)->toBe('cus_transfer_source')
        ->and($target->pm_type)->toBe('visa')
        ->and($target->pm_last_four)->toBe('4242')
        ->and($target->plan)->toBe(Plan::Pro)
        ->and($target->trial_ends_at)->toBeNull()
        ->and($source->stripe_id)->toBeNull()
        ->and($source->pm_type)->toBeNull()
        ->and($source->pm_last_four)->toBeNull()
        ->and($source->plan)->toBe(Plan::Free)
        ->and($subscription->refresh()->team_id)->toBe($target->getKey());
});

it('moves every subscription on the source workspace, not just the one the action was called from', function (): void {
    [$source, $target, $subscription] = transferPair();

    /** @var Subscription $secondSubscription */
    $secondSubscription = $source->subscriptions()->create([
        'type' => 'secondary',
        'stripe_id' => 'sub_transfer_2',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    livewire(ListSubscriptions::class)
        ->callAction(TestAction::make('transfer')->table($subscription), [
            'target_team_id' => $target->getKey(),
        ])
        ->assertHasNoActionErrors();

    expect($subscription->refresh()->team_id)->toBe($target->getKey())
        ->and($secondSubscription->refresh()->team_id)->toBe($target->getKey());
});

it('keeps the source on its sysadmin-assigned plan when it differs from the transferred subscription plan', function (): void {
    [$source, $target, $subscription] = transferPair();

    $source->forceFill(['plan' => Plan::Enterprise])->save();

    livewire(ListSubscriptions::class)
        ->callAction(TestAction::make('transfer')->table($subscription), [
            'target_team_id' => $target->getKey(),
        ])
        ->assertHasNoActionErrors();

    expect($source->refresh()->plan)->toBe(Plan::Enterprise)
        ->and($target->refresh()->plan)->toBe(Plan::Pro);
});

it('grants the target the Pro allowance, drops the source to Free and keeps purchased credits', function (): void {
    [$source, $target, $subscription] = transferPair();

    AiCreditBalance::query()
        ->where('team_id', $target->getKey())
        ->update(['purchased_credits' => 500, 'credits_remaining' => 500]);

    livewire(ListSubscriptions::class)
        ->callAction(TestAction::make('transfer')->table($subscription), [
            'target_team_id' => $target->getKey(),
        ])
        ->assertHasNoActionErrors();

    $targetBalance = AiCreditBalance::query()->where('team_id', $target->getKey())->sole();
    $sourceBalance = AiCreditBalance::query()->where('team_id', $source->getKey())->sole();

    expect($targetBalance->credits_remaining)->toBe(Plan::Pro->credits() + 500)
        ->and($targetBalance->purchased_credits)->toBe(500)
        ->and($targetBalance->credits_used)->toBe(0)
        ->and($sourceBalance->credits_remaining)->toBe(Plan::Free->credits());
});

it('anchors the target credit period on the original subscription start date', function (): void {
    [, $target, $subscription] = transferPair();

    $anchor = now()->subDays(40)->startOfHour();
    $subscription->forceFill(['created_at' => $anchor])->save();

    livewire(ListSubscriptions::class)
        ->callAction(TestAction::make('transfer')->table($subscription), [
            'target_team_id' => $target->getKey(),
        ])
        ->assertHasNoActionErrors();

    $balance = AiCreditBalance::query()->where('team_id', $target->getKey())->sole();

    expect($balance->period_starts_at->toDateTimeString())
        ->toBe($anchor->copy()->addMonthNoOverflow()->toDateTimeString());
});

it('falls back the source credit period to the calendar month, not the moved subscription anchor', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-06-15 12:00:00', new DateTimeZone('UTC')));

    [$source, $target, $subscription] = transferPair();

    $subscription->forceFill(['created_at' => now()->subMonths(6)])->save();

    livewire(ListSubscriptions::class)
        ->callAction(TestAction::make('transfer')->table($subscription), [
            'target_team_id' => $target->getKey(),
        ])
        ->assertHasNoActionErrors();

    $sourceBalance = AiCreditBalance::query()->where('team_id', $source->getKey())->sole();

    expect($sourceBalance->period_starts_at->toDateTimeString())
        ->toBe(now()->startOfMonth()->toDateTimeString());
});

it('hides the transfer action when the source subscription is no longer valid', function (): void {
    [$source, $target, $subscription] = transferPair([
        'stripe_status' => 'canceled',
        'ends_at' => now()->subDay(),
    ]);

    livewire(ListSubscriptions::class)
        ->assertActionHidden(TestAction::make('transfer')->table($subscription));

    expect($source->refresh()->stripe_id)->toBe('cus_transfer_source')
        ->and($target->refresh()->stripe_id)->toBeNull()
        ->and($subscription->refresh()->team_id)->toBe($source->getKey());
});

it('throws when the source subscription is no longer valid, called directly', function (): void {
    [$source, $target, $subscription] = transferPair([
        'stripe_status' => 'canceled',
        'ends_at' => now()->subDay(),
    ]);

    expect(fn () => app(TransferWorkspaceBilling::class)->execute($source, $target, (string) $this->admin->getKey()))
        ->toThrow(TransferRefused::class);

    expect($source->refresh()->stripe_id)->toBe('cus_transfer_source')
        ->and($target->refresh()->stripe_id)->toBeNull()
        ->and($subscription->refresh()->team_id)->toBe($source->getKey());
});

it('refuses to transfer when the subscription price maps to no plan', function (): void {
    [$source, $target, $subscription] = transferPair(['stripe_price' => 'price_not_in_config']);

    livewire(ListSubscriptions::class)
        ->callAction(TestAction::make('transfer')->table($subscription), [
            'target_team_id' => $target->getKey(),
        ])
        ->assertNotified('Transfer refused');

    expect($target->refresh()->stripe_id)->toBeNull()
        ->and($target->refresh()->plan)->toBe(Plan::Free)
        ->and($subscription->refresh()->team_id)->toBe($source->getKey());
});

it('rejects a target that already has its own stripe customer because the option list excludes it', function (): void {
    [$source, $target, $subscription] = transferPair();

    // A workspace that subscribed on its own while the modal was open. Another
    // workspace stays eligible, so the action is still offered and the refusal
    // has to come from the submit, which is the race a sysadmin actually hits.
    $target->forceFill(['stripe_id' => 'cus_target_own'])->save();
    Team::factory()->create(['user_id' => $source->user_id, 'plan' => Plan::Free]);

    livewire(ListSubscriptions::class)
        ->callAction(TestAction::make('transfer')->table($subscription), [
            'target_team_id' => $target->getKey(),
        ])
        ->assertHasActionErrors(['target_team_id']);

    expect($source->refresh()->stripe_id)->toBe('cus_transfer_source')
        ->and($target->refresh()->stripe_id)->toBe('cus_target_own')
        ->and($subscription->refresh()->team_id)->toBe($source->getKey());
});

it('rejects a target with a different owner because the option list excludes it', function (): void {
    [$source, , $subscription] = transferPair();

    /** @var Team $stranger */
    $stranger = Team::factory()->create(['plan' => Plan::Free]);

    livewire(ListSubscriptions::class)
        ->callAction(TestAction::make('transfer')->table($subscription), [
            'target_team_id' => $stranger->getKey(),
        ])
        ->assertHasActionErrors(['target_team_id']);

    expect($source->refresh()->stripe_id)->toBe('cus_transfer_source')
        ->and($stranger->refresh()->stripe_id)->toBeNull()
        ->and($stranger->plan)->toBe(Plan::Free)
        ->and($subscription->refresh()->team_id)->toBe($source->getKey());
});

it('throws when the target already has its own stripe customer, called directly', function (): void {
    [$source, $target, $subscription] = transferPair();

    $target->forceFill(['stripe_id' => 'cus_target_own'])->save();

    expect(fn () => app(TransferWorkspaceBilling::class)->execute($source, $target, (string) $this->admin->getKey()))
        ->toThrow(TransferRefused::class);

    expect($source->refresh()->stripe_id)->toBe('cus_transfer_source')
        ->and($target->refresh()->stripe_id)->toBe('cus_target_own')
        ->and($subscription->refresh()->team_id)->toBe($source->getKey());
});

it('throws when the target belongs to a different owner, called directly', function (): void {
    [$source, , $subscription] = transferPair();

    /** @var Team $stranger */
    $stranger = Team::factory()->create(['plan' => Plan::Free]);

    expect(fn () => app(TransferWorkspaceBilling::class)->execute($source, $stranger, (string) $this->admin->getKey()))
        ->toThrow(TransferRefused::class);

    expect($source->refresh()->stripe_id)->toBe('cus_transfer_source')
        ->and($stranger->refresh()->stripe_id)->toBeNull()
        ->and($stranger->plan)->toBe(Plan::Free)
        ->and($subscription->refresh()->team_id)->toBe($source->getKey());
});

it('does not offer a workspace that already has its own stripe customer as a target', function (): void {
    [$source, $target, $subscription] = transferPair();

    /** @var Team $subscribedSibling */
    $subscribedSibling = Team::factory()->create([
        'user_id' => $source->user_id,
        'stripe_id' => 'cus_sibling_own',
    ]);

    $targets = SubscriptionResource::transferTargets($subscription);

    expect(array_keys($targets))
        ->toContain($target->getKey())
        ->not->toContain($subscribedSibling->getKey());
});

it('does not offer a workspace scheduled for deletion as a target', function (): void {
    [$source, $target, $subscription] = transferPair();

    /** @var Team $scheduledSibling */
    $scheduledSibling = Team::factory()->create([
        'user_id' => $source->user_id,
        'scheduled_deletion_at' => now()->addDays(7),
    ]);

    $targets = SubscriptionResource::transferTargets($subscription);

    expect(array_keys($targets))
        ->toContain($target->getKey())
        ->not->toContain($scheduledSibling->getKey());
});

it('throws when the target is scheduled for deletion, called directly', function (): void {
    [$source, $target, $subscription] = transferPair();

    $target->forceFill(['scheduled_deletion_at' => now()->addDays(7)])->save();

    expect(fn () => app(TransferWorkspaceBilling::class)->execute($source, $target, (string) $this->admin->getKey()))
        ->toThrow(TransferRefused::class);

    expect($source->refresh()->stripe_id)->toBe('cus_transfer_source')
        ->and($target->refresh()->stripe_id)->toBeNull()
        ->and($subscription->refresh()->team_id)->toBe($source->getKey());
});

it('keeps the transfer modal open when the transfer is refused', function (): void {
    [$source, $target, $subscription] = transferPair(['stripe_price' => 'price_not_in_config']);

    livewire(ListSubscriptions::class)
        ->callAction(TestAction::make('transfer')->table($subscription), [
            'target_team_id' => $target->getKey(),
        ])
        ->assertNotified('Transfer refused')
        ->assertActionHalted(TestAction::make('transfer')->table($subscription));

    expect($source->refresh()->stripe_id)->toBe('cus_transfer_source')
        ->and($target->refresh()->stripe_id)->toBeNull()
        ->and($subscription->refresh()->team_id)->toBe($source->getKey());
});

it('renames the stripe customer to the workspace that now owns it', function (): void {
    [, $target, $subscription] = transferPair();

    livewire(ListSubscriptions::class)
        ->callAction(TestAction::make('transfer')->table($subscription), [
            'target_team_id' => $target->getKey(),
        ])
        ->assertHasNoActionErrors();

    /** @var array<int, array{url: string, params: array<string, mixed>}> $calls */
    $calls = $this->stripeCalls->getArrayCopy();

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['url'])->toContain('/v1/customers/cus_transfer_source')
        ->and($calls[0]['params'])->toBe(['name' => $target->name]);
});

it('keeps a committed transfer when the stripe rename fails', function (): void {
    [$source, $target, $subscription] = transferPair();

    fakeStripeCustomerApi($this->stripeCalls, fails: true);

    livewire(ListSubscriptions::class)
        ->callAction(TestAction::make('transfer')->table($subscription), [
            'target_team_id' => $target->getKey(),
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Billing transferred');

    expect($this->stripeCalls)->toHaveCount(1)
        ->and($target->refresh()->stripe_id)->toBe('cus_transfer_source')
        ->and($target->plan)->toBe(Plan::Pro)
        ->and($source->refresh()->stripe_id)->toBeNull()
        ->and($source->plan)->toBe(Plan::Free)
        ->and($subscription->refresh()->team_id)->toBe($target->getKey());
});

it('hides the transfer action when the owner has no workspace the billing can move to', function (): void {
    [$source, $target, $subscription] = transferPair();

    $target->forceFill(['stripe_id' => 'cus_target_own'])->save();

    livewire(ListSubscriptions::class)
        ->assertActionHidden(TestAction::make('transfer')->table($subscription));

    expect($source->refresh()->stripe_id)->toBe('cus_transfer_source')
        ->and($subscription->refresh()->team_id)->toBe($source->getKey());
});
