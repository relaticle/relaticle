<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Relaticle\EmailIntegration\Actions\DisconnectFormerMemberMailbox;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

#[Description('Disconnect mailboxes whose owner no longer belongs to the workspace')]
#[Signature('email:disconnect-former-member-mailboxes {--force : Disconnect the mailboxes instead of reporting them}')]
final class DisconnectFormerMemberMailboxesCommand extends Command
{
    public function handle(DisconnectFormerMemberMailbox $disconnect): int
    {
        $write = (bool) $this->option('force');
        $found = 0;

        $mailboxes = ConnectedAccount::query()
            ->whereNotExists(fn (Builder $owned): Builder => $owned
                ->from('workspaces')
                ->whereColumn('workspaces.id', 'connected_accounts.workspace_id')
                ->whereColumn('workspaces.user_id', 'connected_accounts.user_id'))
            ->whereNotExists(fn (Builder $joined): Builder => $joined
                ->from('workspace_user')
                ->whereColumn('workspace_user.workspace_id', 'connected_accounts.workspace_id')
                ->whereColumn('workspace_user.user_id', 'connected_accounts.user_id'))
            ->lazyById();

        foreach ($mailboxes as $mailbox) {
            $this->info("Mailbox `{$mailbox->getKey()}` in workspace `{$mailbox->workspace_id}`...");

            if ($write) {
                rescue(fn () => $disconnect->execute($mailbox));
            }

            $found++;
        }

        $this->comment($write
            ? "{$found} mailbox(es) of former members disconnected."
            : "{$found} mailbox(es) belong to former members. Re-run with --force to disconnect them.");

        return self::SUCCESS;
    }
}
