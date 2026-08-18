<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Models\User;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Seeds `users.timezone` from the browser's detected zone.
 *
 * Only ever fills a null value: once a timezone is on the record — whether it was
 * detected earlier or chosen explicitly on the profile page — the browser must not
 * overwrite it, or a deliberate choice would be undone on the next page load from a
 * travelling laptop or a VPN.
 */
final readonly class SyncUserTimezone
{
    public function execute(User $user, string $timezone): bool
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            return false;
        }

        /**
         * "Fill only if still null" is a check followed by a write, so two first-page
         * loads racing each other both passed the check and both wrote — last one won.
         * Re-read the row under a lock inside the transaction so the check and the write
         * cannot be separated. Mirrors StartProTrial, which guards the same write-once
         * shape the same way.
         */
        return DB::transaction(function () use ($user, $timezone): bool {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $locked instanceof User || $locked->timezone !== null) {
                return false;
            }

            $locked->forceFill(['timezone' => $timezone])->saveQuietly();
            $user->setAttribute('timezone', $timezone)->syncOriginalAttribute('timezone');

            return true;
        });
    }
}
