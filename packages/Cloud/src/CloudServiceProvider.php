<?php

declare(strict_types=1);

namespace Relaticle\Cloud;

use App\Contracts\Billing as BillingContract;
use App\Contracts\Entitlements;
use App\Features\Billing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\NoBilling;
use App\Services\SelfHostedEntitlements;
use App\Support\Mail\MailPreview;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Pennant\Feature;
use Relaticle\Cloud\Billing\Commands\ProcessTrialsCommand;
use Relaticle\Cloud\Billing\Listeners\SyncPlanOnStripeSubscriptionChange;
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

        $this->aliasStoredNotificationTypes();
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'cloud');
        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');

        Event::listen(WebhookHandled::class, [SyncPlanOnStripeSubscriptionChange::class, 'handle']);

        $this->commands([ProcessTrialsCommand::class]);

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('billing:process-trials')->dailyAt('00:15')->withoutOverlapping()->onOneServer();
        });

        $this->registerMailPreviews();
    }

    private function aliasStoredNotificationTypes(): void
    {
        // A bell row stores its notification class name, and rows written before the move still carry these.
        foreach ([
            'App\Notifications\PaymentFailedNotification' => PaymentFailedNotification::class,
            'App\Notifications\ManagedInvoiceOverdueNotification' => ManagedInvoiceOverdueNotification::class,
        ] as $storedType => $notification) {
            if (! class_exists($storedType, false)) {
                class_alias($notification, $storedType);
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
