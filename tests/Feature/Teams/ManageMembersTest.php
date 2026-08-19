<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Livewire\App\Teams\ManageMembers;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

mutates(ManageMembers::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withTeam()->create();
    $this->team = $this->owner->currentTeam;
    $this->actingAs($this->owner);
    Filament::setTenant($this->team);
});

/**
 * `Team::users()` never calls `withPivot('id')`, so the pivot accessor's `id`
 * attribute is always null — the team_user.id backing a `member:<id>` key must
 * be read from the Membership model directly instead.
 */
function memberKey(Team $team, User $user): string
{
    return 'member:'.Membership::query()
        ->where('team_id', $team->id)
        ->where('user_id', $user->id)
        ->firstOrFail()
        ->id;
}

test('members and pending invitations appear in one list', function (): void {
    $this->team->teamInvitations()->create([
        'email' => 'pending@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    livewire(ManageMembers::class, ['team' => $this->team])
        ->assertSee($this->owner->email)
        ->assertSee('pending@example.test');
});

test('the owner row offers no leave action', function (): void {
    livewire(ManageMembers::class, ['team' => $this->team])
        ->assertTableActionHidden('leaveTeam', 'member:owner');
});

test('the owner row offers no remove action', function (): void {
    livewire(ManageMembers::class, ['team' => $this->team])
        ->assertTableActionHidden('removeTeamMember', 'member:owner');
});

test('the owner row offers no role change action', function (): void {
    livewire(ManageMembers::class, ['team' => $this->team])
        ->assertTableActionHidden('updateTeamRole', 'member:owner');
});

test('a member can be removed', function (): void {
    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => TeamRole::Editor->value]);

    $key = memberKey($this->team, $member);

    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('removeTeamMember')->table($key));

    expect($member->fresh()->belongsToTeam($this->team))->toBeFalse();
});

test('an admin cannot promote another member to admin', function (): void {
    $admin = User::factory()->create();
    $this->team->users()->attach($admin, ['role' => TeamRole::Admin->value]);

    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => TeamRole::Editor->value]);

    $this->actingAs($admin);

    $key = memberKey($this->team, $member);

    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('updateTeamRole')->table($key), ['role' => TeamRole::Admin->value])
        ->assertHasActionErrors(['role']);

    expect($member->fresh()->teamRole($this->team)->key)->toBe(TeamRole::Editor->value);
});

test('an admin cannot demote a peer admin through the merged table', function (): void {
    $adminA = User::factory()->create();
    $this->team->users()->attach($adminA, ['role' => TeamRole::Admin->value]);

    $adminB = User::factory()->create();
    $this->team->users()->attach($adminB, ['role' => TeamRole::Admin->value]);

    $this->actingAs($adminA);

    $key = memberKey($this->team, $adminB);

    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('updateTeamRole')->table($key), ['role' => TeamRole::Editor->value])
        ->assertHasActionErrors(['role']);

    expect($adminB->fresh()->teamRole($this->team)->key)->toBe(TeamRole::Admin->value);
});

test('a pending invitation can be revoked', function (): void {
    $invitation = $this->team->teamInvitations()->create([
        'email' => 'pending@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('revokeTeamInvitation')->table('invite:'.$invitation->id));

    expect($this->team->fresh()->teamInvitations)->toHaveCount(0);
});

test('multiple people can be invited in one submission', function (): void {
    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('invitePeople')->table(), [
            'invites' => [
                ['email' => 'one@example.test', 'role' => TeamRole::Editor->value],
                ['email' => 'two@example.test', 'role' => TeamRole::Viewer->value],
            ],
        ]);

    expect($this->team->fresh()->teamInvitations->pluck('email')->all())
        ->toEqualCanonicalizing(['one@example.test', 'two@example.test']);
});

test('invitePeople rejects an admin role for a non-owner actor', function (): void {
    $admin = User::factory()->create();
    $this->team->users()->attach($admin, ['role' => TeamRole::Admin->value]);
    $this->actingAs($admin);

    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('invitePeople')->table(), [
            'invites' => [['email' => 'nope@example.test', 'role' => TeamRole::Admin->value]],
        ])
        ->assertHasActionErrors();

    expect($this->team->fresh()->teamInvitations)->toHaveCount(0);
});

