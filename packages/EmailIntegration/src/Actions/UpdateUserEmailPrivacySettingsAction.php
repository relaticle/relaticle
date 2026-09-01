<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\EmailBlocklist;

final readonly class UpdateUserEmailPrivacySettingsAction
{
    /**
     * @param  list<array{type: string, value: string}>  $blocklist
     */
    public function execute(User $user, ?EmailPrivacyTier $defaultTier, array $blocklist): void
    {
        $user->update([
            'default_email_sharing_tier' => $defaultTier?->value,
        ]);

        $teamId = $user->currentTeam->getKey();

        EmailBlocklist::query()
            ->where('user_id', $user->getKey())
            ->where('team_id', $teamId)
            ->delete();

        foreach ($blocklist as $entry) {
            $value = strtolower(trim((string) $entry['value']));

            if ($value === '') {
                continue;
            }

            EmailBlocklist::query()->create([
                'user_id' => $user->getKey(),
                'team_id' => $teamId,
                'type' => $entry['type'],
                'value' => $value,
            ]);
        }
    }
}
