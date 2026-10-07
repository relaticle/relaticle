<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\WorkspaceSetupStep;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Actions\SaveUserEmailSharingDefaultAction;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class SaveOnboardingSharing
{
    public const array OFFERED_TIERS = [EmailPrivacyTier::METADATA_ONLY, EmailPrivacyTier::SUBJECT];

    public function __construct(
        private MoveWorkspaceSetup $moveSetup,
        private SaveUserEmailSharingDefaultAction $saveSharingDefault,
    ) {}

    public function execute(User $user, Workspace $workspace, EmailPrivacyTier $tier): bool
    {
        abort_unless(in_array($tier, self::OFFERED_TIERS, true), 422);

        if (! $this->asks($user, $workspace)) {
            return $this->moveSetup->execute($user, $workspace, WorkspaceSetupStep::Sharing, WorkspaceSetupStep::UseCase);
        }

        return DB::transaction(function () use ($user, $workspace, $tier): bool {
            if (! $this->moveSetup->execute($user, $workspace, WorkspaceSetupStep::Sharing, WorkspaceSetupStep::UseCase)) {
                return false;
            }

            $this->saveSharingDefault->execute($user, $tier, $tier);

            return true;
        });
    }

    public function asks(User $user, Workspace $workspace): bool
    {
        return $user->default_email_sharing_tier === null
            && ! ConnectedAccount::hasConnectedOutside($user, $workspace);
    }
}
