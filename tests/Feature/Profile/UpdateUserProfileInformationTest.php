<?php

declare(strict_types=1);

use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Livewire\App\Profile\UpdateProfileInformation as UpdateProfileInformationComponent;
use App\Models\User;
use App\Support\SameOriginUrl;
use Filament\Auth\Notifications\NoticeOfEmailChangeRequest;
use Filament\Auth\Notifications\VerifyEmailChange;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

mutates(UpdateUserProfileInformation::class, UpdateProfileInformationComponent::class);

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

    test('can update name without changing email', function () {
        $user = User::factory()->withTeam()->create([
            'email' => 'stable@example.com',
            'email_verified_at' => now(),
        ]);
        $this->actingAs($user);

        Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm([
                'name' => 'Updated Name',
                'email' => 'stable@example.com',
            ])
            ->call('updateProfile')
            ->assertHasNoFormErrors()
            ->assertNotified();

        expect($user->fresh())
            ->name->toBe('Updated Name')
            ->email->toBe('stable@example.com')
            ->email_verified_at->not->toBeNull();
    });
});

describe('email change verification', function () {
    beforeEach(function () {
        Notification::fake();

        $this->verifiedUser = User::factory()->withTeam()->create([
            'email' => 'original@example.com',
            'email_verified_at' => now(),
        ]);
        $this->actingAs($this->verifiedUser);
    });

    test('email change does not update email immediately', function () {
        Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm([
                'name' => $this->verifiedUser->name,
                'email' => 'new@example.com',
            ])
            ->call('updateProfile')
            ->assertHasNoFormErrors()
            ->assertNotified();

        expect($this->verifiedUser->fresh())
            ->email->toBe('original@example.com')
            ->email_verified_at->not->toBeNull();
    });

    test('email change sends verification to new email', function () {
        Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm([
                'name' => $this->verifiedUser->name,
                'email' => 'new@example.com',
            ])
            ->call('updateProfile')
            ->assertHasNoFormErrors();

        Notification::assertSentOnDemand(VerifyEmailChange::class);
    });

    test('email change sends notice to old email with block link', function () {
        Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm([
                'name' => $this->verifiedUser->name,
                'email' => 'new@example.com',
            ])
            ->call('updateProfile')
            ->assertHasNoFormErrors();

        Notification::assertSentTo($this->verifiedUser, NoticeOfEmailChangeRequest::class);
    });

    test('same email does not trigger verification', function () {
        Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm([
                'name' => 'New Name',
                'email' => 'original@example.com',
            ])
            ->call('updateProfile')
            ->assertHasNoFormErrors();

        Notification::assertNotSentTo($this->verifiedUser, NoticeOfEmailChangeRequest::class);
        Notification::assertSentOnDemandTimes(VerifyEmailChange::class, 0);
    });

    test('email change resets form email to current value', function () {
        Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm([
                'name' => $this->verifiedUser->name,
                'email' => 'new@example.com',
            ])
            ->call('updateProfile')
            ->assertFormSet([
                'email' => 'original@example.com',
            ]);
    });

    test('name change is saved even when email change is deferred', function () {
        Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm([
                'name' => 'Updated Name',
                'email' => 'new@example.com',
            ])
            ->call('updateProfile')
            ->assertHasNoFormErrors()
            ->assertNotified();

        expect($this->verifiedUser->fresh())
            ->name->toBe('Updated Name')
            ->email->toBe('original@example.com');
    });
});

