<?php

declare(strict_types=1);

namespace Relaticle\Cloud;

use App\Contracts\Billing as BillingContract;
use App\Contracts\Entitlements;
use App\Features\Billing;
use App\Models\User;
use App\Models\Workspace;
use App\Providers\Filament\AppPanelProvider;
use App\Services\NoBilling;
use App\Services\SelfHostedEntitlements;
use App\Support\Mail\MailPreview;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\Cloud\Billing\Commands\ProcessTrialsCommand;
use Relaticle\Cloud\Billing\Filament\Billing as BillingPage;
use Relaticle\Cloud\Billing\Filament\BillingPanelHooks;
use Relaticle\Cloud\Billing\Jobs\RefreshManagedRenewal;
use Relaticle\Cloud\Billing\Listeners\SyncPlanOnStripeSubscriptionChange;
use Relaticle\Cloud\Billing\Livewire\UpgradeModal;
use Relaticle\Cloud\Billing\Mail\ProEndedMail;
use Relaticle\Cloud\Billing\Mail\ProTrialEndingSoonMail;
use Relaticle\Cloud\Billing\Notifications\ManagedInvoiceOverdueNotification;
use Relaticle\Cloud\Billing\Notifications\PaymentFailedNotification;
use Relaticle\Cloud\Billing\StripeBilling;
use Relaticle\Cloud\Entitlements\PlanEntitlements;

final class CloudServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Entitlements::class, static fn (Application $app): Entitlements => Feature::active(Billing::class)
            ? $app->make(PlanEntitlements::class)
            : $app->make(SelfHostedEntitlements::class));

        $this->app->bind(BillingContract::class, static fn (Application $app): BillingContract => Feature::active(Billing::class)
            ? $app->make(StripeBilling::class)
            : $app->make(NoBilling::class));

        $this->aliasPreMoveClassNames();
        $this->extendAppPanel();
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'cloud');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'cloud');
        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');

        Livewire::component('app.billing.upgrade-modal', UpgradeModal::class);

        Event::listen(WebhookHandled::class, [SyncPlanOnStripeSubscriptionChange::class, 'handle']);

        $this->commands([ProcessTrialsCommand::class]);

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('billing:process-trials')->dailyAt('00:15')->withoutOverlapping()->onOneServer();
        });

        $this->registerMailPreviews();
    }

    private function extendAppPanel(): void
    {
        Panel::configureUsing(static function (Panel $panel): void {
            if ($panel->getId() !== 'app') {
                return;
            }

            $panel
                ->pages([BillingPage::class])
                ->renderHook(PanelsRenderHook::BODY_END, static fn (): string => resolve(BillingPanelHooks::class)->upgradeModal())
                ->renderHook(AppPanelProvider::SIDEBAR_FOOTER_ACTIONS, static fn (): string => resolve(BillingPanelHooks::class)->sidebarBilling());
        });
    }

    private function aliasPreMoveClassNames(): void
    {
        // Stored bell rows and queued mail and job payloads name these pre-move classes. Queued notifications
        // still lose their private properties under a new class name, so the deploy drains the queue first.
        foreach ([
            'App\Notifications\PaymentFailedNotification' => PaymentFailedNotification::class,
            'App\Notifications\ManagedInvoiceOverdueNotification' => ManagedInvoiceOverdueNotification::class,
            'App\Mail\ProEndedMail' => ProEndedMail::class,
            'App\Mail\ProTrialEndingSoonMail' => ProTrialEndingSoonMail::class,
            'App\Jobs\RefreshManagedRenewal' => RefreshManagedRenewal::class,
        ] as $preMoveName => $current) {
            if (! class_exists($preMoveName, false)) {
                class_alias($current, $preMoveName);
            }
        }
    }

    private function registerMailPreviews(): void
    {
        MailPreview::register('trial-ending', static function (User $owner, Workspace $workspace): Mailable {
            $workspace->setRelation('owner', $owner);

            return new ProTrialEndingSoonMail($workspace);
        });

        MailPreview::register('trial-ended', static fn (User $owner, Workspace $workspace): Mailable => ProEndedMail::afterTrial($workspace));

        MailPreview::register('subscription-ended', static fn (User $owner, Workspace $workspace): Mailable => ProEndedMail::afterSubscription($workspace));
    }
}
