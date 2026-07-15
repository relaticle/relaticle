<?php

declare(strict_types=1);

use App\Enums\SocialiteProvider;
use App\Filament\Pages\Auth\Register;
use App\Http\Controllers\Auth\CallbackController;
use App\Models\TeamInvitation;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

mutates(Register::class, CallbackController::class);

function makeInvitationSocialiteUser(string $id, string $name, string $email): SocialiteUser
{
    $user = new SocialiteUser;
    $user->id = $id;
    $user->name = $name;
    $user->email = $email;

    return $user;
}

// --- Password registration ---

it('allows password registration by default (invitation_only off)', function (): void {
    config()->set('relaticle.registration.invitation_only', false);

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-default@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'jane-default@gmail.com')->exists())->toBeTrue();
});

it('blocks password registration for an uninvited email when invitation_only is on', function (): void {
    config()->set('relaticle.registration.invitation_only', true);

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-uninvited@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ])
        ->call('register')
        ->assertHasFormErrors(['email']);

    expect(User::where('email', 'jane-uninvited@gmail.com')->exists())->toBeFalse();
});

it('allows password registration for an invited email when invitation_only is on', function (): void {
    config()->set('relaticle.registration.invitation_only', true);

    TeamInvitation::factory()->create(['email' => 'jane-invited@gmail.com']);

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-invited@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'jane-invited@gmail.com')->exists())->toBeTrue();
});

it('blocks password registration when the only invitation is expired', function (): void {
    config()->set('relaticle.registration.invitation_only', true);

    TeamInvitation::factory()->expired()->create(['email' => 'jane-expired@gmail.com']);

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-expired@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ])
        ->call('register')
        ->assertHasFormErrors(['email']);

    expect(User::where('email', 'jane-expired@gmail.com')->exists())->toBeFalse();
});

// --- Social login (Google/GitHub) ---

it('blocks social signup for an uninvited email when invitation_only is on', function (): void {
    config()->set('relaticle.registration.invitation_only', true);

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeInvitationSocialiteUser('123', 'New User', 'new-social@example.com'),
    );

    $response = $this->get(route('auth.socialite.callback', [
        'provider' => SocialiteProvider::GOOGLE->value,
        'code' => 'test-code',
    ]));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['login']);
    $this->assertGuest();
    expect(User::where('email', 'new-social@example.com')->exists())->toBeFalse();
});

it('allows social signup for an invited email when invitation_only is on', function (): void {
    config()->set('relaticle.registration.invitation_only', true);

    TeamInvitation::factory()->create(['email' => 'invited-social@example.com']);

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeInvitationSocialiteUser('456', 'Invited User', 'invited-social@example.com'),
    );

    $this->get(route('auth.socialite.callback', [
        'provider' => SocialiteProvider::GOOGLE->value,
        'code' => 'test-code',
    ]));

    $this->assertAuthenticated();
    expect(User::where('email', 'invited-social@example.com')->exists())->toBeTrue();
});

it('still lets an existing user log in via social when invitation_only is on', function (): void {
    config()->set('relaticle.registration.invitation_only', true);

    $user = User::factory()->withTeam()->create(['email' => 'existing-social@example.com']);

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeInvitationSocialiteUser('789', 'Existing User', 'existing-social@example.com'),
    );

    $this->get(route('auth.socialite.callback', [
        'provider' => SocialiteProvider::GOOGLE->value,
        'code' => 'test-code',
    ]));

    $this->assertAuthenticatedAs($user);
});
