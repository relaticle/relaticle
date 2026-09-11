<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailVisibilityEnforcement;
use Relaticle\EmailIntegration\Models\TeamEmailBlocklist;

final readonly class UpdateTeamEmailVisibilityEntryAction
{
    public function execute(
        Team $team,
        User $actor,
        TeamEmailBlocklist $entry,
        EmailVisibilityEnforcement $enforcement,
    ): void {
        abort_unless(
            $actor->ownsTeam($team) || $actor->hasTeamRole($team, TeamRole::Admin->value),
            403,
        );

        abort_unless($entry->team_id === $team->getKey(), 403);

        $entry->update([
            'enforcement_level' => $enforcement->value,
        ]);
    }
}
