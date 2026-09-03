<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailVisibilityEnforcement;
use Relaticle\EmailIntegration\Models\TeamEmailBlocklist;

final readonly class UpdateTeamEmailVisibilityAction
{
    /**
     * @param  array<int, array{type: string, value: string, enforcement_level: EmailVisibilityEnforcement}>  $entries
     */
    public function execute(Team $team, User $actor, array $entries): void
    {
        abort_unless(
            $actor->ownsTeam($team) || $actor->hasTeamRole($team, TeamRole::Admin->value),
            403,
        );

        TeamEmailBlocklist::query()->where('team_id', $team->getKey())->delete();

        foreach ($entries as $entry) {
            if (blank($entry['value'])) {
                continue;
            }

            TeamEmailBlocklist::query()->create([
                'team_id' => $team->getKey(),
                'type' => $entry['type'],
                'value' => strtolower(trim((string) $entry['value'])),
                'enforcement_level' => $entry['enforcement_level']->value,
                'created_by' => $actor->getKey(),
            ]);
        }
    }
}
