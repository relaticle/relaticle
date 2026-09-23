<?php

declare(strict_types=1);

use App\Actions\User\UpdateMarketingConsent;
use App\Enums\Notifications\NotificationChannel;
use App\Enums\Notifications\NotificationType;
use App\Filament\Pages\NotificationPreferences;
use App\Jobs\Email\SyncSubscriberJob;
use App\Livewire\App\Notifications\ManageNotificationPreferences;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

mutates(ManageNotificationPreferences::class, UpdateMarketingConsent::class);

it('hydrates cells and the digest toggle from defaults', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    Livewire::test(ManageNotificationPreferences::class)
        ->assertSet('cells.task_assigned.in_app', true)
        ->assertSet('cells.task_assigned.email', false)
        ->assertSet('digestEnabled', true);
});

it('persists a matrix cell instantly when toggled', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    Livewire::test(ManageNotificationPreferences::class)
        ->set('cells.task_assigned.email', true);

    expect($user->fresh()->wantsNotification(NotificationType::TaskAssigned, NotificationChannel::Email))->toBeTrue();
});

it('persists the digest toggle instantly', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    Livewire::test(ManageNotificationPreferences::class)
        ->set('digestEnabled', false);

    expect($user->fresh()->wantsNotification(NotificationType::TaskDigest, NotificationChannel::Email))->toBeFalse();
});

it('renders the standalone notifications page', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $this->actingAs($user)
        ->get(NotificationPreferences::getUrl(tenant: $user->personalWorkspace()))
        ->assertOk()
        ->assertSee('Daily digest')
        ->assertSee('Task Assignments');
});

it('hydrates the product updates toggle from marketing consent', function (): void {
    $user = User::factory()->withPersonalWorkspace()->withoutMarketingConsent()->create();
    $this->actingAs($user);

    Livewire::test(ManageNotificationPreferences::class)
        ->assertSet('productUpdatesEnabled', false);
});

it('withdraws marketing consent instantly and queues the list sync', function (): void {
    config()->set('mailcoach-sdk.enabled_subscribers_sync', true);
    Queue::fake([SyncSubscriberJob::class]);
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    Livewire::test(ManageNotificationPreferences::class)
        ->set('productUpdatesEnabled', false);

    expect($user->fresh()->marketing_consent_at)->toBeNull();
    Queue::assertPushed(SyncSubscriberJob::class, fn (SyncSubscriberJob $job): bool => invade($job)->userId === (string) $user->id);
});

it('grants marketing consent from the toggle', function (): void {
    $user = User::factory()->withPersonalWorkspace()->withoutMarketingConsent()->create();
    $this->actingAs($user);

    Livewire::test(ManageNotificationPreferences::class)
        ->set('productUpdatesEnabled', true);

    expect($user->fresh()->marketing_consent_at)->not->toBeNull();
});

it('renders the product updates section on the notifications page', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $this->actingAs($user)
        ->get(NotificationPreferences::getUrl(tenant: $user->personalWorkspace()))
        ->assertOk()
        ->assertSee('Product updates');
});
