<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Login;
use App\Jobs\Email\SyncSubscriberJob;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

mutates(Login::class);

test('guest clicking a team invitation link is redirected to the login page', function (bool $accountExists): void {
    $team = Team::factory()->create(['name' => 'Acme Corp']);
    $email = $accountExists ? 'existing@example.com' : 'newuser@example.com';

    if ($accountExists) {
        User::factory()->create(['email' => $email]);
    }

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $email,
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->get($acceptUrl)
        ->assertRedirect(Filament::getLoginUrl());
})->with([
    'no existing account' => false,
    'existing account' => true,
]);

test('guest clicking invitation link sees the team name banner on the login page', function () {
    $team = Team::factory()->create(['name' => 'Acme Corp']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'newuser@example.com',
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->get($acceptUrl);

    $this->get(route('filament.app.auth.login'))
        ->assertSee('Acme Corp');
});

test('login page without an invitation shows no invitation banner', function () {
    $this->get(route('filament.app.auth.login'))
        ->assertDontSee('invited to join');
});

test('signing up with a different email than the invitation does not get auto-verified', function () {
    $team = Team::factory()->create();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@gmail.com',
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->get($acceptUrl);

    livewire(Login::class)
        ->fillForm(['email' => 'different@gmail.com'])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $user = User::where('email', 'different@gmail.com')->first();
    expect($user)->not->toBeNull();
    expect($user->hasVerifiedEmail())->toBeFalse();
});

test('a mixed-case invitation still auto-verifies the signup for that mailbox', function (): void {
    $team = Team::factory()->create();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'Invited-Case@Gmail.com',
    ]);

    expect($invitation->refresh()->email)->toBe('invited-case@gmail.com');

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->get($acceptUrl);

    livewire(Login::class)
        ->fillForm(['email' => 'INVITED-CASE@GMAIL.COM'])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $user = User::where('email', 'invited-case@gmail.com')->first();
    expect($user)->not->toBeNull();
    expect($user->hasVerifiedEmail())->toBeTrue();
});

test('signing up via an invitation link gets a mailcoach subscriber synced', function (): void {
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

    livewire(Login::class)
        ->fillForm(['email' => 'invited@gmail.com'])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $user = User::where('email', 'invited@gmail.com')->first();
    expect($user)->not->toBeNull();
    expect($user->hasVerifiedEmail())->toBeTrue();

    Queue::assertPushed(SyncSubscriberJob::class, fn (SyncSubscriberJob $job): bool => invade($job)->userId === (string) $user->id);
});

test('signing up without an invitation does not trigger subscriber sync', function (): void {
    Queue::fake([SyncSubscriberJob::class]);
    config()->set('mailcoach-sdk.enabled_subscribers_sync', true);
    config()->set('mailcoach-sdk.subscribers_list_id', 'test-list-id');

    livewire(Login::class)
        ->fillForm(['email' => 'noninvited@gmail.com'])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $user = User::where('email', 'noninvited@gmail.com')->first();
    expect($user)->not->toBeNull();
    expect($user->hasVerifiedEmail())->toBeFalse();

    Queue::assertNotPushed(SyncSubscriberJob::class);
});

test('login page prefills the invited email address', function (): void {
    $team = Team::factory()->create(['name' => 'Acme Corp']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited.person@example.com',
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->get($acceptUrl)->assertRedirect(Filament::getLoginUrl());

    livewire(Login::class)
        ->assertSuccessful()
        ->assertFormSet(['email' => 'invited.person@example.com']);
});

test('login page without an invitation leaves the email blank', function (): void {
    livewire(Login::class)
        ->assertSuccessful()
        ->assertFormSet(['email' => null]);
});
