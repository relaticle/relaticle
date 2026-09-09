<?php

declare(strict_types=1);

use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Actions\Profile\RequestEmailChange;
use App\Enums\SocialiteProvider;
use App\Livewire\App\Profile\UpdateProfileInformation as UpdateProfileInformationComponent;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Notifications\Auth\NoticeOfEmailChangeRequest;
use App\Notifications\Auth\VerifyEmailChange;
use App\Support\Auth\AuthenticationSession;
use App\Support\SameOriginUrl;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Passkey;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(UpdateUserProfileInformation::class, UpdateProfileInformationComponent::class, RequestEmailChange::class);

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

    test('a direct profile submission cannot issue an email change without fresh identity proof', function (): void {
        session()->put('auth.password_confirmed_at', time() - 60);

        Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm(['email' => 'new@example.com'])
            ->call('updateProfile');

        Notification::assertNothingSent();
        expect($this->verifiedUser->fresh()->email)->toBe('original@example.com');
    });

    test('email change does not update email immediately', function () {
        Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm([
                'name' => $this->verifiedUser->name,
                'email' => 'new@example.com',
            ])
            ->call('updateProfile')
            ->setActionData(['password' => 'password'])
            ->callMountedAction()
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
            ->setActionData(['password' => 'password'])
            ->callMountedAction()
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
            ->setActionData(['password' => 'password'])
            ->callMountedAction()
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
            ->setActionData(['password' => 'password'])
            ->callMountedAction()
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
            ->setActionData(['password' => 'password'])
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertNotified();

        expect($this->verifiedUser->fresh())
            ->name->toBe('Updated Name')
            ->email->toBe('original@example.com');
    });

    test('email confirmation keeps the normalized target when the form changes afterward', function (): void {
        Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm(['email' => '  New@Example.com  '])
            ->call('updateProfile')
            ->assertActionMounted('confirmEmailChange')
            ->assertMountedActionModalSee('new@example.com')
            ->set('data.email', 'different@example.com')
            ->setActionData(['password' => 'password'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        Notification::assertSentOnDemand(VerifyEmailChange::class,
            fn (VerifyEmailChange $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'new@example.com');
        expect(AuthenticationSession::pendingOperation())->toBe([]);
    });

    test('an enrolled user must prove MFA before requesting an email change', function (): void {
        $user = User::factory()->withTeam()->withConfirmedMfa()->create();
        $this->actingAs($user);
        AuthenticationSession::markComplete($user);
        $component = Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm(['email' => 'new@example.com'])
            ->call('updateProfile')
            ->setActionData(['password' => 'password'])
            ->callMountedAction()
            ->assertHasActionErrors(['code']);

        Notification::assertNothingSent();

        $component->setActionData([
            'password' => 'password',
            'use_recovery_code' => true,
            'recovery_code' => 'recovery-code-one',
        ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        Notification::assertSentOnDemand(VerifyEmailChange::class);
        expect($user->fresh()->recoveryCodes())->not->toContain('recovery-code-one');
    });

    test('a passkey user requests the email change after completing the confirmation ceremony', function (): void {
        $user = User::factory()->withTeam()->create(['password' => null]);
        $this->actingAs($user);
        Passkey::create([
            'user_id' => $user->id,
            'name' => 'MacBook Pro',
            'credential_id' => 'email-change-passkey',
            'credential' => [],
        ]);
        $component = Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm(['email' => 'new@example.com'])
            ->call('updateProfile')
            ->callMountedAction()
            ->assertDispatched('confirm-identity-ceremony');

        Notification::assertNothingSent();
        AuthenticationSession::proveOperation($user, 'change_email', 'new@example.com');

        $component->callMountedAction()->assertHasNoActionErrors();

        Notification::assertSentOnDemand(VerifyEmailChange::class);
    });

    test('a provider-only user resumes the email change after returning from confirmation', function (): void {
        $user = User::factory()->withTeam()->socialOnly()->create();
        $this->actingAs($user);
        $account = UserSocialAccount::factory()->create([
            'user_id' => $user->id,
            'provider_name' => SocialiteProvider::GOOGLE->value,
        ]);
        Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm(['email' => 'new@example.com'])
            ->call('updateProfile');
        $this->get(route('auth.socialite.confirm.redirect', ['provider' => 'google']))->assertRedirect();
        Socialite::fake('google', (new SocialiteUser)->map([
            'id' => $account->provider_id,
            'email' => $user->email,
        ]));
        $this->get(route('auth.socialite.confirm.callback', ['provider' => 'google', 'code' => 'accepted']))
            ->assertRedirect();

        Livewire::test(UpdateProfileInformationComponent::class)
            ->assertFormSet(['email' => 'new@example.com'])
            ->call('updateProfile')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        Notification::assertSentOnDemand(VerifyEmailChange::class);
        expect(AuthenticationSession::pendingOperation())->toBe([]);
    });

    test('the verified email link applies the confirmed change only once', function (): void {
        Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm(['email' => 'new@example.com'])
            ->call('updateProfile')
            ->setActionData(['password' => 'password'])
            ->callMountedAction();
        /** @var VerifyEmailChange $verification */
        $verification = Notification::sent(new AnonymousNotifiable, VerifyEmailChange::class)->sole();

        $this->get($verification->url)->assertRedirect();

        expect($this->verifiedUser->fresh()->email)->toBe('new@example.com')
            ->and($this->verifiedUser->fresh()->email_verified_at)->not->toBeNull();
        $this->get($verification->url)->assertForbidden();
    });

    test('the old address can block a confirmed email change', function (): void {
        Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm(['email' => 'new@example.com'])
            ->call('updateProfile')
            ->setActionData(['password' => 'password'])
            ->callMountedAction();
        /** @var NoticeOfEmailChangeRequest $notice */
        $notice = Notification::sent($this->verifiedUser, NoticeOfEmailChangeRequest::class)->sole();
        /** @var VerifyEmailChange $verification */
        $verification = Notification::sent(new AnonymousNotifiable, VerifyEmailChange::class)->sole();

        $this->get($notice->blockVerificationUrl)->assertRedirect();
        $this->get($verification->url)->assertForbidden();

        expect($this->verifiedUser->fresh()->email)->toBe('original@example.com');
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

    test('refuses a photo update that also swaps the email', function () {
        Notification::fake();
        $photo = UploadedFile::fake()->image('avatar.png', 400, 400);

        $photoPath = $photo->storePublicly('profile-photos', ['disk' => 'public']);

        expect(fn () => $this->action->update($this->user, [
            'name' => 'Photo User',
            'email' => 'photouser@example.com',
            'profile_photo_path' => $photoPath,
        ]))->toThrow(HttpException::class);

        expect($this->user->fresh())
            ->email->toBe('john@example.com')
            ->email_verified_at->not->toBeNull();

        Notification::assertNothingSent();
    });

    test('handles a photo update that keeps the email', function () {
        Notification::fake();
        $photo = UploadedFile::fake()->image('avatar.png', 400, 400);

        $photoPath = $photo->storePublicly('profile-photos', ['disk' => 'public']);

        $this->action->update($this->user, [
            'name' => 'Photo User',
            'email' => $this->user->email,
            'profile_photo_path' => $photoPath,
        ]);

        expect($this->user->fresh())
            ->name->toBe('Photo User')
            ->email->toBe('john@example.com')
            ->profile_photo_path->toBe($photoPath);
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
        })->middleware(['web', 'auth']);
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
        // yields a `localhost` host, so the helper must fall back to the disk URL.
        app()->instance('request', Request::create('http://localhost/'));

        expect($user->getFilamentAvatarUrl())
            ->toStartWith('https://relaticle.test/storage/profile-photos/');
    });

    test('SameOriginUrl rewrites disk url to current request host', function () {
        config(['app.url' => 'https://relaticle.test']);

        Route::get('/_test/rewrite-url', fn () => SameOriginUrl::rewrite('https://relaticle.test/storage/profile-photos/x.png'))
            ->middleware(['web', 'auth']);

        $response = $this->actingAs(User::factory()->create())
            ->get('https://app.relaticle.test/_test/rewrite-url');

        $response->assertOk();
        expect($response->getContent())->toBe('https://app.relaticle.test/storage/profile-photos/x.png');
    });

    test('SameOriginUrl leaves external host URLs untouched', function () {
        config(['app.url' => 'https://relaticle.test']);

        Route::get('/_test/external-url', fn () => SameOriginUrl::rewrite('https://my-bucket.s3.amazonaws.com/profile-photos/x.png?X-Amz-Signature=abc'))
            ->middleware(['web', 'auth']);

        $response = $this->actingAs(User::factory()->create())
            ->get('https://app.relaticle.test/_test/external-url');

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
        })->middleware(['web', 'auth']);

        $response = $this->actingAs($user)
            ->get('https://app.relaticle.test/_test/avatar-url-query');

        $response->assertOk();
        expect($response->getContent())
            ->toBe('https://app.relaticle.test/storage/profile-photos/test.png?signature=abc123');
    });
});

describe('timezone', function () {
    test('form is prefilled with the stored timezone', function () {
        $user = User::factory()->withTeam()->create(['timezone' => 'Asia/Tokyo']);
        $this->actingAs($user);

        Livewire::test(UpdateProfileInformationComponent::class)
            ->assertFormSet(['timezone' => 'Asia/Tokyo']);
    });

    test('can set a timezone through the component', function () {
        $user = User::factory()->withTeam()->create([
            'email' => 'tz@example.com',
            'timezone' => null,
        ]);
        $this->actingAs($user);

        Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm([
                'name' => $user->name,
                'email' => 'tz@example.com',
                'timezone' => 'America/New_York',
            ])
            ->call('updateProfile')
            ->assertHasNoFormErrors();

        expect($user->fresh()->timezone)->toBe('America/New_York');
    });

    test('clearing the select writes null so the app default applies again', function () {
        $user = User::factory()->withTeam()->create([
            'email' => 'tz-clear@example.com',
            'timezone' => 'Asia/Tokyo',
        ]);
        $this->actingAs($user);

        Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm([
                'name' => $user->name,
                'email' => 'tz-clear@example.com',
                'timezone' => null,
            ])
            ->call('updateProfile')
            ->assertHasNoFormErrors();

        expect($user->fresh()->timezone)->toBeNull();
    });

    test('timezone survives a deferred email change', function () {
        Notification::fake();

        $user = User::factory()->withTeam()->create([
            'email' => 'tz-email@example.com',
            'email_verified_at' => now(),
            'timezone' => null,
        ]);
        $this->actingAs($user);

        Livewire::test(UpdateProfileInformationComponent::class)
            ->fillForm([
                'name' => $user->name,
                'email' => 'tz-changed@example.com',
                'timezone' => 'Europe/Lisbon',
            ])
            ->call('updateProfile')
            ->assertHasNoFormErrors();

        expect($user->fresh())
            ->timezone->toBe('Europe/Lisbon')
            ->email->toBe('tz-email@example.com');
    });

    test('rejects an identifier that is not a real timezone', function () {
        $user = User::factory()->withTeam()->create(['timezone' => 'Asia/Tokyo']);

        expect(fn () => $this->action->update($user, [
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => 'Mars/Olympus_Mons',
        ]))->toThrow(ValidationException::class);

        expect($user->fresh()->timezone)->toBe('Asia/Tokyo');
    });

    test('action leaves the timezone untouched when the key is absent from input', function () {
        $user = User::factory()->withTeam()->create(['timezone' => 'Asia/Tokyo']);

        $this->action->update($user, [
            'name' => 'Renamed Without Timezone Key',
            'email' => $user->email,
        ]);

        expect($user->fresh())
            ->name->toBe('Renamed Without Timezone Key')
            ->timezone->toBe('Asia/Tokyo');
    });
});

describe('the raw profile-information route', function () {
    test('a direct request cannot change the account email without an identity proof', function () {
        $user = User::factory()->withTeam()->create([
            'email' => 'owner@example.com',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->putJson(route('user-profile-information.update'), [
                'name' => $user->name,
                'email' => 'attacker@example.com',
            ])
            ->assertStatus(423);

        expect($user->fresh())
            ->email->toBe('owner@example.com')
            ->email_verified_at->not->toBeNull();
    });

    test('a direct request may still update non-credential fields', function () {
        $user = User::factory()->withTeam()->create([
            'name' => 'Original Name',
            'email' => 'owner@example.com',
        ]);

        $this->actingAs($user)
            ->putJson(route('user-profile-information.update'), [
                'name' => 'Renamed Over Http',
                'email' => 'owner@example.com',
            ])
            ->assertSuccessful();

        expect($user->fresh())
            ->name->toBe('Renamed Over Http')
            ->email->toBe('owner@example.com');
    });
});
