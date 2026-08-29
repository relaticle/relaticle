<?php

declare(strict_types=1);

use App\Enums\Notifications\NotificationChannel;
use App\Enums\Notifications\NotificationType;
use App\Filament\Pages\NotificationPreferences;
use App\Livewire\App\Notifications\ManageNotificationPreferences;
use App\Models\User;
use Livewire\Livewire;

it('hydrates cells and the digest toggle from defaults', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    Livewire::test(ManageNotificationPreferences::class)
        ->assertSet('cells.task_assigned.in_app', true)
        ->assertSet('cells.task_assigned.email', false)
        ->assertSet('digestEnabled', true);
});

it('persists a matrix cell instantly when toggled', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    Livewire::test(ManageNotificationPreferences::class)
        ->set('cells.task_assigned.email', true);

    expect($user->fresh()->wantsNotification(NotificationType::TaskAssigned, NotificationChannel::Email))->toBeTrue();
});

it('persists the digest toggle instantly', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    Livewire::test(ManageNotificationPreferences::class)
        ->set('digestEnabled', false);

    expect($user->fresh()->wantsNotification(NotificationType::TaskDigest, NotificationChannel::Email))->toBeFalse();
});

it('renders the standalone notifications page', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)
        ->get(NotificationPreferences::getUrl(tenant: $user->personalTeam()))
        ->assertOk()
        ->assertSee('Daily digest')
        ->assertSee('Task Assignments');
});
