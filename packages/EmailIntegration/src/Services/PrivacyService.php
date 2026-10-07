<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailShare;

final readonly class PrivacyService
{
    public function __construct(
        private EmailVisibilityService $visibility,
        private PreferredEmailCopyService $preferredCopies,
    ) {}

    /**
     * Resolve the effective privacy tier this $viewer can see on $email.
     * Returns null if the email is completely hidden (visibility rules / private / internal).
     */
    public function effectiveTier(Email $email, User $viewer): ?EmailPrivacyTier
    {
        if ($email->user_id === $viewer->getKey()) {
            if ($this->visibility->isHiddenFromOwner($email)) {
                return null;
            }

            return EmailPrivacyTier::FULL;
        }

        if ($this->visibility->isHiddenFromTeammate($email)) {
            return null;
        }

        if ($this->preferredCopies->viewerHasSyncedCopy($email, $viewer)) {
            return EmailPrivacyTier::FULL;
        }

        // Per-email share overrides the email's own tier (uses the loaded relation when
        // eager-loaded, so filtering a list of emails doesn't issue a query per row).
        $share = $this->shareForViewer($email, $viewer);

        if ($share instanceof EmailShare) {
            $tier = EmailPrivacyTier::from($share->tier);

            if ($tier === EmailPrivacyTier::PRIVATE) {
                return null;
            }

            return $tier;
        }

        // Email's own tier
        $tier = $email->privacy_tier;

        if ($tier === EmailPrivacyTier::PRIVATE) {
            return null;
        }

        return $tier;
    }

    public function tierForMailbox(ConnectedAccount $mailbox): EmailPrivacyTier
    {
        return $mailbox->sharing_tier ?? $this->workspaceSharingTier($mailbox->workspace);
    }

    public function tierFromPreference(mixed $tierValue, ConnectedAccount $mailbox): EmailPrivacyTier
    {
        return match (true) {
            $tierValue instanceof EmailPrivacyTier => $tierValue,
            filled($tierValue) => EmailPrivacyTier::from((string) $tierValue),
            default => $this->workspaceSharingTier($mailbox->workspace),
        };
    }

    public function workspaceSharingTier(Workspace $workspace): EmailPrivacyTier
    {
        return $workspace->default_email_sharing_tier ?? EmailPrivacyTier::METADATA_ONLY;
    }

    private function shareForViewer(Email $email, User $viewer): ?EmailShare
    {
        $email->loadMissing('shares');
        $direct = $email->shares->firstWhere('shared_with', $viewer->getKey());

        if ($direct instanceof EmailShare) {
            return $direct;
        }

        if (blank($email->rfc_message_id)) {
            return null;
        }

        return EmailShare::query()
            ->where('workspace_id', $email->workspace_id)
            ->where('shared_with', $viewer->getKey())
            ->whereHas('email', function (Builder $query) use ($email): void {
                $query
                    ->where('workspace_id', $email->workspace_id)
                    ->where('rfc_message_id', $email->rfc_message_id);
            })
            ->first();
    }
}
