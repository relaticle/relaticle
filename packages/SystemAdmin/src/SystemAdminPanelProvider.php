<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin;

use Exception;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\Column;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\ComponentContext;
use Relaticle\Ink\InkPlugin;
use Relaticle\Ink\Models\Category;
use Relaticle\Ink\Models\Post;
use Relaticle\SystemAdmin\Auth\RequiredAppAuthentication;
use Relaticle\SystemAdmin\Filament\Pages\Auth\EditProfile;
use Relaticle\SystemAdmin\Filament\Pages\Dashboard;
use Relaticle\SystemAdmin\Http\Controllers\PasskeyLoginController;
use Relaticle\SystemAdmin\Http\Controllers\PasskeyRegistrationController;
use Relaticle\SystemAdmin\Http\Middleware\DenySearchIndexing;
use Relaticle\SystemAdmin\Http\Middleware\IsolateAuthenticationSession;
use Relaticle\SystemAdmin\Http\Middleware\RequireSecondFactor;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Relaticle\SystemAdmin\Models\SystemAdministratorPasskey;
use Relaticle\SystemAdmin\Policies\CategoryPolicy;
use Relaticle\SystemAdmin\Policies\PostPolicy;

final class SystemAdminPanelProvider extends PanelProvider
{
    /**
     * Single source of truth for which ink models the blog policies below cover
     * Both Gate::policy() registration and the Gate::before() guard read from
     * this array, so the two can never drift apart.
     *
     * @var array<class-string, class-string>
     */
    private const array BLOG_MODEL_POLICIES = [
        Post::class => PostPolicy::class,
        Category::class => CategoryPolicy::class,
    ];

    /**
     * Datetime format for this panel. The zone is opt-in per administrator on the
     * profile page and defaults to UTC, because this panel is used to correlate
     * incidents against Horizon, Flare and server logs, which are all UTC.
     *
     * `T` prints the zone the value is actually rendered in, so a timestamp is never
     * ambiguous: an administrator who has opted out of UTC can still see they did,
     * and two administrators in different zones cannot read the same row as the same
     * numbers. This is why the suffix must stay. Dropping it, not the conversion
     * itself, is what would break incident correlation.
     */
    private const string DATE_TIME_FORMAT = 'M j, Y H:i:s T';

    public function register(): void
    {
        parent::register();

        // Lazy mount-parameter snapshots bypass component lifecycle hooks.
        Livewire::listen('dehydrate', function (Component $component, ComponentContext $context): void {
            $context->addMemo('authContext', IsolateAuthenticationSession::context(request()));
        });

        Livewire::listen('snapshot-verified', function (array $snapshot): void {
            abort_unless(
                ($snapshot['memo']['authContext'] ?? null) === IsolateAuthenticationSession::context(request()),
                419,
            );
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'system-admin');

        // Blog MCP requests are not Filament panel requests, so the panel-scoped
        // policy discovery in AppServiceProvider never sees them.
        foreach (self::BLOG_MODEL_POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        Column::configureUsing(fn (Column $column): Column => $this->isCurrentPanel()
            ? $column->toggleable()
            : $column);

        // Filament configuration is global, so panel-specific callbacks must guard
        // against changing customer-facing components.
        Table::configureUsing(fn (Table $table): Table => $this->isCurrentPanel()
            ? $table
                ->defaultDateTimeDisplayFormat(self::DATE_TIME_FORMAT)
                ->reorderableColumns()
            : $table);

        Schema::configureUsing(fn (Schema $schema): Schema => $this->isCurrentPanel()
            ? $schema->defaultDateTimeDisplayFormat(self::DATE_TIME_FORMAT)
            : $schema);

        // Every policy in this package answers for staff only, but Gate never checks a
        // policy method's parameter type, so a caller of any other type (a customer's
        // User, or an MCP token minted for one) is answered by whatever the method
        // returns. Deny those before Gate reaches the policy: while this panel is
        // current for any of them, and always for the blog models, which resolve
        // globally and so are reachable with no panel at all.
        Gate::before(function (Authenticatable $user, string $ability, array $arguments = []): ?bool {
            if ($user instanceof SystemAdministrator) {
                return null;
            }

            if ($this->isCurrentPanel()) {
                return false;
            }

            $target = $arguments[0] ?? null;
            $modelClass = is_string($target) ? $target : ($target instanceof Model ? $target::class : null);

            return $modelClass !== null && array_key_exists($modelClass, self::BLOG_MODEL_POLICIES)
                ? false
                : null;
        });
    }

    private function isCurrentPanel(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'sysadmin';
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
            ->profile(EditProfile::class)
            ->emailVerification(isRequired: config('app.require_email_verification'))
            // A SuperAdministrator password reaches every customer's data and, through
            // impersonation, a signed-in session as any of them.
            ->multiFactorAuthentication(
                RequiredAppAuthentication::make()->recoverable(),
                isRequired: true,
            )
            ->multiFactorAuthenticationRequiredMiddlewareName(RequireSecondFactor::class)
            ->authGuard('sysadmin')
            ->authPasswordBroker('system_administrators')
            ->strictAuthorization()
            ->spa()
            ->colors([
                'primary' => Color::Indigo,
                'purple' => Color::Purple,
                'indigo' => Color::Indigo,
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
             * tenancy, which means the Ink resources need no scopeToTenant() opt-out.
             * That call writes a static shared by every Filament resource and would
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
                'auth.context',
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
                RequireSecondFactor::class,
            ], isPersistent: true)
            ->routes(function () use ($panel): void {
                Route::prefix('passkeys')
                    ->name('passkeys.')
                    ->group(function () use ($panel): void {
                        Route::get('/login/options', [PasskeyLoginController::class, 'index'])
                            ->middleware(['guest:sysadmin', 'throttle:passkeys'])
                            ->name('login-options');
                        Route::post('/login', [PasskeyLoginController::class, 'store'])
                            ->middleware(['guest:sysadmin', 'throttle:passkeys'])
                            ->name('login');

                        // Behind the panel's own stack, so registering a passkey is
                        // reachable only once the required second factor is enrolled.
                        Route::get('/options', [PasskeyRegistrationController::class, 'index'])
                            ->middleware([...$panel->getAuthMiddleware(), 'throttle:passkeys'])
                            ->name('options');
                        Route::post('/', [PasskeyRegistrationController::class, 'store'])
                            ->middleware([...$panel->getAuthMiddleware(), 'throttle:passkeys'])
                            ->name('store');
                    });
            })
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): View|string => SystemAdministratorPasskey::hasDedicatedRelyingParty()
                    ? view('system-admin::auth.passkey-login')
                    : '',
            )
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