/**
 * Ported from the deleted TeamMembersTest when the three member widgets merged
 * into this component (main #508). Production is missing the team_user foreign
 * keys, so a deleted account can leave an orphaned pivot row behind; the old
 * page 500'd on it because Filament::getUserAvatarUrl() is typed non-nullable.
 * TeamPerson inner-joins users, so orphans are excluded structurally rather
 * than by a whereHas filter — this pins that they stay excluded.
 */
test('the unified list skips a membership row whose user no longer exists', function (): void {
    Schema::table('team_user', function (Blueprint $table): void {
        $table->dropForeign(['user_id']);
    });

    $member = User::factory()->create();
    $deletedUser = User::factory()->create();

    $this->team->users()->attach([
        $member->id => ['role' => TeamRole::Editor->value],
        $deletedUser->id => ['role' => TeamRole::Editor->value],
    ]);

    $deletedEmail = $deletedUser->email;
    $deletedUser->delete();

    livewire(ManageMembers::class, ['team' => $this->team])
        ->assertSee($member->email)
        ->assertDontSee($deletedEmail);
});

test('a crafted payload above the batch cap is rejected server-side', function (): void {
    $invites = collect(range(1, 11))
        ->map(fn (int $i): array => ['email' => "batch{$i}@example.test", 'role' => TeamRole::Editor->value])
        ->all();

    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('invitePeople')->table(), ['invites' => $invites])
        ->assertHasActionErrors();

    expect($this->team->fresh()->teamInvitations)->toHaveCount(0);
});

test('a submission exactly at the batch cap succeeds', function (): void {
    Mail::fake();

    $invites = collect(range(1, 10))
        ->map(fn (int $i): array => ['email' => "atcap{$i}@example.test", 'role' => TeamRole::Editor->value])
        ->all();

    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('invitePeople')->table(), ['invites' => $invites])
        ->assertHasNoActionErrors();

    expect($this->team->fresh()->teamInvitations)->toHaveCount(10);
});

test('cumulative invite volume beyond the window cap is throttled, not just the call count', function (): void {
    Mail::fake();

    $firstBatch = collect(range(1, 10))
        ->map(fn (int $i): array => ['email' => "first{$i}@example.test", 'role' => TeamRole::Editor->value])
        ->all();
    $secondBatch = collect(range(1, 10))
        ->map(fn (int $i): array => ['email' => "second{$i}@example.test", 'role' => TeamRole::Editor->value])
        ->all();

    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('invitePeople')->table(), ['invites' => $firstBatch]);

    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('invitePeople')->table(), ['invites' => $secondBatch]);

    expect($this->team->fresh()->teamInvitations)->toHaveCount(20);

    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('invitePeople')->table(), [
            'invites' => [['email' => 'onemore@example.test', 'role' => TeamRole::Editor->value]],
        ]);

    expect($this->team->fresh()->teamInvitations)->toHaveCount(20);
});

test('the owner can change the invite link default role', function (): void {
    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('manageInviteLink')->table(), [
            'invite_link_default_role' => TeamRole::Viewer->value,
        ]);

    expect($this->team->fresh()->invite_link_default_role)->toBe(TeamRole::Viewer->value);
});

test('an admin cannot set the invite link default role to admin', function (): void {
    $admin = User::factory()->create();
    $this->team->users()->attach($admin, ['role' => TeamRole::Admin->value]);
    $this->actingAs($admin);

    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('manageInviteLink')->table(), [
            'invite_link_default_role' => TeamRole::Admin->value,
        ])
        ->assertHasActionErrors();

    expect($this->team->fresh()->invite_link_default_role)->toBe(TeamRole::Editor->value);
});

test('rotating the invite link changes the token', function (): void {
    $originalToken = $this->team->invite_link_token;

    // rotateInviteLink is an extra footer action nested inside manageInviteLink's
    // modal, not a standalone table action — Filament resolves it relative to
    // the mounted parent action, so the chain is expressed as an array.
    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction([
            TestAction::make('manageInviteLink')->table(),
            TestAction::make('rotateInviteLink'),
        ]);

    expect($this->team->fresh()->invite_link_token)->not->toBe($originalToken);
});
