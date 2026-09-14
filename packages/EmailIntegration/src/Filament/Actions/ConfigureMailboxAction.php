<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Actions;

use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Action;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

/**
 * Empty-state call to action pointing users at the mailbox settings page.
 * Only shown while the user has no connected account in the current team.
 */
final class ConfigureMailboxAction extends Action
{
    public static function getDefaultName(): string
    {
        return 'configureMailbox';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('filament/pages/email-accounts.not_connected.action'))
            ->icon('heroicon-o-cog-6-tooth')
            ->url(fn (): string => EmailAccountsPage::getUrl())
            ->visible(function (): bool {
                /** @var User|null $user */
                $user = auth()->user();

                if (! $user instanceof User) {
                    return false;
                }

                $team = filament()->getTenant();
                $team = $team instanceof Workspace ? $team : $user->currentWorkspace;

                return ! ConnectedAccount::hasConnectedFor($user, $team instanceof Workspace ? $team : null);
            });
    }
}
