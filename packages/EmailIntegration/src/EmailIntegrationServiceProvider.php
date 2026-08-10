<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration;

use App\Features\EmailIntegration;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\GoogleProvider;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Console\Commands\BackfillEmailThreadsCommand;
use Relaticle\EmailIntegration\Console\Commands\DispatchOutboxCommand;
use Relaticle\EmailIntegration\Livewire\EmailComposer;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Factories\CalendarServiceFactory;
use Relaticle\EmailIntegration\Services\Factories\MailServiceFactory;
use Relaticle\EmailIntegration\Support\PublicSuffixList;

final class EmailIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CalendarServiceFactoryInterface::class, CalendarServiceFactory::class);
        $this->app->bind(MailServiceFactoryInterface::class, MailServiceFactory::class);

        // Parse the Public Suffix List once per process.
        $this->app->singleton(PublicSuffixList::class);
    }

    public function boot(): void
    {
        if (! Feature::active(EmailIntegration::class)) {
            return;
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'email-integration');

        // Dedicated Google OAuth driver for email/calendar connect, backed by the
        // `services.gmail` client + redirect (separate from social login's `services.google`).
        // Keeps the consent, token exchange and refresh all on the same OAuth client.
        Socialite::extend('gmail', fn (): AbstractProvider => Socialite::buildProvider(GoogleProvider::class, (array) config('services.gmail')));

        // Email is already observed via #[ObservedBy(EmailObserver::class)] on the model.
        // Registering it again here fires every listener twice (double metric increments,
        // double auto-create) for any create path where participants exist at create time.
        //
        // Incremental email + calendar sync are scheduled in bootstrap/app.php (all
        // scheduled work lives there); do not re-register them here.

        Route::middleware('web')
            ->group(function (): void {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });

        Livewire::component('email-integration.composer', EmailComposer::class);

        // The feature flag is already checked above (config-based, stable for the
        // request), so the closure only needs to gate on per-request context: the
        // active panel, an authenticated user, and a resolved tenant.
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            function (): string {
                if (filament()->getId() !== 'app' || ! auth()->check() || ! filament()->getTenant() instanceof Model) {
                    return '';
                }

                return Blade::render('@livewire(\'email-integration.composer\')');
            },
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                BackfillEmailThreadsCommand::class,
                DispatchOutboxCommand::class,
            ]);
        }
    }
}
