<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\TeamInvitation;
use App\Models\TeamPerson;
use App\Models\User;

mutates(TeamPerson::class);

test('the unified query returns members and invitations with distinct keys', function (): void {
    $owner = User::factory()->withTeam()->create(['name' => 'Ana Reyes']);
    $team = $owner->currentTeam;

    $team->teamInvitations()->create([
        'email' => 'pending@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $rows = TeamPerson::forTeam($team)->get();

    expect($rows)->toHaveCount(2);

    $member = $rows->firstWhere('status', 'member');
    $invited = $rows->firstWhere('status', 'invited');

    expect($member->name)->toBe('Ana Reyes')
        ->and($member->email)->toBe($owner->email)
        ->and($member->user_id)->toBe($owner->id)
        ->and($member->id)->toStartWith('member:')
        ->and($invited->name)->toBeNull()
        ->and($invited->email)->toBe('pending@example.test')
        ->and($invited->user_id)->toBeNull()
        ->and($invited->id)->toStartWith('invite:');
});

test('the unified query is scoped to one team', function (): void {
    $team = User::factory()->withTeam()->create()->currentTeam;
    $otherTeam = User::factory()->withTeam()->create()->currentTeam;

    $otherTeam->teamInvitations()->create([
        'email' => 'elsewhere@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    expect(TeamPerson::forTeam($team)->pluck('email'))
        ->not->toContain('elsewhere@example.test');
});

test('an invitation row surfaces expires_at while a member row leaves it null', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $expiresAt = now()->addDays(5);

    $team->teamInvitations()->create([
        'email' => 'pending@example.test',
        'role' => 'editor',
        'expires_at' => $expiresAt,
    ]);

    $rows = TeamPerson::forTeam($team)->get();

    $member = $rows->firstWhere('status', 'member');
    $invited = $rows->firstWhere('status', 'invited');

    expect($member->expires_at)->toBeNull()
        ->and($invited->expires_at)->not->toBeNull()
        ->and($invited->expires_at->timestamp)->toBe($expiresAt->timestamp);
});

test('source_id round-trips to the underlying membership or invitation', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $attachedMember = User::factory()->create();
    $team->users()->attach($attachedMember, ['role' => TeamRole::Editor->value]);

    $invitation = $team->teamInvitations()->create([
        'email' => 'pending@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $rows = TeamPerson::forTeam($team)->get();

    $memberRow = $rows->firstWhere('user_id', $attachedMember->id);
    $invitedRow = $rows->firstWhere('status', 'invited');

    $membership = Membership::query()->find($memberRow->source_id);
    $foundInvitation = TeamInvitation::query()->find($invitedRow->source_id);

    expect($memberRow->id)->toStartWith('member:')
        ->and($membership)->not->toBeNull()
        ->and($membership->user_id)->toBe($attachedMember->id)
        ->and($foundInvitation)->not->toBeNull()
        ->and($foundInvitation->id)->toBe($invitation->id);
});

test('ordering by happened_at sorts member and invitation rows together', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $this->travelTo(now()->addMinute());
    $attachedMember = User::factory()->create();
    $team->users()->attach($attachedMember, ['role' => TeamRole::Editor->value]);

    $this->travelTo(now()->addMinutes(2));
    $team->teamInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $ordered = TeamPerson::forTeam($team)->orderBy('happened_at')->pluck('email');

    expect($ordered->toArray())->toBe([
        $owner->email,
        $attachedMember->email,
        'later@example.test',
    ]);
});
