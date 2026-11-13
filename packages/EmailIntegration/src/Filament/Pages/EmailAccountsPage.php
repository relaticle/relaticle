<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use App\Contracts\Billing;
use App\Enums\WorkspaceCapability;
use App\Filament\Pages\Concerns\HasWorkspaceSettingsNavigation;
use App\Models\User;
use App\Models\Workspace;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Session;
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
        return __('workspaces.tabs.email');
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

    public function hasMailboxCapacity(): bool
    {
        return $this->workspace()->hasMailboxCapacity();
    }

    public function mailboxLimitMessage(): string
    {
        return $this->workspace()->mailboxLimitMessage();
    }

    public function upgradeUrl(): ?string
    {
        $workspace = $this->workspace();
        $user = auth()->user();

        if (! $user instanceof User || ! $user->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::BillingManage)) {
            return null;
        }

        return resolve(Billing::class)->url($workspace);
    }

    private function workspace(): Workspace
    {
        /** @var Workspace */
        return filament()->getTenant();
    }

    protected function afterAccountChanged(): void
    {
        $this->refreshAccounts();
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
