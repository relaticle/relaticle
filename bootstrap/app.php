<?php

declare(strict_types=1);

use App\Http\Middleware\SetApiTeamContext;
use App\Http\Middleware\SubdomainRootResponse;
use App\Http\Middleware\ValidateSignature;
use App\Models\TeamInvitation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Jobs\IncrementalEmailSyncJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Sentry\Laravel\Integration;
use Spatie\Health\Commands\DispatchQueueCheckJobsCommand;
use Spatie\Health\Commands\RunHealthChecksCommand;
use Spatie\Health\Commands\ScheduleCheckHeartbeatCommand;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        health: '/up',
        then: function (): void {
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
        $middleware->prepend(SubdomainRootResponse::class);

        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetApiTeamContext::class,
        );

        $middleware->alias([
            'signed' => ValidateSignature::class,
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

            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*') || $request->getHost() === config('app.api_domain') || $request->expectsJson());
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('app:generate-sitemap')->daily();
        $schedule->command('import:cleanup')->hourly();
        $schedule->command('queue:prune-batches --hours=24')->daily();
        $schedule->command('invitations:cleanup')->daily();
        $schedule->command('activitylog:clean')->daily();
        $schedule->command('app:purge-scheduled-deletions')->daily()->withoutOverlapping()->onOneServer();

        // TODO::Separate it in different command class
        $schedule->call(function (): void {
            ConnectedAccount::query()->where('status', EmailAccountStatus::ACTIVE)
                ->whereNotNull('sync_cursor')
                ->each(fn (ConnectedAccount $account): PendingDispatch => dispatch(new IncrementalEmailSyncJob($account)));
        })
            ->everyFiveMinutes()
            ->name('email:incremental-sync')
            ->withoutOverlapping();

        $schedule->command('email:dispatch-outbox')
            ->everyMinute()
            ->name('email:dispatch-outbox')
            ->withoutOverlapping();

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
