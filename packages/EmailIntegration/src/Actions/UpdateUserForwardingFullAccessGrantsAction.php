<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Team;
use App\Models\User;
use Relaticle\EmailIntegration\Models\UserForwardingFullAccessGrant;

final readonly class UpdateUserForwardingFullAccessGrantsAction
{
    /**
     * @param  list<string>  $grantedUserIds
     */
    public function execute(User $user, Team $team, array $grantedUserIds): void
    {
        $memberIds = $team->allUsers()
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
        $validIds = array_values(array_intersect($grantedUserIds, $memberIds));
        $validIds = array_values(array_filter(
            $validIds,
            fn (string $id): bool => $id !== $user->getKey(),
        ));

        UserForwardingFullAccessGrant::query()
            ->where('user_id', $user->getKey())
            ->where('team_id', $team->getKey())
            ->delete();

        foreach ($validIds as $granteeId) {
            UserForwardingFullAccessGrant::query()->create([
                'user_id' => $user->getKey(),
                'team_id' => $team->getKey(),
                'granted_user_id' => $granteeId,
            ]);
        }
    }
}
