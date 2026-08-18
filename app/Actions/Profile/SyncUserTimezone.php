<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Models\User;
use DateTimeZone;

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
        if ($user->timezone !== null) {
            return false;
        }

        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            return false;
        }

        $user->forceFill(['timezone' => $timezone])->saveQuietly();

        return true;
    }
}
