<?php

declare(strict_types=1);

use App\Enums\Notifications\NotificationChannel;
use App\Enums\Notifications\NotificationType;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Relaticle\SystemAdmin\Filament\Resources\UserResource;
use Relaticle\SystemAdmin\Filament\Resources\UserResource\Pages\CreateUser;
use Relaticle\SystemAdmin\Filament\Resources\UserResource\Pages\EditUser;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(UserResource::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
});

it('saves a user without touching the password when the field is left blank', function (): void {
    $user = User::factory()->create();
    $originalPassword = $user->password;

    livewire(EditUser::class, ['record' => $user->getKey()])
        ->fillForm(['name' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    $user->refresh();

    expect($user->name)->toBe('Renamed')
        ->and($user->password)->toBe($originalPassword);
});

it('hashes a new password when one is entered', function (): void {
    $user = User::factory()->create();

    livewire(EditUser::class, ['record' => $user->getKey()])
        ->fillForm(['password' => 'new-password'])
        ->call('save')
        ->assertHasNoFormErrors();

    $user->refresh();

    expect($user->password)->not->toBe('new-password')
        ->and(Hash::check('new-password', $user->password))->toBeTrue();
});

it('lets the user authenticate with a password set by a sysadmin', function (): void {
    $user = User::factory()->create();

    livewire(EditUser::class, ['record' => $user->getKey()])
        ->fillForm(['password' => 'admin-set-password'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Auth::guard('web')->attempt([
        'email' => $user->email,
        'password' => 'admin-set-password',
    ]))->toBeTrue();
});

it('keeps the old password working when the field is left blank', function (): void {
    $user = User::factory()->create(['password' => 'original-password']);

    livewire(EditUser::class, ['record' => $user->getKey()])
        ->fillForm(['name' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Auth::guard('web')->attempt([
        'email' => $user->email,
        'password' => 'original-password',
    ]))->toBeTrue();
});

it('lets a sysadmin-created user authenticate with the password given at creation', function (): void {
    livewire(CreateUser::class)
        ->fillForm([
            'name' => 'Created By Admin',
            'email' => 'created-by-admin@example.test',
            'password' => 'creation-password',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = User::query()->where('email', 'created-by-admin@example.test')->firstOrFail();

    expect($created->password)->not->toBe('creation-password')
        ->and(Auth::guard('web')->attempt([
            'email' => 'created-by-admin@example.test',
            'password' => 'creation-password',
        ]))->toBeTrue();
});

it('exposes a notification toggle for every type and channel', function (): void {
    $user = User::factory()->create();

    $component = livewire(EditUser::class, ['record' => $user->getKey()]);

    foreach (NotificationType::cases() as $type) {
        foreach ($type->channels() as $channel) {
            $component->assertFormFieldExists("notification_preferences.{$type->value}.{$channel->value}");
        }
    }
});

it('hydrates notification toggles from the enum defaults', function (): void {
    $user = User::factory()->create(['notification_preferences' => null]);

    livewire(EditUser::class, ['record' => $user->getKey()])
        ->assertFormSet([
            'notification_preferences' => [
                'task_assigned' => ['in_app' => true, 'email' => false],
                'task_digest' => ['email' => true],
            ],
        ]);
});

it('disables notifications for a single user', function (): void {
    $user = User::factory()->create(['notification_preferences' => null]);

    livewire(EditUser::class, ['record' => $user->getKey()])
        ->fillForm([
            'notification_preferences' => [
                'task_assigned' => ['in_app' => false, 'email' => false],
                'task_digest' => ['email' => false],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $user->refresh();

    expect($user->wantsNotification(NotificationType::TaskAssigned, NotificationChannel::InApp))->toBeFalse()
        ->and($user->wantsNotification(NotificationType::TaskAssigned, NotificationChannel::Email))->toBeFalse()
        ->and($user->wantsNotification(NotificationType::TaskDigest, NotificationChannel::Email))->toBeFalse();
});

it('leaves other users untouched when one user is muted', function (): void {
    $muted = User::factory()->create(['notification_preferences' => null]);
    $other = User::factory()->create(['notification_preferences' => null]);

    livewire(EditUser::class, ['record' => $muted->getKey()])
        ->fillForm([
            'notification_preferences' => [
                'task_assigned' => ['in_app' => false, 'email' => false],
                'task_digest' => ['email' => false],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($other->fresh()->wantsNotification(NotificationType::TaskAssigned, NotificationChannel::InApp))->toBeTrue()
        ->and($other->fresh()->wantsNotification(NotificationType::TaskDigest, NotificationChannel::Email))->toBeTrue();
});
