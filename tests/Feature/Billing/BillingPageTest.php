<?php

declare(strict_types=1);

use App\Actions\Billing\CreateProCheckout;
use App\Actions\Billing\StartProTrial;
use App\Enums\Plan;
use App\Features\Billing as BillingFeature;
use App\Filament\Pages\Billing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\CreditPackCatalog;
use App\Services\Billing\HostedWorkspaceAccess;
use Filament\Facades\Filament;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Models\AiCreditBalance;

mutates(
    Billing::class,
    CreateProCheckout::class,
    CreditPackCatalog::class,
    HostedWorkspaceAccess::class,
    StartProTrial::class,
);

beforeEach(function (): void {
    Feature::define(BillingFeature::class, true);
    config()->set('services.stripe.prices.pro_monthly', 'price_pro_monthly_test');
    config()->set('services.stripe.prices.pro_yearly', 'price_pro_yearly_test');
});

/** @return array{0: User, 1: Workspace} */
function billingPageOwner(): array
{
    $user = User::factory()->withPersonalWorkspace()->create();

    /** @var Workspace $workspace */
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['hosted_free_grandfathered_at' => now()])->save();

    test()->actingAs($user);
    Filament::setTenant($workspace);

    return [$user, $workspace];
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

it('hides the trial CTA once the workspace used its trial', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['pro_trial_used_at' => now()])->save();

    livewire(Billing::class)
        ->assertDontSee(__('billing.trial.start_button'))
        ->assertSee(__('billing.upgrade.button'));
});

it('offers the trial to a hosted workspace that never received one', function (): void {
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['hosted_free_grandfathered_at' => null])->save();

    livewire(Billing::class)
        ->assertSee(__('billing.trial.start_button'))
        ->assertSee(__('billing.paused.title'))
        ->assertSee(__('billing.upgrade.now'))
        ->assertSee('$19')
        ->assertSee(__('billing.pro_plan.billed_yearly'))
        ->assertDontSee(__('billing.packs.buy', ['credits' => number_format(1000)]));
});

it('advertises all 37 MCP tools on the authenticated billing page', function (): void {
    billingPageOwner();

    livewire(Billing::class)
        ->assertSee('REST API and 37-tool MCP server')
        ->assertDontSee('REST API and 32-tool MCP server');
});

it('starts a trial via the page action', function (): void {
    [, $workspace] = billingPageOwner();

    livewire(Billing::class)->call('startTrial');

    expect($workspace->refresh()->plan)->toBe(Plan::Pro)
        ->and($workspace->onGenericTrial())->toBeTrue();
});

it('refuses a second trial on a workspace even when called directly', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['pro_trial_used_at' => now()])->save();

    livewire(Billing::class)->call('startTrial');

    expect($workspace->refresh()->plan)->toBe(Plan::Free)
        ->and($workspace->onGenericTrial())->toBeFalse();
});

it('shows a graceful error instead of 500 when checkout cannot start', function (): void {
    // No Stripe secret configured in tests → the checkout call throws; the page must
    // catch it, notify, and stay put rather than surfacing a 500.
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['plan' => Plan::Free])->save();

    livewire(Billing::class)
        ->call('upgrade', 'monthly')
        ->assertNotified()
        ->assertOk();

    expect($workspace->refresh()->plan)->toBe(Plan::Free);
});

it('blocks the trial action for non-owners', function (): void {
    [, $workspace] = billingPageOwner();
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'admin']);

    test()->actingAs($member);
    Filament::setTenant($workspace->refresh());

    livewire(Billing::class)->call('startTrial');

    expect($workspace->refresh()->plan)->toBe(Plan::Free);
});

it('shows trialing state with subscribe CTA', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(10)])->save();

    livewire(Billing::class)
        ->assertSee(__('billing.trial.active_title'))
        ->assertSee(__('billing.subscribe.button'));
});

