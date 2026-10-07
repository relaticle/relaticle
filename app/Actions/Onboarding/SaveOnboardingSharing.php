<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\WorkspaceSetupStep;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Actions\SaveMailboxSharingTierAction;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class SaveOnboardingSharing
{
    public const array OFFERED_TIERS = [EmailPrivacyTier::METADATA_ONLY, EmailPrivacyTier::SUBJECT];

    public function __construct(
        private MoveWorkspaceSetup $moveSetup,
        private SaveMailboxSharingTierAction $saveSharingTier,
    ) {}

    public function execute(User $user, Workspace $workspace, EmailPrivacyTier $tier): bool
    {
        abort_unless(in_array($tier, self::OFFERED_TIERS, true), 422);

        return DB::transaction(function () use ($user, $workspace, $tier): bool {
            if (! $this->moveSetup->execute($user, $workspace, WorkspaceSetupStep::Sharing, WorkspaceSetupStep::UseCase)) {
                return false;
            }

            $mailbox = ConnectedAccount::query()->newestConnectedFor($user, $workspace)->first();

            if ($mailbox instanceof ConnectedAccount) {
                $this->saveSharingTier->execute($user, $mailbox, $tier);
            }

            return true;
        });
    }
}
