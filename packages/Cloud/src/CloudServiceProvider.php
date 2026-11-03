<?php

declare(strict_types=1);

namespace Relaticle\Cloud;

use App\Contracts\Billing as BillingContract;
use App\Contracts\Entitlements;
use App\Enums\Plan;
use App\Events\WorkspaceCreated;
use App\Events\WorkspaceDeleted;
use App\Events\WorkspaceSetupCompleted;
use App\Features\Billing;
use App\Features\Documentation;
use App\Models\User;
use App\Models\Workspace;
use App\Providers\Filament\AppPanelProvider;
use App\Services\NoBilling;
use App\Services\SelfHostedEntitlements;
use App\Support\Mail\MailPreview;
use Carbon\CarbonImmutable;
use Filament\Auth\Events\Registered;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Verified;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\Cloud\Admin\CloudAdminPlugin;
use Relaticle\Cloud\Admin\Mail\ManagedPlanInvoiceMail;
use Relaticle\Cloud\Analytics\Actions\AssociateAcquisitionWorkspace;
use Relaticle\Cloud\Analytics\Actions\CompleteAcquisitionSetup;
use Relaticle\Cloud\Analytics\Actions\RecordAcquisition;
use Relaticle\Cloud\Analytics\Attribution;
use Relaticle\Cloud\Analytics\Http\Middleware\CaptureAcquisition;
use Relaticle\Cloud\Analytics\Listeners\CaptureSubscriptionOutcome;
use Relaticle\Cloud\Billing\Commands\AnnounceInactivityRuleCommand;
use Relaticle\Cloud\Billing\Commands\ProcessTrialsCommand;
use Relaticle\Cloud\Billing\Commands\ScheduleInactiveTrialDeletionsCommand;
use Relaticle\Cloud\Billing\Filament\Billing as BillingPage;
use Relaticle\Cloud\Billing\Filament\BillingPanelHooks;
use Relaticle\Cloud\Billing\Jobs\RefreshManagedRenewal;
use Relaticle\Cloud\Billing\Listeners\CancelInactivityDeletionOnLogin;
use Relaticle\Cloud\Billing\Listeners\SendInactiveWorkspaceDeletedMail;
use Relaticle\Cloud\Billing\Listeners\SyncPlanOnStripeSubscriptionChange;
use Relaticle\Cloud\Billing\Livewire\UpgradeModal;
use Relaticle\Cloud\Billing\Mail\InactiveWorkspaceDeletedMail;
use Relaticle\Cloud\Billing\Mail\InactiveWorkspaceDeletionReminderMail;
use Relaticle\Cloud\Billing\Mail\InactiveWorkspaceDeletionWarningMail;
use Relaticle\Cloud\Billing\Mail\InactivityRuleNoticeMail;
use Relaticle\Cloud\Billing\Mail\ProEndedMail;
use Relaticle\Cloud\Billing\Mail\ProTrialEndingSoonMail;
use Relaticle\Cloud\Billing\Notifications\ManagedInvoiceOverdueNotification;
use Relaticle\Cloud\Billing\Notifications\PaymentFailedNotification;
use Relaticle\Cloud\Billing\StripeBilling;
use Relaticle\Cloud\Entitlements\PlanEntitlements;
use Relaticle\Cloud\Marketing\Console\Commands\GenerateSitemapCommand;
use Relaticle\Cloud\Marketing\Console\Commands\ReportStaleCompetitorFactsCommand;
use Relaticle\Cloud\Marketing\Http\Middleware\AddVaryAcceptHeader;
use Relaticle\Cloud\Marketing\Mail\NewContactSubmissionMail;
use Relaticle\Cloud\Marketing\Services\DiscordService;
use Relaticle\Cloud\Marketing\Services\DockerHubService;
use Relaticle\Cloud\Marketing\Services\GitHubService;

final class CloudServiceProvider extends ServiceProvider
{
    private const string APP_THEME = 'packages/Cloud/resources/css/filament/app/theme.css';

