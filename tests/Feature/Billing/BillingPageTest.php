<?php

declare(strict_types=1);

use App\Actions\Billing\StartProTrial;
use App\Enums\Plan;
use App\Features\Billing as BillingFeature;
use App\Filament\Pages\Billing;
use App\Jobs\RefreshManagedRenewal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\CreditPackCatalog;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Cashier\Subscription;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Cloud\Entitlements\PlanEntitlements;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Tests\Helpers\StripeRecorder;

mutates(
    Billing::class,
    CreditPackCatalog::class,
    PlanEntitlements::class,
    StartProTrial::class,
);

beforeEach(function (): void {
    Queue::fake([RefreshManagedRenewal::class]);
    withHostedBilling();
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
        ->assertSee(__('billing.upgrade.button'))
        ->assertDontSee('@js(', false);
});

it('asks a paused workspace that never trialed to subscribe, without offering a trial', function (): void {
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['hosted_free_grandfathered_at' => null])->save();

    livewire(Billing::class)
        ->assertSee(__('billing.paused.heading.free', ['workspace' => $workspace->name]))
        ->assertDontSee('billing.paused.heading.', false)
        ->assertSee(__('billing.paused.owner_body', ['workspace' => $workspace->name]))
        ->assertSee(__('billing.paused.continue'))
        ->assertDontSee(__('billing.trial.start_button'))
        ->assertDontSee(__('billing.upgrade.now'))
        ->assertDontSee(__('billing.packs.buy', ['credits' => number_format(1000)]));
});

it('refuses to start a trial on a paused workspace', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['hosted_free_grandfathered_at' => null])->save();

    livewire(Billing::class)
        ->call('startTrial')
        ->assertNotified(__('billing.trial.not_available'))
        ->assertNoRedirect();

    expect($workspace->refresh()->pro_trial_used_at)->toBeNull();
});

it('tells a paused workspace with a mailbox that its sync is paused too', function (): void {
    [$user, $workspace] = billingPageOwner();
    $workspace->forceFill(['hosted_free_grandfathered_at' => null, 'pro_trial_used_at' => now()->subDays(15)])->save();

    $this->get(Billing::getUrl(panel: 'app', tenant: $workspace))
        ->assertOk()
        ->assertDontSee(__('billing.paused.mailbox_sync'));

    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]));

    $this->get(Billing::getUrl(panel: 'app', tenant: $workspace))
        ->assertOk()
        ->assertSee(__('billing.paused.mailbox_sync'));
});

it('replaces the app shell with a standalone paused screen when the trial ends', function (): void {
    config()->set('app.url', 'https://marketing.test');
    [, $workspace] = billingPageOwner();
    $workspace->forceFill([
        'hosted_free_grandfathered_at' => null,
        'plan' => Plan::Pro,
        'pro_trial_used_at' => now()->subDays(14),
        'trial_ends_at' => now()->subHour(),
    ])->save();

    $this->get(Billing::getUrl(panel: 'app', tenant: $workspace))
        ->assertOk()
        ->assertSee(__('billing.paused.heading.trial_ended'))
        ->assertDontSee('billing.paused.heading.', false)
        ->assertSee(__('billing.paused.owner_body', ['workspace' => $workspace->name]))
        ->assertSee(__('billing.paused.continue'))
        ->assertSee('href="https://marketing.test/contact"', false)
        ->assertSee(__('billing.paused.sign_out'))
        ->assertDontSee('Delete workspace')
        ->assertDontSee('fi-sidebar', false)
        ->assertDontSee(__('billing.usage.title'));
});

it('opens the upgrade modal from the paused screen instead of a review page', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['hosted_free_grandfathered_at' => null, 'pro_trial_used_at' => now()->subDays(20)])->save();

    livewire(Billing::class)
        ->assertSee(__('billing.paused.continue'))
        ->assertSeeHtml("open-modal', { id: 'upgrade-plan' }")
        ->assertDontSee(__('billing.upgrade.review.proceed'))
        ->assertDontSee('Js::from', false);
});

it('opens the dashboard when a reopening checkout returns after the subscription landed', function (): void {
    [, $workspace] = billingPageOwner();

    livewire(Billing::class, ['checkout' => Billing::CHECKOUT_REOPENED])
        ->assertRedirect(Filament::getUrl($workspace));
});

it('keeps an ordinary upgrade on the billing page when checkout returns', function (): void {
    billingPageOwner();

    livewire(Billing::class, ['checkout' => Billing::CHECKOUT_SUCCESS])
        ->assertNoRedirect();
});

