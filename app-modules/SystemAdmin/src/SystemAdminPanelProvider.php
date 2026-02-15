<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin;

use Exception;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Relaticle\SystemAdmin\Filament\Pages\Dashboard;

final class SystemAdminPanelProvider extends PanelProvider
{
    /**
     * @throws Exception
     */
    public function panel(Panel $panel): Panel
    {
        $panel = $panel->id('sysadmin');

        // Configure domain or path based on environment
        if ($domain = config('app.sysadmin_domain')) {
            $panel->domain($domain);
        } else {
            $panel->path(config('app.sysadmin_path', 'sysadmin'));
        }

        return $panel
            ->login()
            ->emailVerification()
            ->authGuard('sysadmin')
            ->authPasswordBroker('system_administrators')
            ->strictAuthorization()
            ->spa()
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->brandName('Relaticle System Admin')
            ->discoverResources(in: base_path('app-modules/SystemAdmin/src/Filament/Resources'), for: 'Relaticle\\SystemAdmin\\Filament\\Resources')
            ->discoverPages(in: base_path('app-modules/SystemAdmin/src/Filament/Pages'), for: 'Relaticle\\SystemAdmin\\Filament\\Pages')
            ->discoverWidgets(in: base_path('app-modules/SystemAdmin/src/Filament/Widgets'), for: 'Relaticle\\SystemAdmin\\Filament\\Widgets')
            ->navigationGroups([
                NavigationGroup::make()
                    ->label('User Management'),
                NavigationGroup::make()
                    ->label('CRM'),
                NavigationGroup::make()
                    ->label('Task Management'),
                NavigationGroup::make()
                    ->label('Content'),
            ])
            ->globalSearch()
            ->darkMode()
            ->maxContentWidth('full')
            ->sidebarCollapsibleOnDesktop()
            ->pages([
                Dashboard::class,
            ])
            ->widgets([])
            ->plugins([])
            ->databaseNotifications()
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->viteTheme('resources/css/filament/admin/theme.css');
    }
}
