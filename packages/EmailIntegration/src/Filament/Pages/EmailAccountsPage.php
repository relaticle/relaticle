<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use App\Filament\Pages\Concerns\HasWorkspaceSettingsNavigation;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Size;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\HtmlString;
use Relaticle\EmailIntegration\Filament\Concerns\HasConnectedAccountActions;
use Relaticle\EmailIntegration\Filament\Concerns\HasConnectMailboxActions;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final class EmailAccountsPage extends Page
{
    use HasConnectedAccountActions;
    use HasConnectMailboxActions;
    use HasEmailFeatureFlag;
    use HasWorkspaceSettingsNavigation;

    protected string $view = 'email-integration::filament.pages.email-accounts';

    protected static ?string $slug = 'workspace/email';

    protected static bool $shouldRegisterNavigation = false;

    public function getTitle(): string
    {
        return __('filament/pages/email-accounts.title');
    }

    /**
     * Heading and subheading are rendered inside the content column (see the page
     * view) so they sit with the accounts panel under the workspace settings tabs. The page
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