    public function register(): void
    {
        $this->app->bind(Entitlements::class, static fn (Application $app): Entitlements => Feature::active(Billing::class)
            ? $app->make(PlanEntitlements::class)
            : $app->make(SelfHostedEntitlements::class));

        $this->app->bind(BillingContract::class, static fn (Application $app): BillingContract => Feature::active(Billing::class)
            ? $app->make(StripeBilling::class)
            : $app->make(NoBilling::class));

        $this->mergeConfigFrom(__DIR__.'/../config/comparisons.php', 'comparisons');
        $this->mergeConfigFrom(__DIR__.'/../config/analytics.php', 'analytics');

        $this->configureBlog();

        // Laravel finds errors::404 under each view path, so this root holds error pages only.
        config(['view.paths' => array_values(array_unique([...config('view.paths', []), __DIR__.'/../resources/views/overrides']))]);

        $this->aliasPreMoveClassNames();
        $this->extendAppPanel();
        $this->extendSystemAdminPanel();
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'cloud');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'cloud');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->configureAnalytics();
        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
        $this->registerMarketingRoutes();
        $this->registerDocsRoutes();

        Blade::componentNamespace('Relaticle\\Cloud\\Marketing\\View\\Components', 'cloud');

        Livewire::component('app.billing.upgrade-modal', UpgradeModal::class);
        Livewire::component('App\\Filament\\Pages\\Billing', BillingPage::class);

        Event::listen(WebhookHandled::class, [SyncPlanOnStripeSubscriptionChange::class, 'handle']);
        Event::listen(WebhookHandled::class, [CaptureSubscriptionOutcome::class, 'handle']);
        Event::listen(Login::class, [CancelInactivityDeletionOnLogin::class, 'handle']);
        Event::listen(WorkspaceDeleted::class, [SendInactiveWorkspaceDeletedMail::class, 'handle']);

        $this->commands([ProcessTrialsCommand::class, ScheduleInactiveTrialDeletionsCommand::class, AnnounceInactivityRuleCommand::class, GenerateSitemapCommand::class, ReportStaleCompetitorFactsCommand::class]);

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('billing:process-trials')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
            $schedule->command('billing:schedule-inactive-deletions')->dailyAt('02:00')->withoutOverlapping()->onOneServer();
            $schedule->command('app:generate-sitemap')->daily()->onOneServer();
        });

        $this->linkWebsiteFromCore();
        $this->registerMailPreviews();
        $this->registerCommunityCounts();
    }

    private function configureAnalytics(): void
    {
        Route::pushMiddlewareToGroup('web', CaptureAcquisition::class);

        Event::listen(Registered::class, static function (Registered $event): void {
            $user = $event->getUser();

            if ($user instanceof User && request()->hasSession() && ! Attribution::optedOut(request())) {
                resolve(RecordAcquisition::class)->execute($user, session('analytics.acquisition'));
                DB::afterCommit(static fn () => session()->forget('analytics.acquisition'));
            }
        });

        Event::listen(Verified::class, static function (Verified $event): void {
            if ($event->user instanceof User && request()->hasSession()) {
                DB::afterCommit(static fn () => session()->put('fathom.track_email_verified', (string) Str::uuid()));
            }
        });

        Event::listen(WorkspaceCreated::class, static function (WorkspaceCreated $event): void {
            resolve(AssociateAcquisitionWorkspace::class)->execute($event->workspace);
        });

        Event::listen(WorkspaceSetupCompleted::class, static function (WorkspaceSetupCompleted $event): void {
            resolve(CompleteAcquisitionSetup::class)->execute($event->workspace);

            if (request()->hasSession()) {
                session()->put('fathom.track_setup_completed', (string) Str::uuid());
            }
        });
    }

    private function configureBlog(): void
    {
        // Ink registers its routes from packageBooted(), after every register(), before Pennant is bound:
        // read the config key App\Features\Blog resolves from.
        config([
            'ink.features.public_routes' => (bool) config('relaticle.features.blog', false),
            'ink.features.mcp' => true,
            'ink.middleware' => array_values(array_unique([...config('ink.middleware', ['web']), AddVaryAcceptHeader::class])),
            'ink.views' => [
                'index' => 'cloud::blog.index',
                'show' => 'cloud::blog.show',
                'category' => 'cloud::blog.index',
                'tag' => 'cloud::blog.index',
                'preview' => 'cloud::blog.preview',
                'feed' => 'cloud::blog.feed',
            ],
        ]);
    }

    private function registerMarketingRoutes(): void
    {
        Route::middleware('web')->group(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/marketing.php');
        });
    }

    private function registerDocsRoutes(): void
    {
        if (! Feature::for(null)->active(Documentation::class)) {
            return;
        }

        Route::middleware('web')->group(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/docs.php');
        });
    }

    private function linkWebsiteFromCore(): void
    {
        config([
            'relaticle.legal.privacy_url' => config('relaticle.legal.privacy_url') ?: url()->getPublicUrl('privacy-policy'),
            'relaticle.legal.terms_url' => config('relaticle.legal.terms_url') ?: url()->getPublicUrl('terms-of-service'),
            'relaticle.legal.contact_url' => config('relaticle.legal.contact_url') ?: url()->getPublicUrl('contact'),
            'documentation.public_url' => url()->getPublicUrl(),
        ]);
    }

    private function extendAppPanel(): void
    {
        Panel::configureUsing(static function (Panel $panel): void {
            if ($panel->getId() !== 'app') {
                return;
            }

            $panel
                ->pages([BillingPage::class])
                ->bootUsing(static fn (Panel $panel): Panel => $panel->viteTheme(self::APP_THEME))
                ->renderHook(PanelsRenderHook::BODY_END, static fn (): string => resolve(BillingPanelHooks::class)->upgradeModal())
                ->renderHook(AppPanelProvider::SIDEBAR_FOOTER_ACTIONS, static fn (): string => resolve(BillingPanelHooks::class)->sidebarBilling());
        });
    }

    private function extendSystemAdminPanel(): void
    {
        Panel::configureUsing(static function (Panel $panel): void {
            if ($panel->getId() !== 'sysadmin') {
                return;
            }

            $panel->plugin(new CloudAdminPlugin);
        });
    }

    private function aliasPreMoveClassNames(): void
    {
        // Stored bell rows and queued mail and job payloads name these pre-move classes. Queued notifications
        // still lose their private properties under a new class name, so the deploy drains the queue first.
        $aliases = [
            'App\Notifications\PaymentFailedNotification' => PaymentFailedNotification::class,
            'App\Notifications\ManagedInvoiceOverdueNotification' => ManagedInvoiceOverdueNotification::class,
            'App\Mail\ProEndedMail' => ProEndedMail::class,
            'App\Mail\ProTrialEndingSoonMail' => ProTrialEndingSoonMail::class,
            'App\Jobs\RefreshManagedRenewal' => RefreshManagedRenewal::class,
            'Relaticle\SystemAdmin\Mail\ManagedPlanInvoiceMail' => ManagedPlanInvoiceMail::class,
            'App\Mail\NewContactSubmissionMail' => NewContactSubmissionMail::class,
        ];

        spl_autoload_register(static function (string $class) use ($aliases): void {
            if (isset($aliases[$class])) {
                class_alias($aliases[$class], $class);
            }
        });
    }

    private function registerMailPreviews(): void
    {
        MailPreview::register('trial-ending', static function (User $owner, Workspace $workspace): Mailable {
            $workspace->setRelation('owner', $owner);

            return new ProTrialEndingSoonMail($workspace, $workspace->trial_ends_at ?? now()->addDays(3));
        });

        MailPreview::register('trial-ended', static fn (User $owner, Workspace $workspace): Mailable => ProEndedMail::afterTrial(self::pausedTrial($workspace)));

        MailPreview::register('extension-ended', static fn (User $owner, Workspace $workspace): Mailable => ProEndedMail::afterTrial(self::pausedTrial($workspace, now()->subWeek())));

        MailPreview::register('subscription-ended', static fn (User $owner, Workspace $workspace): Mailable => ProEndedMail::afterSubscription($workspace));

        MailPreview::register('inactive-deletion-warning', static function (User $owner, Workspace $workspace): Mailable {
            $workspace->setRelation('owner', $owner);

            return new InactiveWorkspaceDeletionWarningMail($workspace, now()->addDays(14), now()->subDays(46));
        });

        MailPreview::register('inactive-deletion-reminder', static function (User $owner, Workspace $workspace): Mailable {
            $workspace->setRelation('owner', $owner);

            return new InactiveWorkspaceDeletionReminderMail($workspace, now()->addDays(5));
        });

        MailPreview::register('inactivity-rule-notice', static function (User $owner, Workspace $workspace): Mailable {
            $workspace->setRelation('owner', $owner);

            return new InactivityRuleNoticeMail($workspace, now()->addDays(60));
        });

        MailPreview::register('inactive-deleted', static fn (User $owner, Workspace $workspace): Mailable => new InactiveWorkspaceDeletedMail($workspace->name));

        MailPreview::register('contact-submission', static fn (): Mailable => new NewContactSubmissionMail([
            'name' => 'Ana Reyes',
            'email' => 'ana@acme-robotics.example',
            'company' => 'Acme Robotics',
            'message' => 'We are evaluating CRMs for a 12-person sales workspace and need SSO. Can we talk this week?',
        ]));
    }

    private static function pausedTrial(Workspace $workspace, ?CarbonImmutable $extendedAt = null): Workspace
    {
        return $workspace->forceFill([
            'plan' => Plan::Free,
            'trial_ends_at' => null,
            'pro_trial_used_at' => now()->subDay(),
            'trial_extended_at' => $extendedAt,
            'scheduled_deletion_at' => null,
        ]);
    }

    private function registerCommunityCounts(): void
    {
        View::composer(['cloud::components.layout.community-links', 'cloud::marketing.home.partials.hero', 'cloud::marketing.home.partials.works-with', 'cloud::marketing.press'], static function (ViewContract $view): void {
            $gitHubService = resolve(GitHubService::class);

            $view->with([
                'githubStars' => $gitHubService->getStarsCount(),
                'formattedGithubStars' => $gitHubService->getFormattedStarsCount(),
            ]);
        });

        View::composer('cloud::components.layout.community-links', static function (ViewContract $view): void {
            $view->with('formattedDiscordMembers', resolve(DiscordService::class)->getFormattedMemberCount());
        });

        View::composer('cloud::marketing.home.partials.works-with', static function (ViewContract $view): void {
            $view->with('formattedDockerPulls', resolve(DockerHubService::class)->getFormattedPullCount());
        });
    }
}
