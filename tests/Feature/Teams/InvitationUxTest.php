<?php

declare(strict_types=1);

use App\Enums\SubscriberTagEnum;
use App\Filament\Pages\Auth\Register;
use App\Jobs\Email\SyncSubscriberJob;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

test('guest with no account clicking invitation link is redirected to register page', function () {
    $team = Team::factory()->create(['name' => 'Acme Corp']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'newuser@example.com',
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->get($acceptUrl)
        ->assertRedirect(Filament::getRegistrationUrl());
});

test('guest with existing account clicking invitation link is redirected to login page', function () {
    $team = Team::factory()->create(['name' => 'Acme Corp']);

    User::factory()->create(['email' => 'existing@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'existing@example.com',
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->get($acceptUrl)
        ->assertRedirect(Filament::getLoginUrl());
});

test('guest clicking invitation link sees team name and sign-up link on login page', function () {
    $team = Team::factory()->create(['name' => 'Acme Corp']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'newuser@example.com',
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->get($acceptUrl);

    $this->get(route('filament.app.auth.login'))
        ->assertSee('Acme Corp')
        ->assertSee('sign up', escape: false);
});

test('login page without invitation shows default subheading unchanged', function () {
    $this->get(route('filament.app.auth.login'))
        ->assertSee('sign up', escape: false)
        ->assertDontSee('invited to join');
});

test('guest clicking invitation link sees team name and sign-in link on register page', function () {
    $team = Team::factory()->create(['name' => 'Acme Corp']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'newuser@example.com',
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->get($acceptUrl)
        ->assertRedirect(Filament::getRegistrationUrl());

    $this->get(route('filament.app.auth.register'))
        ->assertSee('Acme Corp')
        ->assertSee('sign in', escape: false);
});

test('register page without invitation shows default subheading unchanged', function () {
    $this->get(route('filament.app.auth.register'))
        ->assertSee('sign in', escape: false)
        ->assertDontSee('invited to join');
});

test('user registering via invitation link gets auto-verified email', function () {
    $team = Team::factory()->create();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'newuser@gmail.com',
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    // Simulate guest hitting invite link (sets url.intended in session)
    $this->get($acceptUrl);

    // Register via Filament's Livewire component
    livewire(Register::class)
        ->fillForm([
            'name' => 'New User',
            'email' => 'newuser@gmail.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'newuser@gmail.com')->first();
    expect($user)->not->toBeNull();
    expect($user->hasVerifiedEmail())->toBeTrue();
});

test('user registering without invitation link does not get auto-verified', function () {
    livewire(Register::class)
        ->fillForm([
            'name' => 'Normal User',
            'email' => 'normaluser@gmail.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'normaluser@gmail.com')->first();
    expect($user)->not->toBeNull();
    expect($user->hasVerifiedEmail())->toBeFalse();
});

test('user registering with different email than invitation does not get auto-verified', function () {
    $team = Team::factory()->create();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@gmail.com',
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->get($acceptUrl);

    livewire(Register::class)
        ->fillForm([
            'name' => 'Different User',
            'email' => 'different@gmail.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'different@gmail.com')->first();
    expect($user)->not->toBeNull();
    expect($user->hasVerifiedEmail())->toBeFalse();
});

test('user registering via invitation link gets mailcoach subscriber synced', function (): void {
    Queue::fake([SyncSubscriberJob::class]);
    config()->set('mailcoach-sdk.enabled_subscribers_sync', true);
    config()->set('mailcoach-sdk.subscribers_list_id', 'test-list-id');

    $team = Team::factory()->create();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@gmail.com',
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->get($acceptUrl);

    livewire(Register::class)
        ->fillForm([
            'name' => 'Invited User',
            'email' => 'invited@gmail.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'invited@gmail.com')->first();
    expect($user)->not->toBeNull();
    expect($user->hasVerifiedEmail())->toBeTrue();

    Queue::assertPushed(SyncSubscriberJob::class, function (SyncSubscriberJob $job) use ($user): bool {
        $data = invade($job)->data;

        return $data->email === $user->email
            && in_array(SubscriberTagEnum::Verified->value, $data->tags, true)
            && in_array(SubscriberTagEnum::SignupSourceOrganic->value, $data->tags, true)
            && $data->user_id === (string) $user->id;
    });
});

test('user registering without invitation does not trigger subscriber sync', function (): void {
    Queue::fake([SyncSubscriberJob::class]);
    config()->set('mailcoach-sdk.enabled_subscribers_sync', true);
    config()->set('mailcoach-sdk.subscribers_list_id', 'test-list-id');

    livewire(Register::class)
        ->fillForm([
            'name' => 'Normal User',
            'email' => 'noninvited@gmail.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'noninvited@gmail.com')->first();
    expect($user)->not->toBeNull();
    expect($user->hasVerifiedEmail())->toBeFalse();

    Queue::assertNotPushed(SyncSubscriberJob::class);
});