it('waits on the paused screen while a reopening checkout is still activating', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['hosted_free_grandfathered_at' => null, 'pro_trial_used_at' => now()->subDays(20)])->save();

    livewire(Billing::class, ['checkout' => Billing::CHECKOUT_REOPENED])
        ->assertNoRedirect()
        ->assertSee(__('billing.upgrade.activating'));
});

it('names the ended subscription on the paused screen', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['hosted_free_grandfathered_at' => null, 'pro_trial_used_at' => now()->subMonths(2)])->save();
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_ended',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'ends_at' => now()->subDay(),
    ]);

    livewire(Billing::class)
        ->assertSee(__('billing.paused.heading.subscription_ended'))
        ->assertDontSee('billing.paused.heading.', false)
        ->assertDontSee(__('billing.paused.heading.trial_ended'));
});

it('names the lapsed managed plan on the paused screen and offers to restart it first', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['hosted_free_grandfathered_at' => null])->save();
    endedSubscriptionOn($workspace, 'managed', 'sub_ended_concierge', product: 'prod_concierge_test');

    livewire(Billing::class)
        ->assertSee(__('billing.paused.heading.managed_ended', ['plan' => 'Cloud Concierge']))
        ->assertSee(__('billing.paused.owner_body_managed', ['plan' => 'Cloud Concierge', 'workspace' => $workspace->name]))
        ->assertSee(__('billing.paused.restart_managed', ['plan' => 'Cloud Concierge']))
        ->assertSee('/contact?plan=concierge', false)
        ->assertSee(__('billing.paused.continue'))
        ->assertDontSee(__('billing.paused.heading.subscription_ended'))
        ->assertDontSee(__('billing.paused.owner_body', ['workspace' => $workspace->name]));
});

it('names Cloud Pro when Pro ended after an older managed plan', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['hosted_free_grandfathered_at' => null])->save();
    endedSubscriptionOn($workspace, 'managed', 'sub_ended_concierge', product: 'prod_concierge_test', endedAt: now()->subMonth());
    endedSubscriptionOn($workspace, 'default', 'sub_ended_pro', product: 'prod_pro_test');

    livewire(Billing::class)
        ->assertSee(__('billing.paused.heading.subscription_ended'))
        ->assertDontSee(__('billing.paused.heading.managed_ended', ['plan' => 'Cloud Concierge']));
});

it('tells a member of a lapsed managed workspace that the owner restarts the plan with us', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['hosted_free_grandfathered_at' => null])->save();
    endedSubscriptionOn($workspace, 'managed', 'sub_ended_concierge', product: 'prod_concierge_test');
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'member']);

    test()->actingAs($member);
    Filament::setTenant($workspace->refresh());

    livewire(Billing::class)
        ->assertSee(__('billing.paused.member_body_managed', ['owner' => $workspace->owner->name, 'workspace' => $workspace->name, 'plan' => 'Cloud Concierge']))
        ->assertDontSee(__('billing.paused.restart_managed', ['plan' => 'Cloud Concierge']));
});

function endedSubscriptionOn(Workspace $workspace, string $type, string $stripeId, string $product, ?CarbonImmutable $endedAt = null): void
{
    config()->set('services.stripe.products.concierge', 'prod_concierge_test');

    $workspace->subscriptions()->create([
        'type' => $type,
        'stripe_id' => $stripeId,
        'stripe_status' => 'canceled',
        'stripe_price' => $type === 'default' ? 'price_pro_monthly_test' : "price_inline_{$stripeId}",
        'quantity' => 1,
        'ends_at' => $endedAt ?? now()->subDay(),
    ])->items()->create([
        'stripe_id' => "si_{$stripeId}",
        'stripe_product' => $product,
        'stripe_price' => $type === 'default' ? 'price_pro_monthly_test' : "price_inline_{$stripeId}",
        'quantity' => 1,
    ]);
}

it('calls it a trial ending when the only subscription was an abandoned checkout', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['hosted_free_grandfathered_at' => null, 'pro_trial_used_at' => now()->subDays(20)])->save();
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_abandoned',
        'stripe_status' => 'incomplete_expired',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    livewire(Billing::class)
        ->assertSee(__('billing.paused.heading.trial_ended'))
        ->assertDontSee(__('billing.paused.heading.subscription_ended'));
});

