<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use Filament\Actions\Action;

trait HasConnectMailboxActions
{
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
}
