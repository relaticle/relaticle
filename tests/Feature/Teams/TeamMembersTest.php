<?php

declare(strict_types=1);

use App\Actions\Jetstream\UpdateInviteLinkSettings;
use App\Actions\Jetstream\UpdateTeamMemberRole;
use App\Enums\TeamRole;
use App\Livewire\App\Teams\TeamMembers;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

mutates(TeamMembers::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withTeam()->create();
    $this->team = $this->owner->currentTeam;
    $this->actingAs($this->owner);
    Filament::setTenant($this->team);
});

test('the owner appears in the members list even though they have no pivot row', function (): void {
    expect($this->team->users()->count())->toBe(0);

    livewire(TeamMembers::class, ['team' => $this->team])
        ->assertSee($this->owner->email)
        ->assertSee(__('teams.roles.owner.label'));
});

test('pending invitations are not listed in the members table', function (): void {
    $this->team->teamInvitations()->create([
        'email' => 'pending@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    livewire(TeamMembers::class, ['team' => $this->team])
        ->assertDontSee('pending@example.test');
});

test('the owner row offers no leave action', function (): void {
    livewire(TeamMembers::class, ['team' => $this->team])
        ->assertTableActionHidden('leaveTeam', $this->owner->id);
});

test('the owner row offers no remove action', function (): void {
    livewire(TeamMembers::class, ['team' => $this->team])
        ->assertTableActionHidden('removeTeamMember', $this->owner->id);
});

test('the owner row offers no role change action', function (): void {
    livewire(TeamMembers::class, ['team' => $this->team])
        ->assertTableActionHidden('updateTeamRole', $this->owner->id);
});

test('a member can be removed', function (): void {
    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => TeamRole::Editor->value]);

    livewire(TeamMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('removeTeamMember')->table($member->id));

    expect($member->fresh()->belongsToTeam($this->team))->toBeFalse();
});

test('an admin cannot promote another member to admin', function (): void {
    $admin = User::factory()->create();
    $this->team->users()->attach($admin, ['role' => TeamRole::Admin->value]);

    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => TeamRole::Editor->value]);

    $this->actingAs($admin);

    livewire(TeamMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('updateTeamRole')->table($member->id), ['role' => TeamRole::Admin->value])
        ->assertHasActionErrors(['role']);

    expect($member->fresh()->teamRole($this->team)->key)->toBe(TeamRole::Editor->value);
});

test('an admin cannot demote a peer admin', function (): void {
    $adminA = User::factory()->create();
    $this->team->users()->attach($adminA, ['role' => TeamRole::Admin->value]);

    $adminB = User::factory()->create();
    $this->team->users()->attach($adminB, ['role' => TeamRole::Admin->value]);

    $this->actingAs($adminA);

    livewire(TeamMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('updateTeamRole')->table($adminB->id), ['role' => TeamRole::Editor->value])
        ->assertHasActionErrors(['role']);

    expect($adminB->fresh()->teamRole($this->team)->key)->toBe(TeamRole::Admin->value);
});

test('the owner can change a member role', function (): void {
    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => TeamRole::Editor->value]);

    livewire(TeamMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('updateTeamRole')->table($member->id), ['role' => TeamRole::Viewer->value])
        ->assertHasNoActionErrors();

    expect($member->fresh()->teamRole($this->team)->key)->toBe(TeamRole::Viewer->value);
});

test('multiple people can be invited in one submission', function (): void {
    Mail::fake();

    livewire(TeamMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('invitePeople')->table(), [
            'emails' => "one@example.test\ntwo@example.test",
            'role' => TeamRole::Editor->value,
        ]);

    expect($this->team->fresh()->teamInvitations->pluck('email')->all())
        ->toEqualCanonicalizing(['one@example.test', 'two@example.test']);
});

test('invitePeople rejects an admin role for a non-owner actor', function (): void {
    $admin = User::factory()->create();
    $this->team->users()->attach($admin, ['role' => TeamRole::Admin->value]);
    $this->actingAs($admin);

    livewire(TeamMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('invitePeople')->table(), [
            'emails' => 'nope@example.test',
            'role' => TeamRole::Admin->value,
        ])
        ->assertHasActionErrors();

    expect($this->team->fresh()->teamInvitations)->toHaveCount(0);
});

