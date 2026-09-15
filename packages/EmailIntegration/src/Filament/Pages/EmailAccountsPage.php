<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use App\Models\Team;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\HtmlString;
use Relaticle\EmailIntegration\Actions\EnsureTeamForwardingAddressAction;
use Relaticle\EmailIntegration\Filament\Clusters\EmailSettings;
use Relaticle\EmailIntegration\Filament\Concerns\HasConnectedAccountActions;
use Relaticle\EmailIntegration\Filament\Concerns\HasConnectMailboxActions;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\TeamForwardingAddress;

final class EmailAccountsPage extends Page
{
    use HasConnectedAccountActions;
    use HasConnectMailboxActions;
    use HasEmailFeatureFlag;

    protected string $view = 'email-integration::filament.pages.email-accounts';

    protected static ?string $cluster = EmailSettings::class;

    protected static ?string $slug = 'accounts';

    protected static ?int $navigationSort = 1;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-at-symbol';

    public function getTitle(): string
    {
        return __('filament/pages/email-accounts.title');
    }

    /**
     * Heading and subheading are rendered inside the content column (see the page
     * view) so they sit with the accounts panel under the cluster tabs. The page
     * header itself stays empty.
     */
    public function getHeading(): string
    {
        return '';
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public static function getNavigationLabel(): string
    {
        return __('filament/pages/email-accounts.navigation_label');
    }

    /**
     * Keep the "Accounts" cluster item highlighted while a single account's
     * settings page, a child of this one, is open.
     *
     * @return array<int, string>
     */
    public static function getNavigationItemActiveRoutePattern(): array
    {
        return [
            self::getRouteName(),
            EmailAccountSettingsPage::getRouteName(),
            ForwardingAddressSettingsPage::getRouteName(),
        ];
    }

    /**
     * @var Collection<int, ConnectedAccount>
     */
    public Collection $connectedAccounts;

    public TeamForwardingAddress $forwardingAddress;

    public function mount(EnsureTeamForwardingAddressAction $ensureForwardingAddress): void
    {
        $this->sendSuccessNotification();
        $this->sendErrorNotification();
        $this->connectedAccounts = $this->getAccounts();

        /** @var User $user */
        $user = auth()->user();
        $team = $user->currentTeam;

        abort_unless($team instanceof Team, 404);

        $this->forwardingAddress = $ensureForwardingAddress->execute($team);
    }

    /**
     * @return Collection<int, ConnectedAccount>
     */
    private function getAccounts(): Collection
    {
        return $this->ownedAccountsQuery()->defaultFirst()->get();
    }

    public function forwardingActions(): ActionGroup
    {
        return ActionGroup::make([
            Action::make('forwardingSettings')
                ->label(__('filament/pages/email-accounts.actions.manage'))
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->size(Size::Small)
                ->url(fn (): string => ForwardingAddressSettingsPage::getUrl()),
        ])
            ->label(__('filament/pages/email-accounts.actions.manage'))
            ->icon(Heroicon::EllipsisVertical)
            ->color('gray')
            ->size(Size::Small)
            ->iconButton();
    }

    public function editSettingsAction(): Action
    {
        return Action::make('editSettings')
            ->label(__('filament/pages/email-accounts.actions.edit_settings'))
            ->icon('heroicon-o-cog-6-tooth')
            ->color('gray')
            ->size(Size::Small)
            ->url(fn (array $arguments): string => EmailAccountSettingsPage::getUrl([
                'account' => (string) $arguments['account_id'],
            ]));
    }

    public function refreshAccounts(): void
    {
        $this->connectedAccounts = $this->getAccounts();
    }

    public function isImportingAnyAccount(): bool
    {
        return $this->connectedAccounts->contains(
            fn (ConnectedAccount $account): bool => $account->showsSyncProgress(),
        );
    }

    public function connectedSectionDescription(): HtmlString
    {
        return new HtmlString(__('filament/pages/email-accounts.sections.connected.description', [
            'url' => route('policy.show'),
        ]));
    }

    protected function afterAccountChanged(): void
    {
        $this->refreshAccounts();
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
