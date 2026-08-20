<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Enums\SupportFormType;
use App\Features\Billing as BillingFeature;
use App\Features\SocialAuth;
use App\Features\SupportMenu;
use App\Filament\Clusters\Settings;
use App\Filament\Pages\AccessTokens;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\Register;
use App\Filament\Pages\Billing;
use App\Filament\Pages\CreateTeam;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\EditTeam;
use App\Filament\Pages\Team\CustomFields;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\TaskResource;
use App\Http\Controllers\SyncUserTimezoneController;
use App\Http\Middleware\ApplyTenantScopes;
use App\Http\Middleware\CheckScheduledDeletion;
use App\Http\Middleware\DenySearchIndexing;
use App\Http\Middleware\EnsureHostedWorkspaceAccess;
use App\Listeners\SwitchTeam;
use App\Livewire\App\AppDatabaseNotifications;
use App\Livewire\App\AppSidebar;
use App\Livewire\App\Profile\ScheduledDeletionInterstitial;
use App\Models\Team;
use App\Models\User;
use App\Support\SupportForms;
use Asmit\ResizedColumn\ResizedColumnPlugin;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Enums\DatabaseNotificationsPosition;
use Filament\Enums\GlobalSearchPosition;
use Filament\Events\TenantSet;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Platform;
use Filament\Support\Enums\Size;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Js;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Jetstream\Features;
use Laravel\Pennant\Feature;
use Relaticle\CustomFields\CustomFieldsPlugin;
use Relaticle\ImportWizard\Filament\Pages\ImportHistory;

final class AppPanelProvider extends PanelProvider
{
    /**
     * Datetime format for this panel, and the only place it is decided. Columns and
     * entries call `->dateTime()` with no argument so they resolve to this; a resource
     * that passes its own literal instead is drift, not a local preference.
     *
     * No seconds. Nobody reading a CRM list cares which second a note was written, and
     * DateTimePicker offers minutes anyway, so keeping them made every value read back
     * one field longer than it was entered. Sysadmin keeps its seconds on purpose: that
     * panel exists to line rows up against Horizon and server logs.
     *
     * No zone suffix either, unlike sysadmin: every timestamp here is already in the
     * reader's own zone, so there is nothing for them to correlate against.
     */
    private const string DATE_TIME_FORMAT = 'M j, Y H:i';

    /**
     * Perform post-registration booting of components.
     */
    public function boot(): void
    {
        /**
         * Listen and switch team if tenant was changed
         */
        Event::listen(
            TenantSet::class,
            SwitchTeam::class,
        );

        Action::configureUsing(fn (Action $action): Action => $action->size(Size::Small)->iconPosition('before'));
        DeleteAction::configureUsing(fn (DeleteAction $action): DeleteAction => $action->label(__('filament/panel.actions.delete_record')));
        Section::configureUsing(fn (Section $section): Section => $section->compact());

        // Filament defaults searchDebounce to 1000ms, which reads as a hung field.
        // configureUsing is global across panels, so this is guarded like the callbacks below.
        Select::configureUsing(fn (Select $select): Select => $this->isCurrentPanel()
            ? $select->searchDebounce(250)
            : $select);

        // Table and Schema configuration is global, so both callbacks have to check
        // which panel is actually serving the request before they touch the format.
        Table::configureUsing(fn (Table $table): Table => $this->isCurrentPanel()
            ? $table->defaultDateTimeDisplayFormat(self::DATE_TIME_FORMAT)
            : $table);

        Schema::configureUsing(fn (Schema $schema): Schema => $this->isCurrentPanel()
            ? $schema->defaultDateTimeDisplayFormat(self::DATE_TIME_FORMAT)
            : $schema);

        /**
         * Filament's calendar decides which cell to circle as "today" with
         * `dayjs.tz.guess()`, the device zone, and takes no server timezone. A user in
         * Tokyo on a laptop still set elsewhere therefore saw the app say one date
         * everywhere and the picker circle another, and clicking the circled cell books
         * the wrong day.
         *
         * Only the highlight is wrong: the picker emits a naive wall-clock string that
         * the server reads back in the panel zone, so typed values were always stored
         * correctly. Overriding `dayIsToday` on the component's own element keeps the fix
         * to that single comparison rather than forking the vendor component.
         *
         * The zone is baked in, the date is not: `Intl` recomputes it per call, so a tab
         * left open across midnight still circles the right cell.
         */
        DateTimePicker::configureUsing(fn (DateTimePicker $picker): DateTimePicker => $this->isCurrentPanel()
            ? $picker->extraAlpineAttributes(['x-init' => $this->todayInViewerZoneExpression()], merge: true)
            : $picker);
    }

