<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Team;
use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\UserForwardingSettings;

final readonly class UpdateUserForwardingSettingsAction
{
    public function execute(User $user, Team $team, EmailPrivacyTier $sharingTier): UserForwardingSettings
    {
        return UserForwardingSettings::query()->updateOrCreate([
            'user_id' => $user->getKey(),
            'team_id' => $team->getKey(),
        ], [
            'sharing_tier' => $sharingTier->value,
        ]);
    }
}
