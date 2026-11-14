<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Illuminate\Database\Eloquent\Builder;
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
            ->where(fn (Builder $emails): Builder => $emails
                ->whereNull('automated_reason')
                ->orWhereIn('privacy_tier', array_map(fn (EmailPrivacyTier $open): string => $open->value, $tier->moreOpenTiers())))
            ->where('privacy_tier', '!=', $tier->value)
            ->update(['privacy_tier' => $tier->value]);
    }
}