it('shows manage state for an active subscription', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['plan' => Plan::Pro])->save();
    $workspace->subscriptions()->create([
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
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['plan' => Plan::Pro])->save();
    $workspace->subscriptions()->create([
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
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['plan' => Plan::Pro])->save();
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_due',
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    livewire(Billing::class)
        ->assertSee(__('billing.manage.past_due_title'))
        ->assertSee(__('billing.manage.past_due_tagline'))
        ->assertDontSee(__('billing.manage.auto_renews'))
        ->assertDontSee(__('billing.manage.title'));
});

it('meters a past-due workspace against the allowance it will be refilled with', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['plan' => Plan::Pro])->save();
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_due_allowance',
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->update([
        'credits_remaining' => 0,
        'credits_used' => Plan::Free->credits(),
    ]);

    livewire(Billing::class)
        ->assertSee('/ '.number_format(Plan::Free->credits()))
        ->assertDontSee('/ '.number_format(Plan::Pro->credits()))
        ->assertSee('100%');
});

it('shows enterprise manual state without upgrade actions', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['plan' => Plan::Enterprise])->save();

    livewire(Billing::class)
        ->assertSee(__('billing.enterprise.title'))
        ->assertDontSee(__('billing.upgrade.button'));
});

it('renders read-only info for members', function (): void {
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);
    [, $workspace] = billingPageOwner();
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'editor']);

    test()->actingAs($member);
    Filament::setTenant($workspace->refresh());

    livewire(Billing::class)
        ->assertSee(__('billing.member.ask_owner', ['owner' => $workspace->owner->name]))
        ->assertDontSee(__('billing.trial.start_button'))
        ->assertDontSee(__('billing.packs.buy', ['credits' => number_format(1000)]));
});

it('shows buy-credit buttons to an owner with hosted access', function (): void {
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);
    billingPageOwner();

    livewire(Billing::class)->assertSee(__('billing.packs.buy', ['credits' => number_format(1000)]));
});

it('hides buy-credit buttons when no pack price is configured', function (): void {
    config()->set('services.stripe.credit_packs', [
        'small' => ['price' => null, 'credits' => 1000],
        'large' => ['price' => null, 'credits' => 5000],
    ]);
    billingPageOwner();

    livewire(Billing::class)->assertDontSee(__('billing.packs.buy', ['credits' => number_format(1000)]));
});

it('refuses buyCredits for a paused workspace', function (): void {
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['hosted_free_grandfathered_at' => null, 'trial_ends_at' => now()->subDay(), 'plan' => Plan::Free])->save();

    livewire(Billing::class)
        ->call('buyCredits', 'small')
        ->assertNoRedirect()
        ->assertNotNotified();
});

it('refuses buyCredits for a non-owner', function (): void {
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);
    [, $workspace] = billingPageOwner();
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'admin']);

    test()->actingAs($member);
    Filament::setTenant($workspace->refresh());

    livewire(Billing::class)
        ->call('buyCredits', 'small')
        ->assertNoRedirect()
        ->assertNotNotified();
});

it('shows the purchased portion of the balance', function (): void {
    [, $workspace] = billingPageOwner();

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'credits_remaining' => 500,
        'credits_used' => 0,
        'purchased_credits' => 200,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    livewire(Billing::class)->assertSee(__('billing.packs.balance_split', ['purchased' => number_format(200)]));
});

it('shows a fulfilment-pending notice after a credit pack checkout', function (): void {
    billingPageOwner();

    livewire(Billing::class)
        ->set('credits', 'success')
        ->assertSee(__('billing.packs.fulfilling_title'));
});

it('does not show the fulfilment-pending notice without the credits query param', function (): void {
    billingPageOwner();

    livewire(Billing::class)
        ->assertDontSee(__('billing.packs.fulfilling_title'));
});

it('uses the plan allowance as the meter denominator, not remaining plus used', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'credits_remaining' => 500,
        'credits_used' => 75,
        'purchased_credits' => 200,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    livewire(Billing::class)
        ->assertSee('/ '.number_format(Plan::Pro->credits()))
        ->assertDontSee('/ '.number_format(575))
        ->assertDontSee('/ '.number_format(375));
});

