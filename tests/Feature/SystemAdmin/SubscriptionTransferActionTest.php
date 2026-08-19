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
use Relaticle\SystemAdmin\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(TransferWorkspaceBilling::class);

beforeEach(function (): void {
    $this->admin = SystemAdministrator::factory()->create();
    $this->actingAs($this->admin, 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
    config()->set('services.stripe.prices.pro_monthly', 'price_pro_monthly_test');
});

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
