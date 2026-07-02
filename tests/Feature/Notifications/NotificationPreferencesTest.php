<?php

declare(strict_types=1);

use App\Actions\User\UpdateNotificationPreferences;
use App\Data\NotificationPreferences;
use App\Enums\Notifications\DigestCadence;
use App\Models\User;

it('returns sensible defaults when no preferences are stored', function (): void {
    $user = User::factory()->create(['notification_preferences' => null]);

    $prefs = $user->notificationPreferences();

    expect($prefs->taskAssignedInApp)->toBeTrue()
        ->and($prefs->taskAssignedEmail)->toBeFalse()
        ->and($prefs->digestCadence)->toBe(DigestCadence::Daily);
});

it('persists updated preferences through the action', function (): void {
    $user = User::factory()->create();

    resolve(UpdateNotificationPreferences::class)->execute($user, new NotificationPreferences(
        taskAssignedInApp: false,
        taskAssignedEmail: true,
        digestCadence: DigestCadence::Weekly,
    ));

    $fresh = $user->fresh()->notificationPreferences();

    expect($fresh->taskAssignedInApp)->toBeFalse()
        ->and($fresh->taskAssignedEmail)->toBeTrue()
        ->and($fresh->digestCadence)->toBe(DigestCadence::Weekly);
});

it('switches the digest cadence off immutably', function (): void {
    $prefs = new NotificationPreferences(digestCadence: DigestCadence::Daily);

    $off = $prefs->withDigestCadence(DigestCadence::Off);

    expect($off->digestCadence)->toBe(DigestCadence::Off)
        ->and($prefs->digestCadence)->toBe(DigestCadence::Daily);
});
