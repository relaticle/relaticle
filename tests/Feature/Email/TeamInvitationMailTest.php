<?php

declare(strict_types=1);

use App\Mail\TeamInvitationMail;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Str;

mutates(TeamInvitationMail::class);

it('renders exactly one Accept Invitation CTA in the body', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Acme Co', 'user_id' => $owner->id]);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'guest@example.com',
        'role' => 'editor',
    ]);
    $rawToken = $invitation->issueToken();

    $rendered = (new TeamInvitationMail($invitation, $rawToken))->render();

    expect(substr_count($rendered, __('teams.mail.invitation.action')))->toBe(1);
});

it('does not contain a Create Account button', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'guest@example.com',
        'role' => 'editor',
    ]);
    $rawToken = $invitation->issueToken();

    $rendered = (new TeamInvitationMail($invitation, $rawToken))->render();

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
    $rawToken = Str::random(40);

    $rendered = (new TeamInvitationMail($invitation, $rawToken))->render();

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
    $rawToken = Str::random(40);

    $rendered = (new TeamInvitationMail($invitation, $rawToken))->render();

    expect($rendered)->not->toContain('expires');
});

it('renders an accept URL for the token route containing the raw token, not the hash', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'guest@example.com',
        'role' => 'editor',
    ]);
    $rawToken = $invitation->issueToken();
    $invitation->save();

    $rendered = (new TeamInvitationMail($invitation, $rawToken))->render();

    expect($rendered)->toContain(route('team-invitations.token.accept', ['token' => $rawToken]))
        ->and($rendered)->not->toContain((string) $invitation->token)
        ->and(TeamInvitation::findByRawToken($rawToken)?->is($invitation))->toBeTrue();
});

it('names the inviter in the subject and body when the invitation has an inviter', function (): void {
    $owner = User::factory()->create(['name' => 'Ana Reyes']);
    $team = Team::factory()->create(['name' => 'Acme Co', 'user_id' => $owner->id]);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'guest@example.com',
        'role' => 'editor',
        'inviter_id' => $owner->id,
    ]);
    $rawToken = $invitation->issueToken();

    $mail = new TeamInvitationMail($invitation, $rawToken);

    expect($mail->envelope()->subject)->toBe(
        __('teams.mail.invitation.subject', ['inviter' => 'Ana Reyes', 'team' => 'Acme Co'])
    )->and($mail->render())->toContain(
        __('teams.mail.invitation.line_with_inviter', ['inviter' => 'Ana Reyes', 'team' => 'Acme Co', 'role' => 'Editor'])
    );
});

it('falls back to team-only subject and body copy when the invitation has no inviter', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Acme Co', 'user_id' => $owner->id]);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'guest@example.com',
        'role' => 'editor',
        'inviter_id' => null,
    ]);
    $rawToken = $invitation->issueToken();

    $mail = new TeamInvitationMail($invitation, $rawToken);

    expect($invitation->inviter_id)->toBeNull()
        ->and($mail->envelope()->subject)->toBe(
            __('teams.mail.invitation.subject_without_inviter', ['team' => 'Acme Co'])
        )
        ->and($mail->render())->toContain(
            __('teams.mail.invitation.line', ['team' => 'Acme Co', 'role' => 'Editor'])
        );
});
