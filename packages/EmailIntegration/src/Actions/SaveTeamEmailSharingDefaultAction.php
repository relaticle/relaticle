<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Services\PrivacyService;

final readonly class SaveTeamEmailSharingDefaultAction
{
    public function __construct(
        private UpdateTeamEmailPrivacySettingsAction $updateSettings,
        private ApplyDefaultSharingTierToExistingEmailsAction $applyRetroactive,
        private PrivacyService $privacy,
    ) {}

    public function execute(Workspace $team, User $actor, EmailPrivacyTier $newTier): void
    {
        $previousTier = $this->privacy->workspaceSharingTier($team);

        DB::transaction(function () use ($team, $actor, $newTier, $previousTier): void {
            $this->updateSettings->execute($team, $actor, $newTier);

            if ($previousTier !== $newTier) {
                $this->applyRetroactive->executeForTeam($team, $newTier);
            }
        });
    }
}