it('keeps the plan allowance as the denominator once the monthly allowance is exhausted and purchased credits are being spent', function (): void {
    // Old formula (remaining + used - purchased) collapsed to `used` here,
    // rendering "N / N" at 100% with the plan's real allowance nowhere in sight.
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'credits_remaining' => 50,
        'credits_used' => 2050,
        'purchased_credits' => 50,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    livewire(Billing::class)
        ->assertSee('/ '.number_format(Plan::Pro->credits()))
        ->assertDontSee('/ '.number_format(2050));
});

it('names the workspace in the upgrade confirmation step', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['name' => 'Acme Manufacturing'])->save();

    livewire(Billing::class)
        ->assertSee(__('billing.upgrade.confirm_title'))
        ->assertSee(__('billing.upgrade.confirm_button', ['workspace' => 'Acme Manufacturing']));
});

it('offers owners an Enterprise conversation without changing their plan', function (): void {
    config()->set('app.url', 'https://marketing.test');
    [, $workspace] = billingPageOwner();

    livewire(Billing::class)
        ->assertSee('From $20,000 / year')
        ->assertSeeHtml('href="https://marketing.test/contact?plan=enterprise"');

    expect($workspace->refresh()->plan)->toBe(Plan::Free);
});

it('does not offer Enterprise purchases to workspace members', function (): void {
    [, $workspace] = billingPageOwner();
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'editor']);
    test()->actingAs($member);
    Filament::setTenant($workspace->refresh());

    livewire(Billing::class)
        ->assertDontSee('From $20,000 / year');
});

it('keeps an Enterprise grant managed after an older subscription ended', function (): void {
    config()->set('app.url', 'https://marketing.test');
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['plan' => Plan::Enterprise])->save();
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_old_pro',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'ends_at' => now()->subDay(),
    ]);

    livewire(Billing::class)
        ->assertSee(__('billing.enterprise.body'))
        ->assertSee('Contact your Relaticle team')
        ->assertSeeHtml('href="https://marketing.test/contact"')
        ->assertDontSee(__('billing.upgrade.button'))
        ->assertDontSee('From $20,000 / year');
});

it('does not send an Enterprise workspace through Pro checkout', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['plan' => Plan::Enterprise])->save();

    livewire(Billing::class)
        ->call('upgrade', 'yearly')
        ->assertNoRedirect()
        ->assertNotNotified();

    expect($workspace->refresh()->plan)->toBe(Plan::Enterprise);
});

it('keeps Enterprise access clear while an older Pro subscription is canceling', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['plan' => Plan::Enterprise, 'trial_ends_at' => now()->addDays(5)])->save();
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_enterprise_old_grace',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'ends_at' => now()->addDays(9),
    ]);

    livewire(Billing::class)
        ->assertSee(__('billing.status.managed'))
        ->assertSee('Your Enterprise access is unchanged.')
        ->assertDontSee('After that, workspace access pauses.')
        ->assertDontSee(__('billing.trial.active_title'));
});

it('shows the Enterprise allowance when an older Pro subscription is past due', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['plan' => Plan::Enterprise])->save();
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_enterprise_old_due',
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    livewire(Billing::class)
        ->assertSee('/ 10,000')
        ->assertSee('Your previous subscription has a payment issue.')
        ->assertDontSee(__('billing.manage.past_due_body'));
});

it('identifies a manually managed Pro plan without calling it Enterprise', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    livewire(Billing::class)
        ->assertSee('Your plan is managed by Relaticle')
        ->assertDontSee(__('billing.enterprise.title'));
});

it('shows spendable credits separately after the monthly allowance is exhausted', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['plan' => Plan::Pro])->save();
    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->update([
        'credits_remaining' => 150,
        'credits_used' => 2050,
        'purchased_credits' => 150,
    ]);

    livewire(Billing::class)
        ->assertSee('150 credits available')
        ->assertSee('2,050 credits used this period')
        ->assertSee('100%');
});
