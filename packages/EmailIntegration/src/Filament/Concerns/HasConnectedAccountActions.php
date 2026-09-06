<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use App\Models\Team;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Enums\IconSize;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Support\View\ComponentAttributeBag;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Relaticle\EmailIntegration\Actions\DisconnectConnectedAccountAction;
use Relaticle\EmailIntegration\Actions\SetDefaultConnectedAccountAction;
use Relaticle\EmailIntegration\Actions\StartMailboxHistoryImportAction;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountSettingsPage;
use Relaticle\EmailIntegration\Jobs\IncrementalCalendarSyncJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

use function Filament\Support\generate_icon_html;

/**
 * The per-account action menu shared by the accounts list and a single account's
 * settings page. Every action resolves its account through
 * {@see self::ownedAccountsQuery()}, so a tampered account_id can never reach
 * another user's mailbox.
 *
 * Pages react to a mutation by overriding {@see self::afterAccountChanged()} /
 * {@see self::afterAccountDisconnected()}.
 */
trait HasConnectedAccountActions
{
    protected function afterAccountChanged(): void {}

    protected function afterAccountDisconnected(): void
    {
        $this->afterAccountChanged();
    }

    public function syncingIcon(): Htmlable
    {
        return generate_icon_html(
            'heroicon-m-arrow-path',
            attributes: new ComponentAttributeBag(['class' => 'motion-safe:animate-spin']),
            size: IconSize::Small,
        ) ?? new HtmlString('');
    }

    /**
     * Native Filament dropdown grouping the per-account actions. Arguments are baked onto
     * each child action so the group can be rendered once per account in the blade.
     *
     * @param  array<int, Action>  $extraActions  page-specific entries, appended before Disconnect
     * @param  bool  $includeSettings  whether to show the Settings link (hidden on the settings page itself)
     */
    public function accountActions(string $accountId, EmailAccountStatus $status, array $extraActions = [], bool $includeSettings = true): ActionGroup
    {
        $arguments = ['account_id' => $accountId];

        $settingsAction = $includeSettings
            ? [($this->accountSettingsAction())($arguments)]
            : [];

        // Invoke each action with the arguments (not ->arguments()) so account_id is encoded
        // into the mountAction() click handler, which reads getInvokedArguments().
        return ActionGroup::make([
            ...$settingsAction,
            ($this->setDefaultAction())($arguments),
            ($this->reAuthAction())($arguments)
                ->visible(in_array($status, [
                    EmailAccountStatus::REAUTH_REQUIRED,
                    EmailAccountStatus::ERROR,
                ], true)),
            ($this->syncCalendarNowAction())($arguments),
            ($this->reimportHistoryAction())($arguments),
            ($this->syncCalendarAction())($arguments),
            ...array_map(fn (Action $action): Action => $action($arguments), $extraActions),
            ($this->disconnectAction())($arguments),
        ])
            ->label(__('filament/pages/email-accounts.actions.manage'))
            ->icon(Heroicon::EllipsisVertical)
            ->color('gray')
            ->size(Size::Small)
            ->iconButton();
    }

    public function accountSettingsAction(): Action
    {
        return Action::make('accountSettings')
            ->label(__('filament/pages/email-accounts.actions.manage'))
            ->icon('heroicon-o-cog-6-tooth')
            ->color('gray')
            ->size(Size::Small)
            ->url(fn (array $arguments): string => EmailAccountSettingsPage::getUrl([
                'account' => (string) $arguments['account_id'],
            ]));
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
                    $this->afterAccountChanged();

                    return;
                }

                // Always re-run OAuth when enabling so the provider grants the calendar scope on the token.
                $this->redirect(route('email-accounts.redirect', ['provider' => $account->provider->value]));
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

    public function reimportHistoryAction(): Action
    {
        return Action::make('reimportHistory')
            ->label(__('filament/pages/email-accounts.actions.reimport_history.label'))
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->size(Size::Small)
            ->requiresConfirmation()
            ->modalHeading(__('filament/pages/email-accounts.actions.reimport_history.heading'))
            ->modalDescription(__('filament/pages/email-accounts.actions.reimport_history.description'))
            ->action(function (array $arguments): void {
                $account = $this->findOwnedAccountOrFail($arguments);

                resolve(StartMailboxHistoryImportAction::class)->execute($account);

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-accounts.notifications.reimport_queued.title'))
                    ->body(__('filament/pages/email-accounts.notifications.reimport_queued.body'))
                    ->send();
            });
    }

    public function setDefaultAction(): Action
    {
        return Action::make('setDefault')
            ->label(__('filament/pages/email-accounts.actions.set_default'))
            ->icon('heroicon-o-star')
            ->color('warning')
            ->size(Size::Small)
            ->visible(fn (array $arguments): bool => $this->findAccount($arguments)?->is_default === false)
            ->action(function (array $arguments): void {
                $account = $this->findOwnedAccountOrFail($arguments);

                resolve(SetDefaultConnectedAccountAction::class)->execute($account);

                $this->afterAccountChanged();

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-accounts.notifications.default_set.title'))
                    ->body(__('filament/pages/email-accounts.notifications.default_set.body', [
                        'email' => $account->email_address,
                    ]))
                    ->send();
            });
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

                $this->afterAccountDisconnected();

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-accounts.notifications.disconnected.title'))
                    ->body(__('filament/pages/email-accounts.notifications.disconnected.body'))
                    ->send();
            });
    }

    /** @param array<string, mixed> $arguments */
    protected function findAccount(array $arguments): ?ConnectedAccount
    {
        /** @var ConnectedAccount|null */
        return $this->ownedAccountsQuery()->find((string) $arguments['account_id']);
    }

    /** @param array<string, mixed> $arguments */
    protected function findOwnedAccountOrFail(array $arguments): ConnectedAccount
    {
        /** @var ConnectedAccount */
        return $this->ownedAccountsQuery()->findOrFail((string) $arguments['account_id']);
    }

    /**
     * @return Builder<ConnectedAccount>
     */
    protected function ownedAccountsQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();
        /** @var Team $team */
        $team = filament()->getTenant();

        return ConnectedAccount::query()->ownedBy($user, $team);
    }
}
