<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Relaticle\EmailIntegration\Models\TeamEmailBlocklist;

final readonly class UpdateTeamEmailBlocklistAction
{
    /**
     * @param  array<int, string>  $blockedEmails
     * @param  array<int, string>  $blockedDomains
     */
    public function execute(Team $team, User $actor, array $blockedEmails, array $blockedDomains): void
    {
        abort_unless(
            $actor->ownsTeam($team) || $actor->hasTeamRole($team, TeamRole::Admin->value),
            403,
        );

        TeamEmailBlocklist::query()->where('team_id', $team->getKey())->delete();

        foreach ($blockedEmails as $email) {
            if (blank($email)) {
                continue;
            }

            TeamEmailBlocklist::query()->create([
                'team_id' => $team->getKey(),
                'type' => 'email',
                'value' => strtolower(trim($email)),
                'created_by' => $actor->getKey(),
            ]);
        }

        foreach ($blockedDomains as $domain) {
            if (blank($domain)) {
                continue;
            }

            TeamEmailBlocklist::query()->create([
                'team_id' => $team->getKey(),
                'type' => 'domain',
                'value' => strtolower(trim($domain)),
                'created_by' => $actor->getKey(),
            ]);
        }
    }
}
