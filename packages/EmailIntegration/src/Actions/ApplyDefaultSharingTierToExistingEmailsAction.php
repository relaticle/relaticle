<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\PrivacyService;

final readonly class ApplyDefaultSharingTierToExistingEmailsAction
{
    public function executeForTeam(Workspace $team, EmailPrivacyTier $tier): int
    {
        $userIds = User::query()
            ->whereNull('default_email_sharing_tier')
            ->where(function (Builder $query) use ($team): void {
                $query->whereHas('workspaces', fn (Builder $teamQuery) => $teamQuery->whereKey($team->getKey()))
                    ->orWhereKey($team->user_id);
            })
            ->pluck('id');

        if ($userIds->isEmpty()) {
            return 0;
        }

        return Email::query()
            ->where('workspace_id', $team->getKey())
            ->whereIn('user_id', $userIds)
            ->where('privacy_tier_customized', false)
            ->update(['privacy_tier' => $tier->value]);
    }

    public function executeForUser(User $user, EmailPrivacyTier $tier): int
    {
        // ActiveAccountScope hides disconnected mailboxes from normal reads; the same
        // filter applies here so retroactive updates never touch orphaned rows.
        return Email::query()
            ->where('user_id', $user->getKey())
            ->where('privacy_tier_customized', false)
            ->update(['privacy_tier' => $tier->value]);
    }

    public function executeForUserUsingWorkspaceDefaults(User $user): int
    {
        $teamIds = Email::query()
            ->where('user_id', $user->getKey())
            ->where('privacy_tier_customized', false)
            ->distinct()
            ->pluck('workspace_id');

        if ($teamIds->isEmpty()) {
            return 0;
        }

        $teams = Workspace::query()
            ->whereIn('id', $teamIds)
            ->get()
            ->keyBy('id');

        $updated = 0;

        foreach ($teamIds as $teamId) {
            $team = $teams->get($teamId);

            if ($team === null) {
                continue;
            }

            $tier = resolve(PrivacyService::class)->workspaceSharingTier($team);

            $updated += Email::query()
                ->where('user_id', $user->getKey())
                ->where('workspace_id', $teamId)
                ->where('privacy_tier_customized', false)
                ->update(['privacy_tier' => $tier->value]);
        }

        return $updated;
    }
}
