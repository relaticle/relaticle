<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Size;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\HtmlString;
use Relaticle\EmailIntegration\Filament\Clusters\EmailSettings;
use Relaticle\EmailIntegration\Filament\Concerns\HasConnectedAccountActions;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final class EmailAccountsPage extends Page
{
    use HasConnectedAccountActions, HasEmailFeatureFlag;

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
     * view) so they sit with the accounts panel under the cluster tabs — the page
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
     * settings page — a child of this one — is open.
     *
     * @return array<int, string>
     */
    public static function getNavigationItemActiveRoutePattern(): array
    {
        return [
            self::getRouteName(),
            EmailAccountSettingsPage::getRouteName(),
        ];
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
        return $this->ownedAccountsQuery()->defaultFirst()->get();
    }

    public function connectGmailAction(): Action
    {
        return Action::make('connectGmail')
            ->label(__('filament/pages/email-accounts.actions.connect_gmail'))
            ->icon('icon-google')
            ->color('gray')
            ->outlined()
            ->url(fn (): string => route('email-accounts.redirect', ['provider' => 'gmail']), true);
    }

    public function connectAzureAction(): Action
    {
        return Action::make('connectAzure')
            ->label(__('filament/pages/email-accounts.actions.connect_azure'))
            ->icon('heroicon-o-envelope')
            ->color('gray')
            ->outlined()
            // Outlook/Azure connection is hidden for now; re-enable when the provider is ready.
            ->hidden()
            ->url(fn (): string => route('email-accounts.redirect', ['provider' => 'azure']), true);
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
            fn (ConnectedAccount $account): bool => $account->isImportingHistory(),
        );
    }

    public function connectedSectionDescription(): HtmlString
    {
        return new HtmlString(__('filament/pages/email-accounts.sections.connected.description', [
            'url' => route('policy.show'),
        ]));
    }

    public function accountCapabilities(ConnectedAccount $account): string
    {
        $labels = [];

        if ($account->hasEmail()) {
            $labels[] = __('filament/pages/email-accounts.capabilities.email');
        }

        if ($account->hasCalendar()) {
            $labels[] = __('filament/pages/email-accounts.capabilities.calendar');
        }

        if ($labels === []) {
            return $account->provider->getLabel();
        }

        return implode(', ', $labels);
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