    /**
     * `en-CA` is chosen for its output shape, not its language: it is the locale whose
     * short date is already `YYYY-MM-DD`, so the parts split cleanly without parsing a
     * localised order.
     */
    private function todayInViewerZoneExpression(): string
    {
        $timezone = Js::from(FilamentTimezone::get());

        return <<<JS
            dayIsToday = (day) => {
                const [year, month, date] = new Intl.DateTimeFormat('en-CA', { timeZone: {$timezone} })
                    .format(new Date())
                    .split('-')
                    .map(Number);

                return day === date && focusedMonth === month - 1 && focusedYear === year;
            }
            JS;
    }

    private function isCurrentPanel(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'app';
    }

    /**
     * Gates the browser timezone detection script below, which posts to an app-panel
     * route. Panel rendering itself no longer reads this — that resolution is global
     * and lives in AppServiceProvider::configureFilament(), because TimezoneManager
     * holds a single slot and a second writer here would silently win on boot order.
     *
     * The web guard is shared with the sysadmin panel's SystemAdministrator, whose
     * zone is chosen on its own profile page and never detected — narrow to the
     * customer model rather than assuming.
     */
    private function signedInUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /**
     * Configure the Filament admin panel.
     *
     * @throws Exception
     */
    public function panel(Panel $panel): Panel
    {
        $panel
            ->default()
            ->id('app');

        if ($domain = config('app.app_panel_domain')) {
            $panel->domain($domain);
        } else {
            $panel->path(config('app.app_panel_path', 'app'));
        }

        $panel
            ->homeUrl(fn (): string => Dashboard::getUrl())
            ->brandName('Relaticle')
            ->brandLogo(fn (): View|Factory => Auth::user()?->hasVerifiedEmail()
                ? view('filament.app.logo-empty')
                : view('filament.app.logo'))
            ->brandLogoHeight('2.6rem')
            ->login(Login::class)
            ->registration(Register::class)
            ->authGuard('web')
            ->authPasswordBroker('users')
            ->passwordReset()
            ->emailVerification(isRequired: config('app.require_email_verification'))
            ->emailChangeVerification()
            ->strictAuthorization()
            /**
             * Search and notifications share one row at the top of the sidebar,
             * above the navigation. Both are placed in the sidebar here; AppSidebar
             * is what puts them on the same row rather than in separate slots.
             */
            ->sidebarLivewireComponent(AppSidebar::class)
            ->globalSearch(position: GlobalSearchPosition::Sidebar)
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            /** Filament's own suffix helper renders "⌘+K"; the key cap reads better without the plus. */
            ->globalSearchFieldSuffix(fn (): string => Platform::detect() === Platform::Mac ? '⌘K' : 'Ctrl K')
            ->databaseNotifications(
                livewireComponent: AppDatabaseNotifications::class,
                position: DatabaseNotificationsPosition::Sidebar,
            )
            ->colors([
                'primary' => [
                    50 => 'oklch(0.969 0.016 293.756)',
                    100 => 'oklch(0.943 0.028 294.588)',
                    200 => 'oklch(0.894 0.055 293.283)',
                    300 => 'oklch(0.811 0.101 293.571)',
                    400 => 'oklch(0.709 0.159 293.541)',
                    500 => 'oklch(0.606 0.219 292.717)',
                    600 => 'oklch(0.541 0.247 293.009)',
                    700 => 'oklch(0.491 0.241 292.581)',
                    800 => 'oklch(0.432 0.211 292.759)',
                    900 => 'oklch(0.380 0.178 293.745)',
                    950 => 'oklch(0.283 0.135 291.089)',
                    'DEFAULT' => 'oklch(0.541 0.247 293.009)',
                ],
            ])
            ->viteTheme('resources/css/filament/app/theme.css')
            ->userMenuItems([
                Action::make('settings')
                    ->label(__('filament/panel.user_menu.settings'))
                    ->icon('heroicon-m-cog-6-tooth')
                    ->url(fn (): string => $this->shouldRegisterMenuItem()
                        ? url(Settings::getUrl())
                        : url($panel->getPath())),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverPages(in: base_path('packages/ImportWizard/src/Filament/Pages'), for: 'Relaticle\\ImportWizard\\Filament\\Pages')
            ->discoverPages(in: base_path('packages/Chat/src/Filament/Pages'), for: 'Relaticle\\Chat\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\\Filament\\Clusters')
            ->readOnlyRelationManagersOnResourceViewPagesByDefault(false)
            ->spa()
            ->routes(function (): void {
                Route::get('/scheduled-deletion', ScheduledDeletionInterstitial::class)
                    ->middleware('auth')
                    ->name('scheduled-deletion');
                Route::post('/timezone', SyncUserTimezoneController::class)
                    ->middleware('auth')
                    ->name('timezone.sync');

                Route::get('/{tenant}/tasks-board', fn (string $tenant) => redirect()->to(TaskResource::getUrl('board', ['tenant' => $tenant]), status: 301))
                    ->name('tasks-board.redirect');
                Route::get('/{tenant}/opportunities-board', fn (string $tenant) => redirect()->to(OpportunityResource::getUrl('board', ['tenant' => $tenant]), status: 301))
                    ->name('opportunities-board.redirect');
            })
            ->breadcrumbs(false)
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make()
                    ->label(__('filament/panel.navigation_groups.tasks'))
                    ->icon('heroicon-o-shopping-cart'),
            ])
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
            ->authGuard('web')
            ->authPasswordBroker('users')
            ->authMiddleware([
                Authenticate::class,
                CheckScheduledDeletion::class,
            ])
            ->tenantMiddleware(
                [
                    EnsureHostedWorkspaceAccess::class,
                    ApplyTenantScopes::class,
                ],
                isPersistent: true
            )
            ->plugins([
                CustomFieldsPlugin::make()
                    ->authorize(fn () => Gate::check('update', Filament::getTenant()))
                    ->managementPage(CustomFields::class),
                ResizedColumnPlugin::make(),
            ])
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
                fn (): string => Blade::render('@env(\'local\')<x-login-link email="manuk.minasyan1@gmail.com" redirect-url="'.url()->getAppUrl().'" />@endenv'),
            );

