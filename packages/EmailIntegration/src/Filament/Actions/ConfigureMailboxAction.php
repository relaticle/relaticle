<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Actions;

use App\Models\Team;
use App\Models\User;
use Filament\Actions\Action;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

/**
 * Empty-state call to action pointing users at the mailbox settings page.
 * Only shown while the user has no connected account in the current team.
 */
final class ConfigureMailboxAction extends Action
{
    public static function make(?string $name = null): static
    {
        /** @var static $action */
        $action = parent::make($name ?? 'configureMailbox');

        return $action
            ->label(__('filament/pages/email-accounts.not_connected.action'))
            ->icon('heroicon-o-cog-6-tooth')
            ->url(fn (): string => EmailAccountsPage::getUrl())
            ->visible(function (): bool {
                /** @var User|null $user */
                $user = auth()->user();
                /** @var Team|null $team */
                $team = filament()->getTenant();

                return $user instanceof User && ! ConnectedAccount::hasActiveFor($user, $team);
            });
    }
}
