<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Team;
use App\Models\User;
use Relaticle\EmailIntegration\Models\UserForwardingBlocklist;

final readonly class DeleteUserForwardingBlocklistEntryAction
{
    public function execute(User $user, Team $team, string $entryId): void
    {
        UserForwardingBlocklist::query()
            ->where('user_id', $user->getKey())
            ->where('team_id', $team->getKey())
            ->whereKey($entryId)
            ->firstOrFail()
            ->delete();
    }
}