        if (Feature::active(SocialAuth::class)) {
            $panel
                ->renderHook(
                    PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
                    fn (): View|Factory => view('filament.auth.social_login_buttons')
                )
                ->renderHook(
                    PanelsRenderHook::AUTH_REGISTER_FORM_BEFORE,
                    fn (): View|Factory => view('filament.auth.social_login_buttons')
                );
        }

        $panel
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): View|Factory => view('filament.app.analytics')
            )
            /**
             * Only rendered for a signed-in user who has no timezone yet. The guest
             * check matters: the panel's own login and registration pages render this
             * hook too, and there the endpoint could only ever answer 401.
             */
            ->renderHook(
                PanelsRenderHook::BODY_END,
                function (): View|Factory|string {
                    $user = $this->signedInUser();

                    if (! $user instanceof User || $user->timezone !== null) {
                        return '';
                    }

                    return view('filament.app.detect-timezone', ['endpoint' => route('filament.app.timezone.sync')]);
                },
            );

        if (Features::hasApiFeatures()) {
            $panel->userMenuItems([
                Action::make('api_tokens')
                    ->label(__('access-tokens.user_menu'))
                    ->icon('heroicon-o-key')
                    ->url(fn (): string => $this->shouldRegisterMenuItem()
                        ? url(AccessTokens::getUrl())
                        : url($panel->getPath())),
            ]);
        }

        $panel->userMenuItems($this->supportMenuItems());

        $panel
            ->tenant(Team::class, slugAttribute: 'slug', ownershipRelationship: 'team')
            ->tenantRegistration(CreateTeam::class)
            ->tenantProfile(EditTeam::class)
            ->tenantMenuItems([
                Action::make('import_history')
                    ->label(__('filament/panel.tenant_menu.import_history'))
                    ->icon(Heroicon::OutlinedClock)
                    ->url(fn (): string => ImportHistory::getUrl()),
                Action::make('billing')
                    ->label(__('billing.title'))
                    ->icon(Heroicon::OutlinedCreditCard)
                    ->url(fn (): string => Billing::getUrl())
                    ->visible(fn (): bool => Feature::active(BillingFeature::class)),
            ]);

        return $panel;
    }

    /**
     * Support entries for the user menu — every support form type that resolves
     * to a URL, opening its Maxforms form in a new tab. Empty when nothing is
     * configured, so the user menu simply shows no support entries.
     *
     * Everything is resolved lazily: the URL carries the signed-in user and
     * workspace as prefill, and the feature flag is only decided per request —
     * neither is known while the panel is being configured.
     *
     * @return list<Action>
     */
    private function supportMenuItems(): array
    {
        return array_map(
            fn (SupportFormType $type): Action => Action::make("support_{$type->value}")
                ->label(fn (): string => $type->label())
                ->icon($type->icon())
                ->url(fn (): ?string => $this->supportFormUrl($type))
                ->visible(fn (): bool => $this->supportFormUrl($type) !== null)
                ->openUrlInNewTab(),
            SupportFormType::cases(),
        );
    }

    private function supportFormUrl(SupportFormType $type): ?string
    {
        if (! Feature::active(SupportMenu::class)) {
            return null;
        }

        return resolve(SupportForms::class)->publicUrl($type, $this->supportPrefill());
    }

    /**
     * Context carried into the support form as prefilled hidden fields. Blank
     * values are stripped at the boundary in SupportForms::publicUrl().
     *
     * @return array<string, string>
     */
    private function supportPrefill(): array
    {
        $user = Auth::user();

        return [
            'user_email' => $user->email,
            'user_name' => $user->name,
            'workspace_id' => (string) (Filament::getTenant()?->getKey() ?? ''),
            'source_url' => url()->current(),
            'app_version' => (string) config('app.version', ''),
        ];
    }

    public function shouldRegisterMenuItem(): bool
    {
        $hasVerifiedEmail = Auth::user()?->hasVerifiedEmail();

        return Filament::hasTenancy()
            ? $hasVerifiedEmail && Filament::getTenant()
            : $hasVerifiedEmail;
    }
}