it('tells a member of a paused workspace who can reopen it, without checkout controls', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['hosted_free_grandfathered_at' => null, 'pro_trial_used_at' => now()->subDays(20)])->save();
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'member']);

    test()->actingAs($member);
    Filament::setTenant($workspace->refresh());

    livewire(Billing::class)
        ->assertSee(__('billing.paused.member_body', ['owner' => $workspace->owner->name, 'workspace' => $workspace->name]))
        ->assertDontSee(__('billing.paused.continue'));
});

it('tells a member of a paused workspace whose owner was deleted who can reopen it', function (): void {
    [$owner, $workspace] = billingPageOwner();
    $workspace->forceFill(['hosted_free_grandfathered_at' => null, 'pro_trial_used_at' => now()->subDays(20)])->save();
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'member']);
    $owner->delete();

    test()->actingAs($member);
    Filament::setTenant($workspace->refresh());

    livewire(Billing::class)
        ->assertOk()
        ->assertSee(__('billing.paused.member_body_ownerless', ['workspace' => $workspace->name]));
});

it('tells the owner when paid activation takes longer than expected', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['hosted_free_grandfathered_at' => null, 'pro_trial_used_at' => now()->subDays(20)])->save();

    livewire(Billing::class, ['checkout' => 'success'])
        ->assertSee(__('billing.upgrade.activating'))
        ->assertSee(__('billing.upgrade.activation_delayed_title'));
});

it('lets a paused user switch to another of their workspaces by name and logo', function (): void {
    Storage::fake('public');
    [$user, $workspace] = billingPageOwner();
    $workspace->forceFill(['hosted_free_grandfathered_at' => null])->save();
    $other = Workspace::factory()->create(['user_id' => $user->getKey(), 'name' => 'Northwind Traders', 'personal_workspace' => false]);
    $other->addMedia(UploadedFile::fake()->image('northwind-logo.png'))
        ->toMediaCollection(Workspace::LOGO_MEDIA_COLLECTION);
    test()->actingAs($user->fresh());

    livewire(Billing::class)
        ->assertSee(__('billing.paused.switch'))
        ->assertSee('Northwind Traders')
        ->assertSee(Filament::getUrl($other), false)
        ->assertSee('northwind-logo.png', false);
});

it('keeps polling while checkout activation is pending, then opens the workspace', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['hosted_free_grandfathered_at' => null, 'pro_trial_used_at' => now()->subDays(20)])->save();

    $page = livewire(Billing::class, ['checkout' => 'success'])
        ->assertSee(__('billing.upgrade.activating'))
        ->call('reopenWhenActive')
        ->assertNoRedirect();

    $workspace->forceFill(['plan' => Plan::Enterprise])->save();

    $page->call('reopenWhenActive')->assertRedirect(Filament::getUrl($workspace));
});

it('lists the MCP server on the authenticated billing page', function (): void {
    billingPageOwner();

    livewire(Billing::class)
        ->assertSee('REST API and MCP server');
});

it('starts a trial via the page action', function (): void {
    [, $workspace] = billingPageOwner();

    livewire(Billing::class)->call('startTrial')->assertNoRedirect();

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

it('says when the first charge lands instead of claiming the plan renews', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['plan' => Plan::Pro])->save();
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test_trialing',
        'stripe_status' => 'trialing',
        'stripe_price' => 'price_pro_yearly_test',
        'quantity' => 1,
        'trial_ends_at' => now()->addDays(9),
    ]);

    livewire(Billing::class)
        ->assertSee(__('billing.manage.first_charge', ['date' => now()->addDays(9)->toFormattedDateString()]))
        ->assertDontSee(__('billing.manage.auto_renews'));
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

it('states a Concierge plan as managed once, without repeating the heading in a body', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['plan' => Plan::Concierge])->save();

    $html = livewire(Billing::class)->html();

    expect(substr_count($html, __('billing.managed.title')))->toBe(1)
        ->and($html)->not->toContain(__('billing.enterprise.body'));
});

it('keeps the Enterprise title and body on an Enterprise plan', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['plan' => Plan::Enterprise])->save();

    livewire(Billing::class)
        ->assertSee(__('billing.enterprise.title'))
        ->assertSee(__('billing.enterprise.body'));
});

it('shows Concierge as a managed plan with its allowance and no upgrade actions', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['plan' => Plan::Concierge])->save();

    livewire(Billing::class)
        ->assertSee(__('billing.plans.cloud_concierge'))
        ->assertSee(__('billing.managed.title'))
        ->assertSee('/ '.number_format(Plan::Concierge->credits()))
        ->assertDontSee(__('billing.plans.cloud_pro'))
        ->assertDontSee(__('billing.upgrade.button'));
});

