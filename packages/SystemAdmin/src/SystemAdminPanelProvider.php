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
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Relaticle\Ink\InkPlugin;
use Relaticle\Ink\Models\Category;
use Relaticle\Ink\Models\Post;
use Relaticle\SystemAdmin\Filament\Pages\Dashboard;
use Relaticle\SystemAdmin\Http\Middleware\DenySearchIndexing;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Relaticle\SystemAdmin\Policies\CategoryPolicy;
use Relaticle\SystemAdmin\Policies\PostPolicy;

final class SystemAdminPanelProvider extends PanelProvider
{
    /**
     * Single source of truth for which ink models the blog policies below cover
     * — both Gate::policy() registration and the Gate::before() guard read from
     * this array, so the two can never drift apart.
     *
     * @var array<class-string, class-string>
     */
    private const array BLOG_MODEL_POLICIES = [
        Post::class => PostPolicy::class,
        Category::class => CategoryPolicy::class,
    ];

    public function boot(): void
    {
        // Blog MCP requests are not Filament panel requests, so the panel-scoped
        // policy discovery in AppServiceProvider never sees them.
        foreach (self::BLOG_MODEL_POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // PostPolicy/CategoryPolicy type-hint SystemAdministrator, and Gate never
        // checks a policy method's parameter type before calling it — a caller of
        // any other type (e.g. a customer's User model, or an MCP token minted for
        // one) would hit an uncaught TypeError instead of a clean denial now that
        // the policies above resolve globally. Intercept before Gate reaches them.
        Gate::before(function (Authenticatable $user, string $ability, array $arguments = []): ?bool {
            $target = $arguments[0] ?? null;
            $modelClass = is_string($target) ? $target : ($target instanceof Model ? $target::class : null);

            if ($modelClass !== null && array_key_exists($modelClass, self::BLOG_MODEL_POLICIES) && ! $user instanceof SystemAdministrator) {
                return false;
            }

            return null;
        });
    }

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
            ->emailVerification(isRequired: config('app.require_email_verification'))
            ->authGuard('sysadmin')
            ->authPasswordBroker('system_administrators')
            ->strictAuthorization()
            ->spa()
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->brandName('Relaticle System Admin')
            ->discoverResources(in: base_path('packages/SystemAdmin/src/Filament/Resources'), for: 'Relaticle\\SystemAdmin\\Filament\\Resources')
            ->discoverPages(in: base_path('packages/SystemAdmin/src/Filament/Pages'), for: 'Relaticle\\SystemAdmin\\Filament\\Pages')
            ->discoverWidgets(in: base_path('packages/SystemAdmin/src/Filament/Widgets'), for: 'Relaticle\\SystemAdmin\\Filament\\Widgets')
            ->navigationGroups([
                NavigationGroup::make()
                    ->label('Dashboards'),
                NavigationGroup::make()
                    ->label('User Management'),
                NavigationGroup::make()
                    ->label('AI'),
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
            /**
             * The blog is Relaticle's own marketing content, not tenant data, so it is
             * administered here rather than in the customer panel. This panel has no
             * tenancy, which means the Ink resources need no scopeToTenant() opt-out —
             * that call writes a static shared by every Filament resource and would
             * disable tenant scoping app-wide.
             *
             * Staff always have the authoring surface; the Blog feature flag gates the
             * PUBLIC side (routes, marketing nav, sitemap), so posts can be written
             * before launch. Post::getUrl() already falls back to '#' when the public
             * routes are not registered.
             */
            ->plugins([
                InkPlugin::make(),
            ])
            ->databaseNotifications()
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                DenySearchIndexing::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
                fn (): string => Blade::render('@env(\'local\')<x-login-link email="sysadmin@relaticle.com" guard="sysadmin" user-model="'.SystemAdministrator::class.'" redirect-url="'.$this->sysadminHomeUrl().'" />@endenv'),
            )
            ->viteTheme('resources/css/filament/admin/theme.css');
    }

    private function sysadminHomeUrl(): string
    {
        if ($domain = config('app.sysadmin_domain')) {
            return 'https://'.$domain.'/';
        }

        return url('/'.config('app.sysadmin_path', 'sysadmin'));
    }
}
