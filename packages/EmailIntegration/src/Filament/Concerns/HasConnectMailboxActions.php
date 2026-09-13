<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use App\Models\Team;
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
            ->url(fn (): string => MailboxOAuthWorkspace::redirectUrl('gmail', $this->mailboxOAuthTeam()), true);
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
            ->url(fn (): string => MailboxOAuthWorkspace::redirectUrl('azure', $this->mailboxOAuthTeam()), true);
    }

    private function mailboxOAuthTeam(): Team
    {
        $team = filament()->getTenant();

        throw_unless($team instanceof Team, RuntimeException::class, 'Mailbox OAuth requires an active workspace.');

        return $team;
    }
}
