<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Services\PrivacyService;

final readonly class SaveUserEmailSharingDefaultAction
{
    public function __construct(
        private UpdateUserEmailPrivacySettingsAction $updateSettings,
        private ApplyDefaultSharingTierToExistingEmailsAction $applyRetroactive,
        private PrivacyService $privacy,
    ) {}

    public function execute(User $user, ?EmailPrivacyTier $storedTier, EmailPrivacyTier $effectiveTier): void
    {
        $previousEffectiveTier = $this->privacy->effectiveSharingTierForUser($user);
        $hadOverride = $user->default_email_sharing_tier !== null;

        DB::transaction(function () use ($user, $storedTier, $effectiveTier, $previousEffectiveTier, $hadOverride): void {
            $this->updateSettings->execute($user, $storedTier);

            if (! $storedTier instanceof EmailPrivacyTier) {
                if ($hadOverride) {
                    $this->applyRetroactive->executeForUserUsingWorkspaceDefaults($user);
                }

                return;
            }

            if ($previousEffectiveTier !== $effectiveTier || ! $hadOverride) {
                $this->applyRetroactive->executeForUser($user, $effectiveTier);
            }
        });
    }
}
