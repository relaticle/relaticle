<?php

declare(strict_types=1);

use App\Actions\User\UpdateNotificationPreferences;
use App\Enums\Notifications\NotificationChannel;
use App\Enums\Notifications\NotificationType;
use App\Jobs\ReactivatePostmarkRecipient;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

it('returns code defaults when no override is stored', function (): void {
    $user = User::factory()->create(['notification_preferences' => null]);

    expect($user->wantsNotification(NotificationType::TaskAssigned, NotificationChannel::InApp))->toBeTrue()
        ->and($user->wantsNotification(NotificationType::TaskAssigned, NotificationChannel::Email))->toBeFalse()
        ->and($user->wantsNotification(NotificationType::TaskDigest, NotificationChannel::Email))->toBeTrue();
});

it('persists a single overridden cell through the action', function (): void {
    Bus::fake();
    $user = User::factory()->create();

    resolve(UpdateNotificationPreferences::class)->execute(
        $user, NotificationType::TaskAssigned, NotificationChannel::Email, true,
    );

    expect($user->fresh()->wantsNotification(NotificationType::TaskAssigned, NotificationChannel::Email))->toBeTrue()
        ->and($user->fresh()->notification_preferences)->toBe(['task_assigned' => ['email' => true]]);
});

it('dispatches a Postmark reactivation only when the digest email is re-enabled', function (): void {
    Bus::fake();
    $user = User::factory()->create(['notification_preferences' => ['task_digest' => ['email' => false]]]);

    resolve(UpdateNotificationPreferences::class)->execute(
        $user, NotificationType::TaskDigest, NotificationChannel::Email, true,
    );

    Bus::assertDispatched(
        ReactivatePostmarkRecipient::class,
        fn (ReactivatePostmarkRecipient $job): bool => $job->email === $user->email,
    );
});

it('does not dispatch reactivation when disabling the digest email', function (): void {
    Bus::fake();
    $user = User::factory()->create();

    resolve(UpdateNotificationPreferences::class)->execute(
        $user, NotificationType::TaskDigest, NotificationChannel::Email, false,
    );

    Bus::assertNotDispatched(ReactivatePostmarkRecipient::class);
});

it('does not dispatch reactivation when the digest email was already enabled', function (): void {
    Bus::fake();
    $user = User::factory()->create();

    resolve(UpdateNotificationPreferences::class)->execute(
        $user, NotificationType::TaskDigest, NotificationChannel::Email, true,
    );

    Bus::assertNotDispatched(ReactivatePostmarkRecipient::class);
});
