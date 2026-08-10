<?php

declare(strict_types=1);

use App\Actions\User\UpdateNotificationPreferences;
use App\Enums\Notifications\NotificationChannel;
use App\Enums\Notifications\NotificationType;
use App\Models\User;

it('returns code defaults when no override is stored', function (): void {
    $user = User::factory()->create(['notification_preferences' => null]);

    expect($user->wantsNotification(NotificationType::TaskAssigned, NotificationChannel::InApp))->toBeTrue()
        ->and($user->wantsNotification(NotificationType::TaskAssigned, NotificationChannel::Email))->toBeFalse()
        ->and($user->wantsNotification(NotificationType::TaskDigest, NotificationChannel::Email))->toBeTrue();
});

it('persists a single overridden cell through the action', function (): void {
    $user = User::factory()->create();

    resolve(UpdateNotificationPreferences::class)->execute(
        $user, NotificationType::TaskAssigned, NotificationChannel::Email, true,
    );

    expect($user->fresh()->wantsNotification(NotificationType::TaskAssigned, NotificationChannel::Email))->toBeTrue()
        ->and($user->fresh()->notification_preferences)->toBe(['task_assigned' => ['email' => true]]);
});
