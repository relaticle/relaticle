<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Team;
use App\Models\User;
use Relaticle\EmailIntegration\Models\UserForwardingBlocklist;

final readonly class UpdateUserForwardingBlocklistAction
{
    /**
     * @param  list<array{type: string, value: string}>  $blocklist
     */
    public function execute(User $user, Team $team, array $blocklist): void
    {
        UserForwardingBlocklist::query()
            ->where('user_id', $user->getKey())
            ->where('team_id', $team->getKey())
            ->delete();

        foreach ($blocklist as $entry) {
            $value = strtolower(trim((string) $entry['value']));

            if ($value === '') {
                continue;
            }

            UserForwardingBlocklist::query()->create([
                'user_id' => $user->getKey(),
                'team_id' => $team->getKey(),
                'type' => $entry['type'],
                'value' => $value,
            ]);
        }
    }
}
