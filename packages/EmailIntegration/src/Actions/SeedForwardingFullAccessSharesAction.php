<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Team;
use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\UserForwardingFullAccessGrant;
use Relaticle\EmailIntegration\Services\EmailSharingService;

final readonly class SeedForwardingFullAccessSharesAction
{
    public function __construct(private EmailSharingService $sharing) {}

    public function execute(Email $email, User $owner, Team $team): void
    {
        $granteeIds = UserForwardingFullAccessGrant::query()
            ->where('user_id', $owner->getKey())
            ->where('team_id', $team->getKey())
            ->pluck('granted_user_id');

        foreach ($granteeIds as $granteeId) {
            $grantee = $team->allUsers()->firstWhere('id', $granteeId);

            if (! $grantee instanceof User) {
                continue;
            }

            $this->sharing->shareEmail($email, $owner, $grantee, EmailPrivacyTier::FULL);
        }
    }
}
