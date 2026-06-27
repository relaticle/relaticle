<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ProtectedRecipient;

final readonly class UpdateTeamEmailPrivacySettingsAction
{
    /**
     * @param  array<int, string>  $protectedEmails
     * @param  array<int, string>  $protectedDomains
     */
    public function execute(
        Team $team,
        User $actor,
        EmailPrivacyTier $defaultTier,
        array $protectedEmails,
        array $protectedDomains,
    ): void {
        // Team-wide privacy settings (default tier + protected recipients) may only
        // be changed by the team owner or an admin, regardless of which caller path
        // reaches this action.
        abort_unless(
            $actor->ownsTeam($team) || $actor->hasTeamRole($team, TeamRole::Admin->value),
            403,
        );

        $team->update([
            'default_email_sharing_tier' => $defaultTier->value,
        ]);

        ProtectedRecipient::query()->where('team_id', $team->getKey())->delete();

        foreach ($protectedEmails as $email) {
            if (blank($email)) {
                continue;
            }

            ProtectedRecipient::query()->create([
                'team_id' => $team->getKey(),
                'type' => 'email',
                'value' => strtolower(trim($email)),
                'created_by' => $actor->getKey(),
            ]);
        }

        foreach ($protectedDomains as $domain) {
            if (blank($domain)) {
                continue;
            }

            ProtectedRecipient::query()->create([
                'team_id' => $team->getKey(),
                'type' => 'domain',
                'value' => strtolower(trim($domain)),
                'created_by' => $actor->getKey(),
            ]);
        }
    }
}
