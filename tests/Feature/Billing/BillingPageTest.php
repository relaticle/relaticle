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

/** @return array{0: User, 1: Team} */
function billingPageOwner(): array
{
    $user = User::factory()->withPersonalTeam()->create();

    /** @var Team $team */
    $team = $user->currentTeam;

    test()->actingAs($user);
    Filament::setTenant($team);

    return [$user, $team];
}

it('is forbidden when the billing feature is off', function (): void {
    Feature::define(BillingFeature::class, false);
    billingPageOwner();

    livewire(Billing::class)->assertForbidden();
});

it('shows trial CTA to an owner who never trialed', function (): void {
    billingPageOwner();

    livewire(Billing::class)
        ->assertSee(__('billing.trial.start_button'));
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

it('shows a graceful error instead of 500 when checkout cannot start', function (): void {
    // No Stripe secret configured in tests → the checkout call throws; the page must
    // catch it, notify, and stay put rather than surfacing a 500.
    [, $team] = billingPageOwner();
    $team->forceFill(['plan' => Plan::Free])->save();

    livewire(Billing::class)
        ->call('upgrade', 'monthly')
        ->assertNotified()
        ->assertOk();

    expect($team->refresh()->plan)->toBe(Plan::Free);
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

it('shows trialing state with subscribe CTA', function (): void {
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

it('shows enterprise manual state without upgrade actions', function (): void {
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
