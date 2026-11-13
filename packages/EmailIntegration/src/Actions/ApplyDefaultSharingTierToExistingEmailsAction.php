<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;

final readonly class ApplyDefaultSharingTierToExistingEmailsAction
{
    public function execute(ConnectedAccount $mailbox, EmailPrivacyTier $tier): int
    {
        return Email::query()
            ->where('connected_account_id', $mailbox->getKey())
            ->where('privacy_tier_customized', false)
            ->where('privacy_tier', '!=', $tier->value)
            ->update(['privacy_tier' => $tier->value]);
    }
}
