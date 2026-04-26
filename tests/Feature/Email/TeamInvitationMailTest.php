<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Laravel\Jetstream\Mail\TeamInvitation as TeamInvitationMail;

it('renders exactly one Accept Invitation CTA in the body', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Acme Co', 'user_id' => $owner->id]);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'guest@example.com',
        'role' => 'editor',
    ]);

    $rendered = (new TeamInvitationMail($invitation))->render();

    expect(substr_count($rendered, 'Accept Invitation'))->toBe(1);
});

it('does not contain a Create Account button', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'guest@example.com',
        'role' => 'editor',
    ]);

    $rendered = (new TeamInvitationMail($invitation))->render();

    expect($rendered)->not->toContain('Create Account');
});

it('mentions the team name and the expires-in phrase when expires_at is set', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Acme Co', 'user_id' => $owner->id]);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'guest@example.com',
        'role' => 'editor',
        'expires_at' => now()->addDays(7),
    ]);

    $rendered = (new TeamInvitationMail($invitation))->render();

    expect($rendered)->toContain('Acme Co')
        ->and($rendered)->toContain('expires');
});

it('omits the expiry phrase when expires_at is null', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'guest@example.com',
        'role' => 'editor',
        'expires_at' => null,
    ]);

    $rendered = (new TeamInvitationMail($invitation))->render();

    expect($rendered)->not->toContain('expires');
});

it('renders a valid signed accept URL as the CTA target', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'guest@example.com',
        'role' => 'editor',
    ]);

    $rendered = (new TeamInvitationMail($invitation))->render();

    expect($rendered)->toContain('/team-invitations/'.$invitation->id)
        ->and($rendered)->toContain('signature=');
});