/**
 * Production is missing the team_user foreign keys, so a deleted account can
 * leave an orphaned pivot row behind; the page 500s on it because
 * Filament::getUserAvatarUrl() is typed non-nullable. Selecting through `users`
 * excludes orphans structurally rather than by a whereHas filter — this pins
 * that they stay excluded.
 */
test('the members list skips a membership row whose user no longer exists', function (): void {
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

    livewire(TeamMembers::class, ['team' => $this->team])
        ->assertSee($member->email)
        ->assertDontSee($deletedEmail);
});

test('a crafted payload above the batch cap is rejected server-side', function (): void {
    $emails = collect(range(1, 11))->map(fn (int $i): string => "batch{$i}@example.test")->implode("\n");

    livewire(TeamMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('invitePeople')->table(), ['emails' => $emails, 'role' => TeamRole::Editor->value])
        ->assertHasActionErrors();

    expect($this->team->fresh()->teamInvitations)->toHaveCount(0);
});

test('a submission exactly at the batch cap succeeds', function (): void {
    Mail::fake();

    $emails = collect(range(1, 10))->map(fn (int $i): string => "atcap{$i}@example.test")->implode("\n");

    livewire(TeamMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('invitePeople')->table(), ['emails' => $emails, 'role' => TeamRole::Editor->value])
        ->assertHasNoActionErrors();

    expect($this->team->fresh()->teamInvitations)->toHaveCount(10);
});

test('cumulative invite volume beyond the window cap is throttled, not just the call count', function (): void {
    Mail::fake();

    $firstBatch = collect(range(1, 10))->map(fn (int $i): string => "first{$i}@example.test")->implode("\n");
    $secondBatch = collect(range(1, 10))->map(fn (int $i): string => "second{$i}@example.test")->implode("\n");

    livewire(TeamMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('invitePeople')->table(), ['emails' => $firstBatch, 'role' => TeamRole::Editor->value]);

    livewire(TeamMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('invitePeople')->table(), ['emails' => $secondBatch, 'role' => TeamRole::Editor->value]);

    expect($this->team->fresh()->teamInvitations)->toHaveCount(20);

    livewire(TeamMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('invitePeople')->table(), [
            'emails' => 'onemore@example.test',
            'role' => TeamRole::Editor->value,
        ]);

    expect($this->team->fresh()->teamInvitations)->toHaveCount(20);
});

test('the owner can change the invite link default role', function (): void {
    livewire(TeamMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('manageInviteLink')->table(), [
            'invite_link_default_role' => TeamRole::Viewer->value,
        ]);

    expect($this->team->fresh()->invite_link_default_role)->toBe(TeamRole::Viewer->value);
});

test('an admin cannot set the invite link default role to admin', function (): void {
    $admin = User::factory()->create();
    $this->team->users()->attach($admin, ['role' => TeamRole::Admin->value]);
    $this->actingAs($admin);

    livewire(TeamMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('manageInviteLink')->table(), [
            'invite_link_default_role' => TeamRole::Admin->value,
        ])
        ->assertHasActionErrors();

    expect($this->team->fresh()->invite_link_default_role)->toBe(TeamRole::Editor->value);
});

test('the invite link default role must be a role the app actually registers', function (): void {
    expect(fn () => resolve(UpdateInviteLinkSettings::class)->update($this->owner, $this->team, 'superuser'))
        ->toThrow(ValidationException::class);

    expect($this->team->fresh()->invite_link_default_role)->toBe(TeamRole::Editor->value);
});

test('a member role must be a role the app actually registers', function (): void {
    $editor = User::factory()->create();
    $this->team->users()->attach($editor, ['role' => TeamRole::Editor->value]);

    expect(fn () => resolve(UpdateTeamMemberRole::class)->update($this->owner, $this->team, (string) $editor->getKey(), 'superuser'))
        ->toThrow(ValidationException::class);

    expect($editor->fresh()->teamRole($this->team->fresh())?->key)->toBe(TeamRole::Editor->value);
});

test('rotating the invite link changes the token', function (): void {
    $originalToken = $this->team->invite_link_token;

    // rotateInviteLink is an extra footer action nested inside manageInviteLink's
    // modal, not a standalone table action — Filament resolves it relative to
    // the mounted parent action, so the chain is expressed as an array.
    livewire(TeamMembers::class, ['team' => $this->team])
        ->callAction([
            TestAction::make('manageInviteLink')->table(),
            TestAction::make('rotateInviteLink'),
        ]);

    expect($this->team->fresh()->invite_link_token)->not->toBe($originalToken);
});