it('shows a managed plan, not a pending activation, when a checkout returns', function (Plan $plan): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['plan' => $plan])->save();

    livewire(Billing::class, ['checkout' => Billing::CHECKOUT_SUCCESS])
        ->assertSee(__('billing.status.managed'))
        ->assertDontSee(__('billing.upgrade.activating'));
})->with([Plan::Enterprise, Plan::Concierge]);

it('renders read-only info for members', function (): void {
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);
    [, $workspace] = billingPageOwner();
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'member']);

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
    $workspace->users()->attach($member, ['role' => 'member']);
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

it('keeps a managed plan clear while an older Pro subscription is canceling', function (Plan $plan): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['plan' => $plan, 'trial_ends_at' => now()->addDays(5)])->save();
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
        ->assertSee('Your current plan is unchanged.')
        ->assertDontSee('After that, workspace access pauses.')
        ->assertDontSee(__('billing.trial.active_title'));
})->with([Plan::Enterprise, Plan::Concierge]);

it('keeps the managed allowance when an older Pro subscription is past due', function (Plan $plan): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['plan' => $plan])->save();
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_enterprise_old_due',
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    livewire(Billing::class)
        ->assertSee('/ '.number_format($plan->credits()))
        ->assertSee('Your previous subscription has a payment issue.')
        ->assertDontSee(__('billing.manage.past_due_body'));
})->with([Plan::Enterprise, Plan::Concierge]);

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

afterEach(function (): void {
    StripeRecorder::uninstall();
});

function managedPageWorkspace(Workspace $workspace, string $status = 'active', Plan $plan = Plan::Concierge, ?Plan $held = null): Subscription
{
    config()->set('services.stripe.products.concierge', 'prod_concierge_test');
    config()->set('services.stripe.products.enterprise', 'prod_enterprise_test');

    $workspace->forceFill(['plan' => $held ?? $plan, 'stripe_id' => 'cus_managed_page'])->save();

    $subscription = $workspace->subscriptions()->create([
        'type' => 'managed',
        'stripe_id' => 'sub_managed_page',
        'stripe_status' => $status,
        'stripe_price' => 'price_inline_managed_page',
        'quantity' => 1,
    ]);
    $subscription->items()->create([
        'stripe_id' => 'si_managed_page',
        'stripe_product' => $plan->stripeProduct(),
        'stripe_price' => 'price_inline_managed_page',
        'quantity' => 1,
    ]);

    return $subscription;
}

function cacheManagedRenewalForPage(): void
{
    Cache::forever(RefreshManagedRenewal::CACHE_PREFIX.'sub_managed_page', [
        'renews_at' => now()->addMonths(6)->getTimestamp(),
        'amount' => 70800,
        'currency' => 'usd',
    ]);
}

it('shows the managed renewal date and amount', function (): void {
    [, $workspace] = billingPageOwner();
    managedPageWorkspace($workspace);
    cacheManagedRenewalForPage();

    livewire(Billing::class)
        ->assertSee('Renews '.now()->addMonths(6)->toFormattedDateString())
        ->assertSee('$708')
        ->assertSee(__('billing.manage.button'));
});

it('shows the managed renewal beside the cancelled Pro subscription it replaced', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_replaced_pro',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'ends_at' => now()->subDay(),
        'created_at' => now()->subMonth(),
    ])->items()->create([
        'stripe_id' => 'si_replaced_pro',
        'stripe_product' => 'prod_pro_test',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);
    managedPageWorkspace($workspace);
    cacheManagedRenewalForPage();

    livewire(Billing::class)
        ->assertSee('Renews '.now()->addMonths(6)->toFormattedDateString())
        ->assertSee(__('billing.manage.button'));
});

it('omits the renewal line when nothing is cached and asks Stripe for it in the background', function (): void {
    [, $workspace] = billingPageOwner();
    managedPageWorkspace($workspace);

    livewire(Billing::class)
        ->assertDontSee('Renews')
        ->assertSee(__('billing.manage.button'));

    Queue::assertPushed(RefreshManagedRenewal::class, fn (RefreshManagedRenewal $job): bool => $job->stripeSubscriptionId === 'sub_managed_page');
});

it('warns the owner when the managed invoice is overdue', function (): void {
    [, $workspace] = billingPageOwner();
    managedPageWorkspace($workspace, 'past_due');

    livewire(Billing::class)
        ->assertSee(__('billing.managed.overdue_title', ['plan' => 'Cloud Concierge']))
        ->assertSee(__('billing.managed.overdue_body', ['button' => __('billing.manage.button')]))
        ->assertSee('Pay it from '.__('billing.manage.button').' to keep the plan.');
});

