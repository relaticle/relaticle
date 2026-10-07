<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Workspace;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Scopes\ActiveAccountScope;

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
}
