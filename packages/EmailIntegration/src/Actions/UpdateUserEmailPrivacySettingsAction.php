<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;

final readonly class UpdateUserEmailPrivacySettingsAction
{
    public function execute(User $user, ?EmailPrivacyTier $defaultTier): void
    {
        $user->update([
            'default_email_sharing_tier' => $defaultTier?->value,
        ]);
    }
}
