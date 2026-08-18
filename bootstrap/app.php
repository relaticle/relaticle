<?php

declare(strict_types=1);

use App\Http\Controllers\Billing\StripeWebhookController;
use App\Http\Middleware\DenyIndexingOnSecondaryHosts;
use App\Http\Middleware\NoReferrer;
use App\Http\Middleware\RedirectToPrimaryHost;
use App\Http\Middleware\SetApiTeamContext;
use App\Http\Middleware\SubdomainRootResponse;
use App\Http\Middleware\ValidateSignature;
use App\Models\TeamInvitation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;
use Livewire\Mechanisms\HandleComponents\CorruptComponentPayloadException;
use Sentry\Laravel\Integration;
use Spatie\Health\Commands\DispatchQueueCheckJobsCommand;
use Spatie\Health\Commands\RunHealthChecksCommand;
use Spatie\Health\Commands\ScheduleCheckHeartbeatCommand;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function (): void {
            // Registered here rather than by Cashier (see AppServiceProvider):
            // signature verification must apply whether or not the webhook
            // secret is configured, otherwise an unset secret silently turns
            // this into an unauthenticated, plan-mutating endpoint.
            Route::post('stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])
                ->middleware(VerifyWebhookSignature::class)
                ->name('cashier.webhook');

            $apiDomain = config('app.api_domain');

            $routes = Route::middleware('api');

            if ($apiDomain) {
                $routes->domain($apiDomain);
            } else {
                $routes->prefix('api');
            }

            $routes->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: [
            'document.*',
        ]);

        $middleware->convertEmptyStringsToNull(except: [
            fn (Request $request): bool => $request->is('chat') || $request->is('chat/*'),
        ]);

        $middleware->trustProxies(at: [
            '127.0.0.0/8',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '169.254.0.0/16',
            '::1/128',
            'fc00::/7',
            'fe80::/10',
        ]);

        $middleware->prepend(SubdomainRootResponse::class);

        // Outermost (last prepend wins the front slot) so the noindex header
        // also lands on responses SubdomainRootResponse short-circuits — the
        // api/mcp root banners are exactly the crawlable secondary-host URLs.
        $middleware->prepend(DenyIndexingOnSecondaryHosts::class);

        $middleware->web(append: RedirectToPrimaryHost::class);

        // Only enforced on multi-host deployments (any *_DOMAIN configured);
        // the framework already skips TrustHosts in local and test runs.
        // Anchored patterns, because Symfony matches them as unanchored regex.
        $middleware->trustHosts(at: function (): array {
            $dedicatedDomains = array_filter(
                [
                    config('app.api_domain'),
                    config('app.mcp_domain'),
                    config('app.sysadmin_domain'),
                    config('app.app_panel_domain'),
                ],
                fn (mixed $domain): bool => is_string($domain) && $domain !== '',
            );

            if ($dedicatedDomains === []) {
                return [];
            }

            $primaryHost = parse_url((string) config('app.url'), PHP_URL_HOST);

            $hosts = array_filter([
                $primaryHost,
                is_string($primaryHost) ? "www.{$primaryHost}" : null,
                ...array_values($dedicatedDomains),
            ], is_string(...));

            return array_map(
                fn (string $host): string => '^'.preg_quote($host).'$',
                array_values($hosts),
            );
        }, subdomains: false);

        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetApiTeamContext::class,
        );

        $middleware->alias([
            'signed' => ValidateSignature::class,
            'no-referrer' => NoReferrer::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);

        $middleware->redirectGuestsTo(function (Request $request): string {
            if ($request->routeIs('team-invitations.accept')) {
                $invitation = TeamInvitation::query()
                    ->whereKey($request->route('invitation'))
                    ->first();

                if ($invitation && User::query()->where('email', $invitation->email)->exists()) {
                    return Filament::getLoginUrl();
                }

                return Filament::getRegistrationUrl();
            }

            // A shared join link carries no email, so we cannot tell whether the
            // visitor has an account. Most people opening one do not, and the
            // register page links back to sign-in for the rest.
            if ($request->routeIs('teams.join')) {
                return Filament::getRegistrationUrl();
            }

            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*') || $request->getHost() === config('app.api_domain') || $request->expectsJson());

        // Stale tabs and deploy boundaries produce checksum failures that
        // Livewire already renders as 419 (page expired -> client refreshes).
        // They are user-state noise, not actionable errors — keep them out of
        // Sentry (issue #125406836).
        $exceptions->dontReport(CorruptComponentPayloadException::class);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('app:generate-sitemap')->daily();
        $schedule->command('import:cleanup')->hourly();
        $schedule->command('queue:prune-batches --hours=24')->daily();
        $schedule->command('invitations:cleanup')->daily();
        $schedule->command('activitylog:clean --force')->daily();
        $schedule->command('chat:expire-pending-actions')->everyFiveMinutes();
        $schedule->command('chat:release-orphaned-reservations')->everyTenMinutes()->withoutOverlapping()->onOneServer();
        $schedule->command('chat:reset-credits')->hourly()->withoutOverlapping()->onOneServer();
        $schedule->command('billing:process-trials')->dailyAt('00:15')->withoutOverlapping()->onOneServer();
        $schedule->command('disposable:update')->weekly()->withoutOverlapping()->onOneServer();
        $schedule->command('subscribers:sync-recency-tags')->dailyAt('02:00')
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command('app:purge-scheduled-deletions')->daily()->withoutOverlapping()->onOneServer();
        $schedule->command('notifications:send-task-digest')->hourly()->withoutOverlapping()->onOneServer();

        if (config('app.health_checks_enabled')) {
            $schedule->command(RunHealthChecksCommand::class)->everyMinute();
            $schedule->command(DispatchQueueCheckJobsCommand::class)->everyMinute();
            $schedule->command(ScheduleCheckHeartbeatCommand::class)->everyMinute();
        }
    })
    ->booting(function (): void {
        //        Model::automaticallyEagerLoadRelationships(); TODO: Before enabling this, check the test suite for any issues with eager loading.
    })
    ->create();
