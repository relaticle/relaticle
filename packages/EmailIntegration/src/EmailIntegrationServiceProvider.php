<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration;

use App\Features\EmailIntegration;
use App\Filament\Pages\Dashboard;
use App\Models\CustomFieldValue;
use App\Models\User;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\GoogleProvider;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Console\Commands\BackfillEmailThreadsCommand;
use Relaticle\EmailIntegration\Console\Commands\DispatchOutboxCommand;
use Relaticle\EmailIntegration\Console\Commands\IncrementalCalendarSyncCommand;
use Relaticle\EmailIntegration\Console\Commands\IncrementalEmailSyncCommand;
use Relaticle\EmailIntegration\Console\Commands\RenewCalendarPushChannelsCommand;
use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource\Pages\ManageEmailTemplates;
use Relaticle\EmailIntegration\Livewire\AccessRequestsTable;
use Relaticle\EmailIntegration\Livewire\DraftsTable;
use Relaticle\EmailIntegration\Livewire\EmailAccessNotificationHandler;
use Relaticle\EmailIntegration\Livewire\EmailComposer;
use Relaticle\EmailIntegration\Livewire\EmailVisibilityTable;
use Relaticle\EmailIntegration\Livewire\MeetingsHomeWidget;
use Relaticle\EmailIntegration\Livewire\OutboxTable;
use Relaticle\EmailIntegration\Livewire\TemplatesTable;
use Relaticle\EmailIntegration\Livewire\UserEmailPrivacySettings;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailTemplate;
use Relaticle\EmailIntegration\Models\EmailThread;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;
use Relaticle\EmailIntegration\Services\Factories\CalendarServiceFactory;
use Relaticle\EmailIntegration\Services\Factories\MailServiceFactory;
use Relaticle\EmailIntegration\Services\MailboxDisplayNameDirectory;
use Relaticle\EmailIntegration\Services\TeamMemberDirectory;
use Relaticle\EmailIntegration\Support\ComposerPageTo;
use Relaticle\EmailIntegration\Support\PublicSuffixList;
use Relaticle\EmailIntegration\Support\QueueRecordHistoryRelink;
use Relaticle\ImportWizard\Events\CustomFieldValuesImported;
use SocialiteProviders\Azure\AzureExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

final class EmailIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/email-integration.php', 'email-integration');

        $this->app->bind(CalendarServiceFactoryInterface::class, CalendarServiceFactory::class);
        $this->app->bind(MailServiceFactoryInterface::class, MailServiceFactory::class);

        // Parse the Public Suffix List once per process.
        $this->app->singleton(PublicSuffixList::class);
        $this->app->scoped(TeamMemberDirectory::class);
        $this->app->scoped(MailboxDisplayNameDirectory::class);
        $this->app->scoped(EmailVisibilityService::class);
        $this->app->scoped(QueueRecordHistoryRelink::class);

        // Not gated by the feature flag: these are inert while the feature is off, and
        // static analysis (which runs with it off) can only resolve
        // `email-integration::` view strings when they are always registered.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'email-integration');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'email-integration');
    }

    public function boot(): void
    {
        Relation::morphMap([
            'email' => Email::class,
            'connected_account' => ConnectedAccount::class,
            'email_thread' => EmailThread::class,
            'email_access_request' => EmailAccessRequest::class,
            'meeting' => Meeting::class,
        ]);

        // Workspace rules outlive their creator; a personal template leaves with them,
        // even while the feature is off.
        User::deleting(function (User $user): void {
            EmailTemplate::query()
                ->withoutGlobalScopes()
                ->where('created_by', $user->getKey())
                ->where('is_shared', false)
                ->forceDelete();
        });

        if (! self::enabled()) {
            return;
        }

        CustomFieldValue::saved(fn (CustomFieldValue $value) => resolve(QueueRecordHistoryRelink::class)->forIdentityValue($value));
        Event::listen(CustomFieldValuesImported::class, fn (CustomFieldValuesImported $event) => resolve(QueueRecordHistoryRelink::class)->forImportedValues($event));

        Event::listen(SocialiteWasCalled::class, [AzureExtendSocialite::class, 'handle']);

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

        // The templates resource has no page view of its own, so its tabs and header
        // (see HasEmailSettingsHeader) are rendered into the content column from here.
        FilamentView::registerRenderHook(
            Dashboard::AFTER_COMPOSER_RENDER_HOOK,
            fn (): string => Feature::active(EmailIntegration::class)
                ? Blade::render("@livewire('email-integration.meetings-home-widget')")
                : '',
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_HEADER_WIDGETS_BEFORE,
            fn (): string => view('email-integration::components.settings-tabs')->render()
                .view('email-integration::components.settings-header')->render(),
            scopes: ManageEmailTemplates::class,
        );

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        Livewire::component('email-integration.composer', EmailComposer::class);
        Livewire::component('email-integration.email-visibility-table', EmailVisibilityTable::class);
        Livewire::component('email-integration.drafts-table', DraftsTable::class);
        Livewire::component('email-integration.access-requests-table', AccessRequestsTable::class);
        Livewire::component(EmailAccessNotificationHandler::LIVEWIRE_ALIAS, EmailAccessNotificationHandler::class);
        Livewire::component('email-integration.outbox-table', OutboxTable::class);
        Livewire::component('email-integration.templates-table', TemplatesTable::class);
        Livewire::component('email-integration.meetings-home-widget', MeetingsHomeWidget::class);
        Livewire::component('email-integration.user-email-privacy-settings', UserEmailPrivacySettings::class);

        // The feature flag is already checked above (config-based, stable for the
        // request), so the closure only needs to gate on per-request context: the
        // active panel, an authenticated user, and a resolved tenant.
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            function (): string {
                if (filament()->getId() !== 'app' || ! auth()->check() || ! filament()->getTenant() instanceof Model) {
                    return '';
                }

                $pageTo = ComposerPageTo::email();
                $pageRecordId = ComposerPageTo::recordId();

                return Blade::render('@livewire(\'email-integration.composer\', [\'pageTo\' => $pageTo, \'pageRecordType\' => $pageRecordType, \'pageRecordId\' => $pageRecordId], key($composerKey))', [
                    'pageTo' => $pageTo,
                    'pageRecordType' => ComposerPageTo::recordType(),
                    'pageRecordId' => $pageRecordId,
                    'composerKey' => 'email-composer-'.($pageRecordId ?? '').'-'.($pageTo ?? ''),
                ]).Blade::render('@livewire(\''.EmailAccessNotificationHandler::LIVEWIRE_ALIAS.'\')');
            },
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                BackfillEmailThreadsCommand::class,
                DispatchOutboxCommand::class,
                IncrementalCalendarSyncCommand::class,
                IncrementalEmailSyncCommand::class,
                RenewCalendarPushChannelsCommand::class,
            ]);
        }
    }

    public static function enabled(): bool
    {
        return Feature::for(null)->active(EmailIntegration::class);
    }
}
