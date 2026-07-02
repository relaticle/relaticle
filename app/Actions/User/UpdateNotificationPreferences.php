<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Data\NotificationPreferences;
use App\Models\User;

final readonly class UpdateNotificationPreferences
{
    public function execute(User $user, NotificationPreferences $preferences): void
    {
        $user->update([
            'notification_preferences' => $preferences->toArray(),
        ]);
    }
}
