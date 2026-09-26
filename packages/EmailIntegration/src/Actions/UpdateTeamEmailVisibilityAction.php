<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Enums\EmailVisibilityEnforcement;
use Relaticle\EmailIntegration\Models\TeamEmailBlocklist;

final readonly class UpdateTeamEmailVisibilityAction
{
    /**
     * @param  array<int, array{type: string, value: string, enforcement_level: EmailVisibilityEnforcement, include_subdomains?: bool}>  $entries
     */
    public function execute(Workspace $team, User $actor, array $entries): void
    {
        abort_unless(
            $actor->hasWorkspaceCapability($team->getKey(), WorkspaceCapability::EmailManage),
            403,
        );

        TeamEmailBlocklist::query()->where('workspace_id', $team->getKey())->delete();

        foreach ($entries as $entry) {
            if (blank($entry['value'])) {
                continue;
            }

            TeamEmailBlocklist::query()->create([
                'workspace_id' => $team->getKey(),
                'type' => $entry['type'],
                'value' => strtolower(trim((string) $entry['value'])),
                'enforcement_level' => $entry['enforcement_level']->value,
                'include_subdomains' => (bool) ($entry['include_subdomains'] ?? false),
                'created_by' => $actor->getKey(),
            ]);
        }
    }
}
