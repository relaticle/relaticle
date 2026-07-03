<?php

declare(strict_types=1);

use App\Enums\Notifications\DigestCadence;
use App\Livewire\App\Profile\UpdateNotificationPreferences;
use App\Models\User;
use Livewire\Livewire;

it('renders current preferences and persists changes', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    Livewire::test(UpdateNotificationPreferences::class)
        ->assertFormSet([
            'task_assigned_in_app' => true,
            'task_assigned_email' => false,
            'digest_cadence' => DigestCadence::Daily->value,
        ])
        ->fillForm([
            'task_assigned_email' => true,
            'digest_cadence' => DigestCadence::Weekly->value,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $preferences = $user->fresh()->notificationPreferences();

    expect($preferences->taskAssignedEmail)->toBeTrue()
        ->and($preferences->digestCadence)->toBe(DigestCadence::Weekly);
});
