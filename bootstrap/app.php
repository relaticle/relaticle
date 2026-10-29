<?php

declare(strict_types=1);

use App\Features\EmailIntegration;
use App\Http\Controllers\Billing\StripeWebhookController;
use App\Http\Middleware\ConfigureTrustedProxies;
use App\Http\Middleware\DenyIndexingOnSecondaryHosts;
use App\Http\Middleware\EnsureAuthenticationComplete;
use App\Http\Middleware\NoReferrer;
use App\Http\Middleware\ReadAcrossWorkspaces;
use App\Http\Middleware\RedirectToPrimaryHost;
use App\Http\Middleware\RequireIdentityConfirmation;
use App\Http\Middleware\RequireOperationGrant;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetApiWorkspaceContext;
use App\Http\Middleware\StopImpersonationOnLogout;
use App\Http\Middleware\SubdomainRootResponse;
use App\Http\Middleware\SuperviseImpersonation;
use App\Http\Middleware\ThrottleBeforeAuthentication;
use App\Http\Middleware\ValidateSignature;
use App\Models\Notification;
use Filament\Facades\Filament;
use Filament\Http\Middleware\SetUpPanel;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;
use Laravel\Fortify\Fortify;
use Laravel\Pennant\Feature;
use League\OAuth2\Server\Exception\OAuthServerException;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Exceptions\PayloadTooLargeException;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Features\SupportComputed\CannotCallComputedDirectlyException;
use Livewire\Features\SupportLifecycleHooks\DirectlyCallingLifecycleHooksNotAllowedException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Mechanisms\HandleComponents\CorruptComponentPayloadException;
use Relaticle\SystemAdmin\Http\Middleware\EnsureAuthenticationContext;
use Relaticle\SystemAdmin\Http\Middleware\IsolateAuthenticationSession;
use Sentry\Laravel\Integration;
use Spatie\Health\Commands\DispatchQueueCheckJobsCommand;
use Spatie\Health\Commands\RunHealthChecksCommand;
use Spatie\Health\Commands\ScheduleCheckHeartbeatCommand;
use Spatie\MarkdownResponse\Actions\DetectsMarkdownRequest;
use Spatie\MarkdownResponse\Enums\DetectionMethod;
use Spatie\MarkdownResponse\Support\Config;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
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
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: [
            'document.*',
        ]);

        $middleware->convertEmptyStringsToNull(except: [
            fn (Request $request): bool => $request->is('chat') || $request->is('chat/*'),
        ]);

        // On Laravel Cloud the framework trusts its TLS edge itself, which reaches the app from a
        // public IPv6 address the private ranges would reject, turning every generated URL into http://.
        if (! laravel_cloud()) {
            $middleware->prepend(ConfigureTrustedProxies::class);
            $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PORT);
        }

        $middleware->prepend(SubdomainRootResponse::class);

        // Outermost (last prepend wins the front slot) so the noindex header
        // also lands on responses SubdomainRootResponse short-circuits. The
        // api/mcp root banners are exactly the crawlable secondary-host URLs.
        $middleware->prepend(DenyIndexingOnSecondaryHosts::class);

        // Controller constructors can resolve sessions before route middleware runs.
        $middleware->append(IsolateAuthenticationSession::class);

        // Global rather than the web group: Filament panel routes do not run the web group.
        $middleware->append(SecurityHeaders::class);

        $middleware->authenticateSessions();

        $middleware->web(
            append: [
                'auth.context',
                RedirectToPrimaryHost::class,
                EnsureAuthenticationComplete::class,
                StopImpersonationOnLogout::class,
                SuperviseImpersonation::class,
            ],
        );

        // Anchored patterns, because Symfony matches them as unanchored regex.
        $middleware->trustHosts(at: function (): array {
            $primaryHost = parse_url((string) config('app.url'), PHP_URL_HOST);

            $dedicatedDomains = array_filter(
                [
                    config('app.api_domain'),
                    config('app.mcp_domain'),
                    config('app.sysadmin_domain'),
                    config('app.app_panel_domain'),
                ],
                fn (mixed $domain): bool => is_string($domain) && $domain !== '',
            );

            $hosts = array_filter([
                $primaryHost,
                is_string($primaryHost) ? "www.{$primaryHost}" : null,
                ...array_values($dedicatedDomains),
                ...config('app.trusted_hosts'),
            ], is_string(...));

            return array_map(
                fn (string $host): string => '^'.preg_quote($host).'$',
                array_values(array_unique($hosts)),
            );
        }, subdomains: false);

        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetApiWorkspaceContext::class,
        );

        // Textual order in a route's middleware array does not decide execution
        // order: SortedMiddleware resorts by this priority list. Critically,
        // an unmapped middleware sitting between two mapped ones (e.g. between
        // the 'web' group's SubstituteBindings and 'auth') gets dragged along
        // when the higher-priority one jumps forward. Only registering our own
        // class here keeps ThrottleBeforeAuthentication running before auth,
        // without moving the framework's own ThrottleRequests (used by 'throttle'
        // elsewhere, e.g. routes/api.php, routes/ai.php) relative to auth.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: ThrottleBeforeAuthentication::class,
        );

        $middleware->prependToPriorityList(
            before: EncryptCookies::class,
            prepend: SetUpPanel::class,
        );

        $middleware->appendToPriorityList(
            after: StartSession::class,
            append: EnsureAuthenticationContext::class,
        );

        $middleware->prependToPriorityList(
            before: SetUpPanel::class,
            prepend: RedirectToPrimaryHost::class,
        );

        $middleware->alias([
            'auth.context' => EnsureAuthenticationContext::class,
            'signed' => ValidateSignature::class,
            'no-referrer' => NoReferrer::class,
            'workspaces.across' => ReadAcrossWorkspaces::class,
            // Fortify and Passkeys both reference this alias by name in their own
            // route definitions; overriding it here (rather than swapping every
            // route's middleware array) is the only way to reach both packages'
            // routes with the same scoped confirmation policy as the UI.
            'password.confirm' => RequireIdentityConfirmation::class,
            'require-operation' => RequireOperationGrant::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
            'mail/unsubscribe/*',
        ]);

        $middleware->redirectGuestsTo(function (Request $request): string {
            // The login page's signup branch handles both an invited email that
            // already has an account and one that does not, from the same URL,
            // and a shared join link carries no email to tell them apart with.
            if ($request->routeIs('workspace-invitations.token.accept', 'workspaces.join')) {
                return Filament::getLoginUrl();
            }

            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);
        $rendersJson = fn (Request $request): bool => $request->is('api/*') || $request->getHost() === config('app.api_domain') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($rendersJson);

        // Detected like every markdown page: wire:navigate fetches send Accept */*,
        // so reading the header alone would swap the panel for this body.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($rendersJson): ?Response {
            if ($rendersJson($request)) {
                return null;
            }

            if (! Config::getAction('detection.detector', DetectsMarkdownRequest::class)($request) instanceof DetectionMethod) {
                return null;
            }

            $indexes = array_filter([
                __('Site index') => 'llms-txt',
                __('Help centre') => 'help.index',
                __('Developer docs') => 'documentation.index',
                __('REST API spec') => 'openapi.json',
            ], Route::has(...));

            $lines = ['# '.__('Not found'), '', __('Nothing lives at :url.', ['url' => '`'.str_replace('`', '%60', $request->url()).'`']), ''];

            foreach ($indexes as $label => $routeName) {
                $lines[] = "- {$label}: ".route($routeName);
            }

            $lines[] = '- '.__('Home: :url', ['url' => config('app.url')]);
            $lines[] = '';

            return response(implode("\n", $lines), 404, ['Content-Type' => 'text/markdown; charset=UTF-8']);
        });

        // An expired or wrong-account verification link returns to the prompt, where Resend gets a working one.
        $exceptions->render(function (HttpException $e, Request $request): ?RedirectResponse {
            if ($e->getStatusCode() !== 403 || ! $request->routeIs('filament.app.auth.email-verification.verify')) {
                return null;
            }

            FilamentNotification::make()->title(__('auth.verify_email.link_unusable'))->warning()->persistent()->send();

            return to_route('filament.app.auth.email-verification.prompt');
        });

        // Sign-in and provider steps are retried, so a throttled one goes back with the wait instead of a bare 429 page.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($rendersJson): ?RedirectResponse {
            if ($rendersJson($request)) {
                return null;
            }

            $wait = __('auth.throttle', ['seconds' => $e->getHeaders()['Retry-After'] ?? 60]);

            if ($request->routeIs('auth.socialite.*')) {
                FilamentNotification::make()->title(__('auth.provider.failed_title'))->body($wait)->danger()->persistent()->send();

                return $request->user() === null
                    ? to_route('login')
                    : redirect()->intended(Fortify::redirects('password-confirmation'));
            }

            $form = match (true) {
                $request->routeIs('two-factor.login.store') => route('two-factor.login'),
                $request->routeIs('identity.confirm.mfa.store') => route('identity.confirm.mfa'),
                default => null,
            };

            return $form === null ? null : redirect()->to($form)->withErrors(['code' => $wait]);
        });

        // Stale tabs and deploy boundaries produce checksum failures that
        // Livewire already renders as 419 (page expired -> client refreshes).
        // They are user-state noise, not actionable errors, so keep them out of
        // Sentry (issue #125406836).
        $exceptions->dontReport(CorruptComponentPayloadException::class);

        // Tampered updates are client noise, not bugs. Logging inside the dontReport check is the
        // only point that runs before Sentry and before an exception's own report().
        $livewireTamperExceptions = [
            CannotUpdateLockedPropertyException::class,
            PublicPropertyNotFoundException::class,
            DirectlyCallingLifecycleHooksNotAllowedException::class,
            MethodNotFoundException::class,
            CannotCallComputedDirectlyException::class,
        ];
        $isLivewireTamper = fn (Throwable $e): bool => Arr::first(
            $livewireTamperExceptions,
            fn (string $class): bool => $e instanceof $class,
        ) !== null;

        $exceptions->dontReportWhen(function (Throwable $e) use ($isLivewireTamper): bool {
            if (! $isLivewireTamper($e) || ! request()->hasHeader('X-Livewire')) {
                return false;
            }

            Log::warning('Livewire request rejected as tampered', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'components' => collect(request()->array('components'))
                    ->map(fn (mixed $component): mixed => is_string($snapshot = data_get($component, 'snapshot'))
                        ? data_get(json_decode($snapshot, true), 'memo.name')
                        : null)
                    ->filter()
                    ->values()
                    ->all(),
                'ip' => request()->ip(),
            ]);

            return true;
        });

        $exceptions->render(function (Throwable $e, Request $request) use ($isLivewireTamper): ?Response {
            if (! $request->hasHeader('X-Livewire') || ! $isLivewireTamper($e)) {
                return null;
            }

            return response('', 419);
        });

        // Passport reports every rejected bearer token; keep league's 5xx server errors visible.
        $exceptions->dontReportWhen(
            fn (Throwable $e): bool => $e instanceof OAuthServerException && $e->getHttpStatusCode() < 500,
        );

        // Livewire rejects an oversized body before the component hydrates, so the panel
        // cannot notify from the server; payload-guard.js turns this into a notification
        // instead of the full-screen error overlay.
        $exceptions->render(function (PayloadTooLargeException $e, Request $request): ?JsonResponse {
            if (! $request->hasHeader('X-Livewire')) {
                return null;
            }

            return response()->json(['message' => __('filament/panel.payload_too_large')], 413);
        });
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('app:generate-sitemap')->daily()->onOneServer();
        $schedule->command('import:cleanup')->hourly()->onOneServer();
        $schedule->command('app:purge-pending-uploads')->hourly()->withoutOverlapping()->onOneServer();
        $schedule->command('queue:prune-batches --hours=24')->daily()->onOneServer();
        $schedule->command('invitations:cleanup')->daily()->onOneServer();
        $schedule->command('activitylog:clean --force')->daily()->onOneServer();
        $schedule->command('model:prune', ['--model' => [Notification::class]])->daily()->onOneServer();
        $schedule->command('chat:expire-pending-actions')->everyFiveMinutes()->onOneServer();
        $schedule->command('chat:release-orphaned-reservations')->everyTenMinutes()->withoutOverlapping()->onOneServer();
        $schedule->command('chat:purge-unsent-attachments')->hourly()->withoutOverlapping()->onOneServer();
        $schedule->command('chat:reset-credits')->hourly()->withoutOverlapping()->onOneServer();
        $schedule->command('billing:process-trials')->dailyAt('00:15')->withoutOverlapping()->onOneServer();
        $schedule->command('disposable:update')->weekly()->withoutOverlapping()->onOneServer();
        $schedule->command('subscribers:reconcile --limit=500')->dailyAt('02:00')
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command('app:purge-scheduled-deletions')->daily()->withoutOverlapping()->onOneServer();
        $schedule->command('notifications:send-task-digest')->hourly()->withoutOverlapping()->onOneServer();
        $schedule->command('notifications:send-setup-nudge')->hourly()->withoutOverlapping()->onOneServer();

        if (Feature::for(null)->active(EmailIntegration::class)) {
            $schedule->command('email:incremental-sync')
                ->everyFiveMinutes()
                ->name('email:incremental-sync')
                ->withoutOverlapping()
                ->onOneServer();

            $schedule->command('calendar:incremental-sync')
                ->everyFiveMinutes()
                ->name('calendar:incremental-sync')
                ->withoutOverlapping()
                ->onOneServer();

            $schedule->command('calendar:renew-push-channels')
                ->daily()
                ->name('calendar:renew-push-channels')
                ->withoutOverlapping()
                ->onOneServer();

            $schedule->command('email:dispatch-outbox')
                ->everyMinute()
                ->name('email:dispatch-outbox')
                ->withoutOverlapping()
                ->onOneServer();

            $schedule->command('email:expire-access-requests')
                ->hourly()
                ->name('email:expire-access-requests')
                ->withoutOverlapping()
                ->onOneServer();
        }

        if (config('app.health_checks_enabled')) {
            $schedule->command(RunHealthChecksCommand::class)->everyMinute()->onOneServer();
            $schedule->command(DispatchQueueCheckJobsCommand::class)->everyMinute()->onOneServer();
            $schedule->command(ScheduleCheckHeartbeatCommand::class)->everyMinute()->onOneServer();
        }
    })
    ->booting(function (): void {
        //        Model::automaticallyEagerLoadRelationships(); TODO: Before enabling this, check the test suite for any issues with eager loading.
    })
    ->create();
