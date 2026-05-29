<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use App\Models\Team;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Size;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;
use Relaticle\EmailIntegration\Actions\DisconnectConnectedAccountAction;
use Relaticle\EmailIntegration\Actions\UpdateConnectedAccountSettingsAction;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Jobs\IncrementalCalendarSyncJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final class EmailAccountsPage extends Page
{
    protected string $view = 'email-integration::filament.pages.email-accounts';

    protected static ?string $slug = 'settings/email-accounts';

    protected static ?string $title = 'Accounts';

    protected static ?int $navigationSort = 10;

    protected static string|\UnitEnum|null $navigationGroup = null;

    public static function getNavigationGroup(): string
    {
        return __('filament/navigation.groups.emails');
    }

    /**
     * @var Collection<int, ConnectedAccount>
     */
    public Collection $connectedAccounts;

    public function mount(): void
    {
        $this->sendSuccessNotification();
        $this->sendErrorNotification();
        $this->connectedAccounts = $this->getAccounts();
    }

    /**
     * @return Collection<int, ConnectedAccount>
     */
    private function getAccounts(): Collection
    {
        return $this->ownedAccountsQuery()->get();
    }

    public function connectGmailAction(): Action
    {
        return Action::make('connectGmail')
            ->label(__('filament/pages/email-accounts.actions.connect_gmail'))
            ->icon('heroicon-o-plus')
            ->size(Size::Small)
            ->url(fn (): string => route('email-accounts.redirect', ['provider' => 'gmail']), true);
    }

    public function connectAzureAction(): Action
    {
        return Action::make('connectAzure')
            ->label(__('filament/pages/email-accounts.actions.connect_azure'))
            ->icon('heroicon-o-plus')
            ->color('info')
            ->size(Size::Small)
            ->url(fn (): string => route('email-accounts.redirect', ['provider' => 'azure']), true);
    }

    public function reAuthAction(): Action
    {
        return Action::make('reAuth')
            ->label(__('filament/pages/email-accounts.actions.re_auth'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->size(Size::Small)
            ->url(fn (array $arguments): string => route('email-accounts.redirect', [
                'provider' => $this->findAccount($arguments)?->provider->value,
            ]), true);
    }

    public function editSettingsAction(): Action
    {
        return Action::make('editSettings')
            ->label(__('filament/pages/email-accounts.actions.edit_settings'))
            ->icon('heroicon-o-cog-6-tooth')
            ->color('gray')
            ->size(Size::Small)
            ->fillForm(function (array $arguments): array {
                $account = $this->findOwnedAccountOrFail($arguments);

                return [
                    'sync_inbox' => $account->sync_inbox,
                    'sync_sent' => $account->sync_sent,
                    'contact_creation_mode' => $account->contact_creation_mode->value,
                    'auto_create_companies' => $account->auto_create_companies,
                    'hourly_send_limit' => $account->hourly_send_limit,
                    'daily_send_limit' => $account->daily_send_limit,
                ];
            })
            ->schema([
                Grid::make(2)
                    ->schema([
                        Toggle::make('sync_inbox')
                            ->label(__('filament/pages/email-accounts.settings.sync_inbox.label'))
                            ->helperText(__('filament/pages/email-accounts.settings.sync_inbox.helper_text')),
                        Toggle::make('sync_sent')
                            ->label(__('filament/pages/email-accounts.settings.sync_sent.label'))
                            ->helperText(__('filament/pages/email-accounts.settings.sync_sent.helper_text')),
                    ]),
                Select::make('contact_creation_mode')
                    ->label(__('filament/pages/email-accounts.settings.contact_creation_mode.label'))
                    ->options(ContactCreationMode::class)
                    ->required()
                    ->helperText(__('filament/pages/email-accounts.settings.contact_creation_mode.helper_text')),
                Toggle::make('auto_create_companies')
                    ->label(__('filament/pages/email-accounts.settings.auto_create_companies.label'))
                    ->helperText(__('filament/pages/email-accounts.settings.auto_create_companies.helper_text')),
                Grid::make(2)
                    ->schema([
                        TextInput::make('hourly_send_limit')
                            ->label(__('filament/pages/email-accounts.settings.hourly_send_limit.label'))
                            ->numeric()
                            ->minValue(1)
                            ->placeholder(__('filament/pages/email-accounts.settings.hourly_send_limit.placeholder', ['default' => Config::integer('email-integration.outbox.defaults.hourly_send_limit')]))
                            ->helperText(__('filament/pages/email-accounts.settings.hourly_send_limit.helper_text')),
                        TextInput::make('daily_send_limit')
                            ->label(__('filament/pages/email-accounts.settings.daily_send_limit.label'))
                            ->numeric()
                            ->minValue(1)
                            ->placeholder(__('filament/pages/email-accounts.settings.daily_send_limit.placeholder', ['default' => Config::integer('email-integration.outbox.defaults.daily_send_limit')]))
                            ->helperText(__('filament/pages/email-accounts.settings.daily_send_limit.helper_text')),
                    ]),
            ])
            ->action(function (array $arguments, array $data): void {
                $account = $this->findAccount($arguments);

                if (! $account instanceof ConnectedAccount) {
                    return;
                }

                resolve(UpdateConnectedAccountSettingsAction::class)->execute($account, $data);
            })
            ->modalHeading(__('filament/pages/email-accounts.settings.modal_heading'))
            ->modalSubmitActionLabel(__('filament/pages/email-accounts.settings.submit_label'));
    }

    public function syncCalendarAction(): Action
    {
        return Action::make('syncCalendar')
            ->label(fn (array $arguments): string => $this->findAccount($arguments)?->hasCalendar()
                ? __('filament/pages/email-accounts.actions.sync_calendar.disable_label')
                : __('filament/pages/email-accounts.actions.sync_calendar.enable_label'))
            ->icon('heroicon-o-calendar')
            ->color(fn (array $arguments): string => $this->findAccount($arguments)?->hasCalendar() ? 'warning' : 'success')
            ->size(Size::Small)
            ->visible(fn (array $arguments): bool => $this->findAccount($arguments) instanceof ConnectedAccount)
            ->requiresConfirmation(fn (array $arguments): bool => (bool) $this->findAccount($arguments)?->hasCalendar())
            ->modalHeading(fn (array $arguments): string => $this->findAccount($arguments)?->hasCalendar()
                ? __('filament/pages/email-accounts.actions.sync_calendar.disable_heading')
                : __('filament/pages/email-accounts.actions.sync_calendar.enable_heading'))
            ->modalDescription(fn (array $arguments): string => $this->findAccount($arguments)?->hasCalendar()
                ? __('filament/pages/email-accounts.actions.sync_calendar.disable_description')
                : __('filament/pages/email-accounts.actions.sync_calendar.enable_description', [
                    'provider' => $this->findAccount($arguments)?->provider->getLabel() ?? __('filament/pages/email-accounts.actions.sync_calendar.fallback_provider'),
                ]))
            ->action(function (array $arguments): void {
                $account = $this->findOwnedAccountOrFail($arguments);

                if ($account->hasCalendar()) {
                    $account->disableCalendar();
                    $this->connectedAccounts = $this->getAccounts();

                    return;
                }

                // Always re-run OAuth when enabling so the provider grants the calendar scope on the token.
                $this->redirect(route('email-accounts.redirect', ['provider' => $account->provider->value]).'?capability=calendar');
            });
    }

    public function syncCalendarNowAction(): Action
    {
        return Action::make('syncCalendarNow')
            ->label(__('filament/pages/email-accounts.actions.sync_calendar_now'))
            ->icon('heroicon-o-arrow-path')
            ->color('primary')
            ->size(Size::Small)
            ->visible(fn (array $arguments): bool => (bool) $this->findAccount($arguments)?->hasCalendar())
            ->action(function (array $arguments): void {
                $account = $this->findOwnedAccountOrFail($arguments);

                dispatch(new IncrementalCalendarSyncJob($account));

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-accounts.notifications.calendar_sync_queued.title'))
                    ->body(__('filament/pages/email-accounts.notifications.calendar_sync_queued.body'))
                    ->send();
            });
    }

    /** @param array<string, mixed> $arguments */
    private function findAccount(array $arguments): ?ConnectedAccount
    {
        /** @var ConnectedAccount|null */
        return $this->ownedAccountsQuery()->find((string) $arguments['account_id']);
    }

    /** @param array<string, mixed> $arguments */
    private function findOwnedAccountOrFail(array $arguments): ConnectedAccount
    {
        /** @var ConnectedAccount */
        return $this->ownedAccountsQuery()->findOrFail((string) $arguments['account_id']);
    }

    /**
     * @return Builder<ConnectedAccount>
     */
    private function ownedAccountsQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();
        /** @var Team $team */
        $team = filament()->getTenant();

        return ConnectedAccount::query()->ownedBy($user, $team);
    }

    public function disconnectAction(): Action
    {
        return Action::make('disconnect')
            ->label(__('filament/pages/email-accounts.actions.disconnect'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->size(Size::Small)
            ->requiresConfirmation()
            ->action(function (array $arguments): void {
                $account = $this->findOwnedAccountOrFail($arguments);

                resolve(DisconnectConnectedAccountAction::class)->execute($account);
            });
    }

    public function sendSuccessNotification(): void
    {
        if (Session::has('success')) {
            Notification::make()
                ->title(Session::get('success'))
                ->success()
                ->send();
        }
    }

    public function sendErrorNotification(): void
    {
        if (Session::has('error')) {
            Notification::make()
                ->title(Session::get('error'))
                ->danger()
                ->send();
        }
    }
}
