<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use App\Models\Workspace;
use Filament\Actions\Action;
use Relaticle\EmailIntegration\Support\MailboxOAuthWorkspace;
use RuntimeException;

trait HasConnectMailboxActions
{
    public function connectGmailAction(): Action
    {
        return Action::make('connectGmail')
            ->label(__('filament/pages/email-accounts.actions.connect_gmail'))
            ->icon('icon-google')
            ->color('gray')
            ->outlined()
            ->url(fn (): string => MailboxOAuthWorkspace::redirectUrl('gmail', $this->mailboxOAuthWorkspace()), true);
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
            ->url(fn (): string => MailboxOAuthWorkspace::redirectUrl('azure', $this->mailboxOAuthWorkspace()), true);
    }

    private function mailboxOAuthWorkspace(): Workspace
    {
        $team = filament()->getTenant();

        throw_unless($team instanceof Workspace, RuntimeException::class, 'Mailbox OAuth requires an active workspace.');

        return $team;
    }
}
