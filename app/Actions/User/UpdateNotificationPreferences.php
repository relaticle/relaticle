<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Enums\Notifications\NotificationChannel;
use App\Enums\Notifications\NotificationType;
use App\Models\User;

final readonly class UpdateNotificationPreferences
{
    public function execute(User $user, NotificationType $type, NotificationChannel $channel, bool $enabled): void
    {
        $preferences = $user->notificationPreferences()->with($type, $channel, $enabled);

        $user->update(['notification_preferences' => $preferences->overrides]);
    }
}
