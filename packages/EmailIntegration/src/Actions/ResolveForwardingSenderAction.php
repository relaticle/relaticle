<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Team;
use App\Models\User;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class ResolveForwardingSenderAction
{
    public function execute(Team $team, string $fromAddress): ?User
    {
        $normalized = strtolower(trim($fromAddress));

        if ($normalized === '') {
            return null;
        }

        $member = $team->allUsers()
            ->first(fn (User $user): bool => strtolower($user->email) === $normalized);

        if ($member instanceof User) {
            return $member;
        }

        $accountOwnerId = ConnectedAccount::query()
            ->where('team_id', $team->getKey())
            ->whereRaw('lower(email_address) = ?', [$normalized])
            ->value('user_id');

        if (! is_string($accountOwnerId) || $accountOwnerId === '') {
            return null;
        }

        return $team->allUsers()->firstWhere('id', $accountOwnerId);
    }
}
