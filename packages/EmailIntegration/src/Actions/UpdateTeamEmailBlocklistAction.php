<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Models\TeamEmailBlocklist;

final readonly class UpdateTeamEmailBlocklistAction
{
    /**
     * @param  array<int, string>  $blockedEmails
     * @param  array<int, string>  $blockedDomains
     */
    public function execute(Workspace $team, User $actor, array $blockedEmails, array $blockedDomains): void
    {
        abort_unless(
            $actor->ownsWorkspace($team) || $actor->hasWorkspaceRole($team, WorkspaceRole::Admin->value),
            403,
        );

        TeamEmailBlocklist::query()->where('workspace_id', $team->getKey())->delete();

        foreach ($blockedEmails as $email) {
            if (blank($email)) {
                continue;
            }

            TeamEmailBlocklist::query()->create([
                'workspace_id' => $team->getKey(),
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
                'workspace_id' => $team->getKey(),
                'type' => 'domain',
                'value' => strtolower(trim($domain)),
                'include_subdomains' => false,
                'created_by' => $actor->getKey(),
            ]);
        }
    }
}