describe('photo upload', function () {
    beforeEach(fn () => Storage::fake('public'));

    test('action does not error when profile_photo_path key is absent from input', function () {
        $this->action->update($this->user, [
            'name' => 'Renamed Without Photo Key',
            'email' => $this->user->email,
        ]);

        expect($this->user->fresh())
            ->name->toBe('Renamed Without Photo Key')
            ->profile_photo_path->toBeNull();
    });

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

        Notification::assertSentTo($this->user, VerifyEmail::class);
    });

    test('null profile_photo_path does not delete existing photo', function () {
        Storage::fake('public');

        $photo = UploadedFile::fake()->image('avatar.png', 300, 300);
        $photoPath = $photo->storePublicly('profile-photos', ['disk' => 'public']);

        $this->action->update($this->user, [
            'name' => $this->user->name,
            'email' => $this->user->email,
            'profile_photo_path' => $photoPath,
        ]);

        expect($this->user->fresh()->profile_photo_path)->toBe($photoPath);

        $this->action->update($this->user, [
            'name' => $this->user->name,
            'email' => $this->user->email,
            'profile_photo_path' => null,
        ]);

        expect($this->user->fresh()->profile_photo_path)->toBe($photoPath)
            ->and(Storage::disk('public')->exists($photoPath))->toBeTrue();
    });

    test('empty string profile_photo_path does not delete existing photo', function () {
        Storage::fake('public');

        $photo = UploadedFile::fake()->image('avatar.png', 300, 300);
        $photoPath = $photo->storePublicly('profile-photos', ['disk' => 'public']);

        $this->action->update($this->user, [
            'name' => $this->user->name,
            'email' => $this->user->email,
            'profile_photo_path' => $photoPath,
        ]);

        $this->action->update($this->user, [
            'name' => $this->user->name,
            'email' => $this->user->email,
            'profile_photo_path' => '',
        ]);

        expect($this->user->fresh()->profile_photo_path)->toBe($photoPath)
            ->and(Storage::disk('public')->exists($photoPath))->toBeTrue();
    });

    test('removeProfilePhoto livewire method deletes photo and file', function () {
        Storage::fake('public');
        $user = User::factory()->withTeam()->create([
            'email' => 'remove-photo@example.com',
        ]);
        $this->actingAs($user);

        $photo = UploadedFile::fake()->image('avatar.png', 300, 300);
        $photoPath = $photo->storePublicly('profile-photos', ['disk' => 'public']);

        $user->forceFill(['profile_photo_path' => $photoPath])->save();
        expect(Storage::disk('public')->exists($photoPath))->toBeTrue();

        Livewire::test(UpdateProfileInformationComponent::class)
            ->call('removeProfilePhoto')
            ->assertNotified();

        expect($user->fresh()->profile_photo_path)->toBeNull()
            ->and(Storage::disk('public')->exists($photoPath))->toBeFalse();
    });

    test('removeProfilePhoto also clears pending FileUpload state', function () {
        $user = User::factory()->withTeam()->create([
            'email' => 'pending-photo@example.com',
        ]);
        $this->actingAs($user);

        $photo = UploadedFile::fake()->image('avatar.png', 300, 300);
        $photoPath = $photo->storePublicly('profile-photos', ['disk' => 'public']);
        $user->forceFill(['profile_photo_path' => $photoPath])->save();

        $component = Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm(['profile_photo_path' => UploadedFile::fake()->image('pending.png', 200, 200)])
            ->call('removeProfilePhoto')
            ->assertNotified();

        $state = $component->get('data.profile_photo_path');

        expect($state)->toBeIn([null, []])
            ->and($user->fresh()->profile_photo_path)->toBeNull();
    });

    test('can update profile through livewire component with photo', function () {
        Storage::fake('public');
        $user = User::factory()->withTeam()->create([
            'email' => 'photo-test@example.com',
        ]);
        $this->actingAs($user);

        $photo = UploadedFile::fake()->image('avatar.jpg', 200, 200);

        Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm([
                'name' => 'Updated Name',
                'email' => 'photo-test@example.com',
                'profile_photo_path' => $photo,
            ])
            ->call('updateProfile')
            ->assertHasNoFormErrors()
            ->assertNotified();

        expect($user->fresh())
            ->name->toBe('Updated Name')
            ->email->toBe('photo-test@example.com')
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

describe('photo url generation', function () {
    beforeEach(function () {
        Storage::fake('public');

        Route::get('/_test/avatar-url', function () {
            return auth()->user()->getFilamentAvatarUrl();
        })->middleware('web');
    });

    test('avatar url uses current request host instead of APP_URL', function () {
        config(['app.url' => 'https://relaticle.test']);

        $user = User::factory()->create([
            'profile_photo_path' => 'profile-photos/test.png',
        ]);

        $response = $this->actingAs($user)
            ->get('https://app.relaticle.test/_test/avatar-url');

        $response->assertOk();
        expect($response->getContent())->toBe('https://app.relaticle.test/storage/profile-photos/test.png');
    });

    test('avatar url falls back to absolute disk url when request host is localhost', function () {
        config(['app.url' => 'https://relaticle.test']);
        Storage::fake('public', ['url' => 'https://relaticle.test/storage']);

        $user = User::factory()->make([
            'profile_photo_path' => 'profile-photos/test.png',
        ]);

        // Queue workers / scheduler hydrate Request from empty CLI globals, which
        // yields a `localhost` host — the helper must fall back to the disk URL.
        app()->instance('request', Request::create('http://localhost/'));

        expect($user->getFilamentAvatarUrl())
            ->toStartWith('https://relaticle.test/storage/profile-photos/');
    });

    test('SameOriginUrl rewrites disk url to current request host', function () {
        config(['app.url' => 'https://relaticle.test']);

        Route::get('/_test/rewrite-url', fn () => SameOriginUrl::rewrite('https://relaticle.test/storage/profile-photos/x.png'))
            ->middleware('web');

        $response = $this->get('https://app.relaticle.test/_test/rewrite-url');

        $response->assertOk();
        expect($response->getContent())->toBe('https://app.relaticle.test/storage/profile-photos/x.png');
    });

    test('SameOriginUrl leaves external host URLs untouched', function () {
        config(['app.url' => 'https://relaticle.test']);

        Route::get('/_test/external-url', fn () => SameOriginUrl::rewrite('https://my-bucket.s3.amazonaws.com/profile-photos/x.png?X-Amz-Signature=abc'))
            ->middleware('web');

        $response = $this->get('https://app.relaticle.test/_test/external-url');

        $response->assertOk();
        expect($response->getContent())->toBe('https://my-bucket.s3.amazonaws.com/profile-photos/x.png?X-Amz-Signature=abc');
    });

    test('avatar url preserves query string from disk url', function () {
        config(['app.url' => 'https://relaticle.test']);

        $user = User::factory()->create([
            'profile_photo_path' => 'profile-photos/test.png',
        ]);

        Route::get('/_test/avatar-url-query', function () {
            // Force a disk URL that includes a query string (e.g. signed URL style).
            $disk = Mockery::mock(FilesystemAdapter::class);
            $disk->shouldReceive('url')
                ->andReturnUsing(fn (string $path): string => 'https://relaticle.test/storage/'.$path.'?signature=abc123');
            $disk->shouldIgnoreMissing();

            Storage::shouldReceive('disk')->andReturn($disk);

            return auth()->user()->getFilamentAvatarUrl();
        })->middleware('web');

        $response = $this->actingAs($user)
            ->get('https://app.relaticle.test/_test/avatar-url-query');

        $response->assertOk();
        expect($response->getContent())
            ->toBe('https://app.relaticle.test/storage/profile-photos/test.png?signature=abc123');
    });
});
