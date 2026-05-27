<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;

it('renders the team-name banner on login when intended url is a join link', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Acme Co', 'user_id' => $owner->id]);

    session(['url.intended' => route('teams.join', ['token' => $team->invite_link_token])]);

    $this->get('/app/login')
        ->assertOk()
        ->assertSee("You've been invited to join", false)
        ->assertSee('Acme Co');
});

it('renders the team-name banner on register when intended url is a join link', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Acme Co', 'user_id' => $owner->id]);

    session(['url.intended' => route('teams.join', ['token' => $team->invite_link_token])]);

    $this->get('/app/register')
        ->assertOk()
        ->assertSee("You've been invited to join", false)
        ->assertSee('Acme Co');
});

it('does not render the banner when the join token has expired', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Stale Team', 'user_id' => $owner->id]);
    $team->forceFill(['invite_link_token_expires_at' => now()->subDay()])->save();

    session(['url.intended' => route('teams.join', ['token' => $team->invite_link_token])]);

    $this->get('/app/login')
        ->assertOk()
        ->assertDontSee('Stale Team');
});

it('does not render the banner when the intended url is unrelated', function (): void {
    session(['url.intended' => '/dashboard']);

    $this->get('/app/login')
        ->assertOk()
        ->assertDontSee("You've been invited to join", false);
});
