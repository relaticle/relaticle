<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Scopes\ActiveAccountScope;
use Relaticle\EmailIntegration\Services\PrivacyService;

final readonly class ApplyDefaultSharingTierToExistingEmailsAction
{
    public function executeForWorkspace(Workspace $workspace, EmailPrivacyTier $tier): int
    {
        // A disconnected mailbox keeps its row and mail, and reconnecting restores both.
        return Email::query()
            ->withoutGlobalScope(ActiveAccountScope::class)
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('connected_account_id', ConnectedAccount::withTrashed()
                ->where('workspace_id', $workspace->getKey())
                ->whereNull('sharing_tier')
                ->select('id'))
            ->where('privacy_tier_customized', false)
            ->update(['privacy_tier' => $tier->value]);
    }

    public function executeForMailbox(ConnectedAccount $mailbox, EmailPrivacyTier $tier): int
    {
        return Email::query()
            ->where('connected_account_id', $mailbox->getKey())
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
        $workspaceIds = Email::query()
            ->where('user_id', $user->getKey())
            ->where('privacy_tier_customized', false)
            ->distinct()
            ->pluck('workspace_id');

        if ($workspaceIds->isEmpty()) {
            return 0;
        }

        $workspaces = Workspace::query()
            ->whereIn('id', $workspaceIds)
            ->get()
            ->keyBy('id');

        $updated = 0;

        foreach ($workspaceIds as $workspaceId) {
            $workspace = $workspaces->get($workspaceId);

            if ($workspace === null) {
                continue;
            }

            $tier = resolve(PrivacyService::class)->workspaceSharingTier($workspace);

            $updated += Email::query()
                ->where('user_id', $user->getKey())
                ->where('workspace_id', $workspaceId)
                ->where('privacy_tier_customized', false)
                ->update(['privacy_tier' => $tier->value]);
        }

        return $updated;
    }
}