it('marks an overdue managed plan past due with a pay button in its banner', function (): void {
    [, $workspace] = billingPageOwner();
    managedPageWorkspace($workspace, 'past_due');

    $html = livewire(Billing::class)
        ->assertSee(__('billing.status.past_due'))
        ->assertDontSee(__('billing.status.managed'))
        ->assertSee(__('billing.managed.tagline_portal'))
        ->assertDontSee(__('billing.manage.past_due_title'))
        ->html();

    expect(substr_count($html, 'wire:click="managePortal"'))->toBe(2);
});

it('keeps the plain managed tagline when no subscription bills the plan', function (): void {
    [, $workspace] = billingPageOwner();
    $workspace->forceFill(['plan' => Plan::Concierge])->save();

    livewire(Billing::class)
        ->assertSee(__('billing.managed.tagline'))
        ->assertSee(__('billing.status.managed'));
});

it('hides the renewal line while the managed invoice is overdue', function (): void {
    [, $workspace] = billingPageOwner();
    managedPageWorkspace($workspace, 'past_due');
    cacheManagedRenewalForPage();

    livewire(Billing::class)
        ->assertSee(__('billing.managed.overdue_title', ['plan' => 'Cloud Concierge']))
        ->assertDontSee('Renews');
});

it('hides the renewal line once the managed plan is set to cancel', function (): void {
    [, $workspace] = billingPageOwner();
    managedPageWorkspace($workspace)->forceFill(['ends_at' => now()->addDays(10)])->save();
    cacheManagedRenewalForPage();

    livewire(Billing::class)->assertDontSee('Renews');
});

it('ignores a managed subscription that sells a plan the workspace does not hold', function (): void {
    [, $workspace] = billingPageOwner();
    managedPageWorkspace($workspace, 'past_due', Plan::Concierge, held: Plan::Enterprise);
    cacheManagedRenewalForPage();

    livewire(Billing::class)
        ->assertDontSee(__('billing.managed.overdue_title', ['plan' => 'Enterprise']))
        ->assertDontSee(__('billing.managed.overdue_title', ['plan' => 'Cloud Concierge']))
        ->assertDontSee('Renews')
        ->assertDontSee(__('billing.manage.button'));
});

it('shows no overdue warning while the managed invoice is current', function (): void {
    [, $workspace] = billingPageOwner();
    managedPageWorkspace($workspace);

    livewire(Billing::class)
        ->assertDontSee(__('billing.managed.overdue_title', ['plan' => 'Cloud Concierge']));
});

it('opens the Stripe portal with the managed configuration', function (): void {
    config()->set('services.stripe.portal_configurations.managed', 'bpc_managed_test');
    config()->set('cashier.secret', 'sk_test_fake');
    $recorder = StripeRecorder::install();
    [, $workspace] = billingPageOwner();
    managedPageWorkspace($workspace);

    livewire(Billing::class)->call('managePortal');

    expect($recorder->paramsFor('/billing_portal/sessions')['configuration'])->toBe('bpc_managed_test')
        ->and($recorder->paramsFor('/billing_portal/sessions')['customer'])->toBe('cus_managed_page');
});

it('opens the default Stripe portal when no managed configuration is set', function (): void {
    config()->set('services.stripe.portal_configurations.managed', null);
    config()->set('cashier.secret', 'sk_test_fake');
    $recorder = StripeRecorder::install();
    [, $workspace] = billingPageOwner();
    managedPageWorkspace($workspace);

    livewire(Billing::class)->call('managePortal');

    expect($recorder->paramsFor('/billing_portal/sessions'))->not->toHaveKey('configuration');
});

it('shows a member the managed plan without billing controls', function (): void {
    [, $workspace] = billingPageOwner();
    managedPageWorkspace($workspace, 'past_due');
    cacheManagedRenewalForPage();
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'member']);

    test()->actingAs($member);
    Filament::setTenant($workspace->refresh());

    livewire(Billing::class)
        ->assertSee(__('billing.plans.cloud_concierge'))
        ->assertSee(__('billing.member.ask_owner', ['owner' => $workspace->owner->name]))
        ->assertDontSee(__('billing.manage.button'))
        ->assertDontSee('Renews')
        ->assertDontSee(__('billing.managed.overdue_title', ['plan' => 'Cloud Concierge']));
});
