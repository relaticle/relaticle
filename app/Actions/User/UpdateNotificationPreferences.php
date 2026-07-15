<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Enums\Notifications\NotificationChannel;
use App\Enums\Notifications\NotificationGroup;
use App\Enums\Notifications\NotificationType;
use App\Jobs\ReactivatePostmarkRecipient;
use App\Models\User;

final readonly class UpdateNotificationPreferences
{
    public function execute(User $user, NotificationType $type, NotificationChannel $channel, bool $enabled): void
    {
        $wasEnabled = $user->wantsNotification($type, $channel);

        $preferences = $user->notificationPreferences()->with($type, $channel, $enabled);

        $user->update(['notification_preferences' => $preferences->overrides]);

        $reEnablingDigestEmail = $type->group() === NotificationGroup::Digest
            && $channel === NotificationChannel::Email
            && $enabled
            && ! $wasEnabled;

        if ($reEnablingDigestEmail) {
            dispatch(new ReactivatePostmarkRecipient($user->email));
        }
    }
}
