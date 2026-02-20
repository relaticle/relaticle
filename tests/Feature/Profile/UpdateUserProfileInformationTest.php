<?php

declare(strict_types=1);

use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Livewire\App\Profile\UpdateProfileInformation as UpdateProfileInformationComponent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->action = new UpdateUserProfileInformation;
    $this->user = User::factory()->create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'email_verified_at' => now(),
    ]);
});

describe('profile component functionality', function () {
    test('profile information component renders correctly', function () {
        $user = User::factory()->withTeam()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
        $this->actingAs($user);

        Livewire::test(UpdateProfileInformationComponent::class)
            ->assertSuccessful()
            ->assertSee('Profile Information')
            ->assertFormSet([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
    });

    test('can update profile through livewire component', function () {
        $user = User::factory()->withTeam()->create();
        $this->actingAs($user);

        Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm([
                'name' => 'Updated Name',
                'email' => 'updated@example.com',
            ])
            ->call('updateProfile')
            ->assertHasNoFormErrors()
            ->assertNotified();

        expect($user->fresh())
            ->name->toBe('Updated Name')
            ->email->toBe('updated@example.com');
    });
});

describe('photo upload', function () {
    beforeEach(fn () => Storage::fake('public'));

    test('can upload valid photo', function ($format) {
        $photo = UploadedFile::fake()->image("avatar.{$format}", 300, 300);

        // Store the file first (simulating what Filament does)
        $photoPath = $photo->storePublicly('profile-photos', ['disk' => 'public']);

        $this->action->update($this->user, [
            'name' => $this->user->name,
            'email' => $this->user->email,
            'profile_photo_path' => $photoPath,
        ]);

        $user = $this->user->fresh();
        expect($user->profile_photo_path)->toBe($photoPath)
            ->and(Storage::disk('public')->exists($user->profile_photo_path))->toBeTrue();
    })->with(['jpg', 'jpeg', 'png']);

    test('handles photo with email change', function () {
        Notification::fake();
        $photo = UploadedFile::fake()->image('avatar.png', 400, 400);

        // Store the file first (simulating what Filament does)
        $photoPath = $photo->storePublicly('profile-photos', ['disk' => 'public']);

        $this->action->update($this->user, [
            'name' => 'Photo User',
            'email' => 'photouser@example.com',
            'profile_photo_path' => $photoPath,
        ]);

        expect($this->user->fresh())
            ->name->toBe('Photo User')
            ->email->toBe('photouser@example.com')
            ->email_verified_at->toBeNull()
            ->profile_photo_path->toBe($photoPath);

        Notification::assertSentTo($this->user, \Illuminate\Auth\Notifications\VerifyEmail::class);
    });

    test('can delete profile photo', function () {
        Storage::fake('public');

        // First set a photo
        $photo = UploadedFile::fake()->image('avatar.png', 300, 300);
        $photoPath = $photo->storePublicly('profile-photos', ['disk' => 'public']);

        $this->action->update($this->user, [
            'name' => $this->user->name,
            'email' => $this->user->email,
            'profile_photo_path' => $photoPath,
        ]);

        expect($this->user->fresh()->profile_photo_path)->toBe($photoPath);

        // Then delete it
        $this->action->update($this->user, [
            'name' => $this->user->name,
            'email' => $this->user->email,
            'profile_photo_path' => null,
        ]);

        expect($this->user->fresh()->profile_photo_path)->toBeNull();
    });

    test('can update profile through livewire component with photo', function () {
        Storage::fake('public');
        $user = User::factory()->withTeam()->create();
        $this->actingAs($user);

        $photo = UploadedFile::fake()->image('avatar.jpg', 200, 200);

        Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm([
                'name' => 'Updated Name',
                'email' => 'updated@example.com',
                'profile_photo_path' => $photo,
            ])
            ->call('updateProfile')
            ->assertHasNoFormErrors()
            ->assertNotified();

        expect($user->fresh())
            ->name->toBe('Updated Name')
            ->email->toBe('updated@example.com')
            ->profile_photo_path->not->toBeNull();
    });
});

describe('validation', function () {
    test('validates required fields through livewire component', function () {
        $user = User::factory()->withTeam()->create();
        $this->actingAs($user);

        Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm([
                'name' => '',
                'email' => 'invalid-email',
            ])
            ->call('updateProfile')
            ->assertHasFormErrors(['name', 'email']);
    });

    test('rejects duplicate email through livewire component', function () {
        User::factory()->create(['email' => 'existing@example.com']);
        $user = User::factory()->withTeam()->create();
        $this->actingAs($user);

        Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm([
                'name' => 'Valid Name',
                'email' => 'existing@example.com',
            ])
            ->call('updateProfile')
            ->assertHasFormErrors(['email']);
    });
});
