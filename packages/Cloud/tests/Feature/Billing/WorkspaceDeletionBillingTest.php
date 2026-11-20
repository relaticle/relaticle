<?php

declare(strict_types=1);

use App\Actions\Jetstream\DeleteWorkspace;
use App\Actions\Jetstream\ScheduleWorkspaceDeletion;
use App\Enums\Plan;
use App\Livewire\App\Workspaces\WorkspaceDeletionBanner;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\WorkspaceDeletionCancelledNotification;
use Filament\Actions\Action;
use Filament\Notifications\Notification as Toast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Relaticle\Cloud\Billing\Actions\CancelWorkspaceSubscription;
use Relaticle\Cloud\Billing\Actions\KeepWorkspaceSubscription;
use Relaticle\Cloud\Billing\Data\Renewal;
use Relaticle\Cloud\Billing\Filament\Billing;
use Relaticle\Cloud\Billing\StripeBilling;
use Tests\Helpers\StripeRecorder;

mutates(CancelWorkspaceSubscription::class, StripeBilling::class, KeepWorkspaceSubscription::class);

it('does nothing when the workspace has no subscription', function (): void {
    /** @var Workspace $workspace */
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;

    app(CancelWorkspaceSubscription::class)->execute($workspace);
})->throwsNoExceptions();

it('does nothing when the subscription already ended', function (): void {
    /** @var Workspace $workspace */
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_done',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'ends_at' => now()->subDay(),
    ]);

    app(CancelWorkspaceSubscription::class)->execute($workspace, immediately: true);
})->throwsNoExceptions();

it('logs instead of throwing when stripe is unreachable for a live subscription', function (): void {
    /** @var Workspace $workspace */
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $workspace->forceFill(['stripe_id' => 'cus_x'])->save();
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_live',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    // Tests have no real STRIPE_SECRET, so the Cashier cancel call throws. The action logs and returns.
    app(CancelWorkspaceSubscription::class)->execute($workspace);

    expect($workspace->fresh()->subscription()->stripe_status)->toBe('active');
});

it('cancels managed subscriptions and voids their open invoices when the workspace is deleted', function (): void {
    withHostedBilling();
    config()->set('cashier.secret', 'sk_test_fake');

    /** @var Workspace $workspace */
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $workspace->forceFill(['stripe_id' => 'cus_deleted', 'plan' => Plan::Enterprise])->save();

    foreach (['managed' => 'sub_paid_managed', 'managed_pending' => 'sub_pending_managed'] as $type => $stripeId) {
        $workspace->subscriptions()->create([
            'type' => $type,
            'stripe_id' => $stripeId,
            'stripe_status' => 'active',
            'stripe_price' => "price_inline_{$stripeId}",
            'quantity' => 1,
        ]);
    }

    $stripe = StripeRecorder::install()
        ->respondTo('GET /v1/invoices', ['object' => 'list', 'has_more' => false, 'data' => [['id' => 'in_open', 'object' => 'invoice', 'status' => 'open']]])
        ->respondTo('/v1/subscriptions/', ['id' => 'sub_x', 'object' => 'subscription', 'status' => 'canceled']);

    app(DeleteWorkspace::class)->delete($workspace);

    StripeRecorder::uninstall();

    expect($stripe->countFor('DELETE /v1/subscriptions/sub_paid_managed'))->toBe(1)
        ->and($stripe->countFor('DELETE /v1/subscriptions/sub_pending_managed'))->toBe(1)
        ->and($stripe->countFor('POST /v1/invoices/in_open/void'))->toBe(2);
});

it('cancels the subscription at period end when workspace deletion is scheduled', function (): void {
    withHostedBilling();
    config()->set('cashier.secret', 'sk_test_fake');
    Notification::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['stripe_id' => 'cus_scheduled'])->save();
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_scheduled',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    $stripe = StripeRecorder::install()
        ->respondTo('/v1/subscriptions/sub_scheduled', ['id' => 'sub_scheduled', 'object' => 'subscription', 'status' => 'active', 'cancel_at_period_end' => true]);

    resolve(ScheduleWorkspaceDeletion::class)->schedule($user, $workspace);

    StripeRecorder::uninstall();

    expect($stripe->countFor('POST /v1/subscriptions/sub_scheduled'))->toBe(1)
        ->and($stripe->lastParamsFor('POST /v1/subscriptions/sub_scheduled'))->toHaveKey('cancel_at_period_end', true)
        ->and($stripe->countFor('DELETE /v1/subscriptions'))->toBe(0)
        ->and($workspace->refresh()->scheduled_deletion_at)->not->toBeNull();
});

/** @return array{0: User, 1: Workspace} */
function graceDeletionWorkspace(): array
{
    config()->set('services.stripe.prices.pro_monthly', 'price_pro_monthly_test');

    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro, 'stripe_id' => 'cus_undo', 'scheduled_deletion_at' => now()->addDays(30)])->save();
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_undo',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'ends_at' => now()->addDays(12),
    ]);
    Cache::forever(Renewal::cacheKey('sub_undo'), ['renews_at' => now()->addDays(12)->getTimestamp(), 'amount' => 2900, 'amount_due' => null, 'currency' => 'usd', 'card_brand' => 'visa', 'card_last4' => '4242']);

    return [$owner, $workspace];
}

function subscriptionEndingNotice(): string
{
    return __('cloud::billing.keep.ending_notice', ['plan' => Plan::Pro->marketingLabel(), 'date' => now()->addDays(12)->isoFormat('ll')]);
}

it('offers the owner to keep the subscription right after undoing a scheduled deletion', function (): void {
    withHostedBilling();
    Notification::fake();

    [$owner, $workspace] = graceDeletionWorkspace();
    $this->actingAs($owner);

    $keepLabel = __('cloud::billing.keep.button', ['plan' => Plan::Pro->marketingLabel()]);

    livewire(WorkspaceDeletionBanner::class, ['workspace' => $workspace])
        ->mountAction('cancelDeletion')
        ->callMountedAction()
        ->assertRedirect()
        ->assertNotified(Toast::make()
            ->title(__('workspaces.notifications.workspace_deletion_cancelled.success'))
            ->success()
            ->body(subscriptionEndingNotice())
            ->persistent()
            ->actions([Action::make('keepSubscription')->label($keepLabel)->url(Billing::getUrl(['action' => 'keepSubscription'], panel: 'app', tenant: $workspace))->button()]));
});

it('tells the owner in the deletion-cancelled email when the subscription ends and links to keep it', function (): void {
    withHostedBilling();

    [$owner, $workspace] = graceDeletionWorkspace();

    $html = (string) (new WorkspaceDeletionCancelledNotification($workspace))->toMail($owner)->render();

    expect($html)->toContain(e(subscriptionEndingNotice()))
        ->and($html)->toContain('action=keepSubscription');
});

it('says nothing about a subscription in the deletion-cancelled email when none is set to cancel', function (): void {
    withHostedBilling();

    [$owner, $workspace] = graceDeletionWorkspace();
    $workspace->subscription()->update(['ends_at' => null]);

    $html = (string) (new WorkspaceDeletionCancelledNotification($workspace))->toMail($owner)->render();

    expect($html)->not->toContain('action=keepSubscription');
});
