<?php

declare(strict_types=1);

use App\Filament\Notifications\NotificationCollection;
use App\Livewire\FilamentNotifications;
use Filament\Livewire\Notifications;
use Filament\Notifications\Notification;
use Livewire\Livewire;

it('filters non-array junk out of the notifications livewire payload', function (): void {
    $valid = Notification::make('valid-notification')->title('Hello')->toArray();

    $collection = NotificationCollection::fromLivewire([
        $valid,
        7,
        'junk',
        null,
    ]);

    expect($collection)->toHaveCount(1)
        ->and($collection->first())->toBeInstanceOf(Notification::class)
        ->and($collection->first()->getId())->toBe('valid-notification');
});

it('resolves the panel notifications component to the resilient subclass', function (): void {
    $panelPath = config('app.app_panel_path', 'app');

    $this->get("/{$panelPath}/login")->assertOk();

    [$name, $class] = app('livewire.factory')
        ->resolveComponentNameAndClass(Notifications::class);

    expect($class)->toBe(FilamentNotifications::class);
});

it('mounts the resilient collection on the notifications component', function (): void {
    $instance = Livewire::test(FilamentNotifications::class)->instance();

    expect($instance->notifications)->toBeInstanceOf(NotificationCollection::class);
});

it('survives a full livewire roundtrip with the resilient collection', function (): void {
    Livewire::test(FilamentNotifications::class)
        ->call('removeNotification', 'nonexistent-id')
        ->assertOk();
});

it('responds 419 instead of 500 to a tampered livewire snapshot', function (): void {
    $snapshot = json_encode([
        'data' => [],
        'memo' => ['id' => 'tampered-id', 'name' => 'tampered-component'],
        'checksum' => 'definitely-not-valid',
    ]);

    $this->postJson(route('default-livewire.update'), [
        'components' => [
            ['snapshot' => $snapshot, 'updates' => [], 'calls' => []],
        ],
    ], ['X-Livewire' => 'true'])
        ->assertStatus(419);
});
