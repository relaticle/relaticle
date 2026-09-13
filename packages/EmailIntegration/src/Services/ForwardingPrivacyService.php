<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\Team;
use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\UserForwardingSettings;

final readonly class ForwardingPrivacyService
{
    public function defaultSharingTier(User $user, Team $team): EmailPrivacyTier
    {
        $settings = UserForwardingSettings::query()
            ->where('user_id', $user->getKey())
            ->where('team_id', $team->getKey())
            ->first();

        if ($settings instanceof UserForwardingSettings) {
            return $settings->sharing_tier;
        }

        if ($user->default_email_sharing_tier instanceof EmailPrivacyTier) {
            return $user->default_email_sharing_tier;
        }

        return $team->default_email_sharing_tier ?? EmailPrivacyTier::METADATA_ONLY;
    }
}
