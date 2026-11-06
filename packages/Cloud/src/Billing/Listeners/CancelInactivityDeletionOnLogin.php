<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Listeners;

use App\Actions\Jetstream\CancelInactivityDeletion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Builder;

final readonly class CancelInactivityDeletionOnLogin
{
    public function __construct(private CancelInactivityDeletion $cancelInactivityDeletion) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        rescue(fn (): bool => Workspace::query()
            ->scheduledForInactivity()
            ->where(fn (Builder $workspaces): Builder => $workspaces
                ->where('user_id', $user->getKey())
                ->orWhereHas('users', fn (Builder $members): Builder => $members->whereKey($user->getKey())))
            ->each($this->cancelInactivityDeletion->execute(...)), report: true);
    }
}
