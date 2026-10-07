<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Listeners;

use App\Models\User;
use App\Models\Workspace;
use Laravel\Jetstream\Events\TeamMemberRemoved;
use Relaticle\EmailIntegration\Actions\DisconnectConnectedAccountAction;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Throwable;

final readonly class DisconnectRemovedMemberMailboxesListener
{
    public function __construct(private DisconnectConnectedAccountAction $disconnect) {}

    public function handle(TeamMemberRemoved $event): void
    {
        if (! $event->user instanceof User || ! $event->team instanceof Workspace) {
            return;
        }

        ConnectedAccount::query()
            ->ownedBy($event->user, $event->team)
            ->get()
            ->each(function (ConnectedAccount $mailbox): void {
                try {
                    $this->disconnect->execute($mailbox);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
    }
}
