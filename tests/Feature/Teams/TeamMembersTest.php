<?php

declare(strict_types=1);

use App\Actions\Jetstream\UpdateInviteLinkSettings;
use App\Actions\Jetstream\UpdateTeamMemberRole;
use App\Enums\TeamRole;
use App\Livewire\App\Teams\InviteTeamMembers;
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

test('a pending invitation shares the roster with the joined members', function (): void {
    $this->team->teamInvitations()->create([
        'email' => 'pending@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    livewire(TeamMembers::class, ['team' => $this->team])
        ->assertSee('pending@example.test')
        ->assertSee(__('teams.table.invite_pending'))
        ->assertSee($this->owner->email);
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
        ->assertTableActionHidden('updateTeamRole', $adminB->id);

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

    livewire(InviteTeamMembers::class, ['team' => $this->team])
        ->callAction('invitePeople', [
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

    livewire(InviteTeamMembers::class, ['team' => $this->team])
        ->callAction('invitePeople', [
            'emails' => 'nope@example.test',
            'role' => TeamRole::Admin->value,
        ])
        ->assertHasActionErrors();

    expect($this->team->fresh()->teamInvitations)->toHaveCount(0);
});

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

    livewire(InviteTeamMembers::class, ['team' => $this->team])
        ->callAction('invitePeople', ['emails' => $emails, 'role' => TeamRole::Editor->value])
        ->assertHasActionErrors(['emails']);

    expect($this->team->fresh()->teamInvitations)->toHaveCount(0);
});

test('a submission exactly at the batch cap succeeds', function (): void {
    Mail::fake();

    $emails = collect(range(1, 10))->map(fn (int $i): string => "atcap{$i}@example.test")->implode("\n");

    livewire(InviteTeamMembers::class, ['team' => $this->team])
        ->callAction('invitePeople', ['emails' => $emails, 'role' => TeamRole::Editor->value])
        ->assertHasNoActionErrors();

    expect($this->team->fresh()->teamInvitations)->toHaveCount(10);
});

test('cumulative invite volume beyond the window cap is throttled, not just the call count', function (): void {
    Mail::fake();

    $firstBatch = collect(range(1, 10))->map(fn (int $i): string => "first{$i}@example.test")->implode("\n");
    $secondBatch = collect(range(1, 10))->map(fn (int $i): string => "second{$i}@example.test")->implode("\n");

    livewire(InviteTeamMembers::class, ['team' => $this->team])
        ->callAction('invitePeople', ['emails' => $firstBatch, 'role' => TeamRole::Editor->value]);

    livewire(InviteTeamMembers::class, ['team' => $this->team])
        ->callAction('invitePeople', ['emails' => $secondBatch, 'role' => TeamRole::Editor->value]);

    expect($this->team->fresh()->teamInvitations)->toHaveCount(20);

    livewire(InviteTeamMembers::class, ['team' => $this->team])
        ->callAction('invitePeople', [
            'emails' => 'onemore@example.test',
            'role' => TeamRole::Editor->value,
        ]);

    expect($this->team->fresh()->teamInvitations)->toHaveCount(20);
});

test('the owner can change the invite link default role', function (): void {
    livewire(InviteTeamMembers::class, ['team' => $this->team])
        ->mountAction('manageInviteLink')
        ->setActionData(['invite_link_default_role' => TeamRole::Viewer->value])
        ->assertHasNoActionErrors();

    expect($this->team->fresh()->invite_link_default_role)->toBe(TeamRole::Viewer->value);
});

test('an admin cannot set the invite link default role to admin', function (): void {
    $admin = User::factory()->create();
    $this->team->users()->attach($admin, ['role' => TeamRole::Admin->value]);
    $this->actingAs($admin);

    livewire(InviteTeamMembers::class, ['team' => $this->team])
        ->mountAction('manageInviteLink')
        ->setActionData(['invite_link_default_role' => TeamRole::Admin->value]);

    expect($this->team->fresh()->invite_link_default_role)->toBe(TeamRole::Editor->value);
});

test('not even the owner can point the invite link at the admin role', function (): void {
    expect(fn () => resolve(UpdateInviteLinkSettings::class)->update($this->owner, $this->team, TeamRole::Admin->value))
        ->toThrow(ValidationException::class);

    expect($this->team->fresh()->invite_link_default_role)->toBe(TeamRole::Editor->value);
});

test('the owner setting the invite link to admin through the modal changes nothing', function (): void {
    livewire(InviteTeamMembers::class, ['team' => $this->team])
        ->mountAction('manageInviteLink')
        ->setActionData(['invite_link_default_role' => TeamRole::Admin->value]);

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

    livewire(InviteTeamMembers::class, ['team' => $this->team])
        ->callAction([
            TestAction::make('manageInviteLink'),
            TestAction::make('rotateInviteLink'),
        ]);

    expect($this->team->fresh()->invite_link_token)->not->toBe($originalToken);
});

test('turning the workspace link off clears the token', function (): void {
    livewire(InviteTeamMembers::class, ['team' => $this->team])
        ->callAction([
            TestAction::make('manageInviteLink'),
            TestAction::make('disableInviteLink'),
        ]);

    expect($this->team->fresh()->invite_link_token)->toBeNull()
        ->and($this->team->fresh()->invite_link_token_expires_at)->toBeNull();
});

test('turning the link back on issues a different token, never the disabled one', function (): void {
    $originalToken = $this->team->invite_link_token;

    livewire(InviteTeamMembers::class, ['team' => $this->team])
        ->callAction([
            TestAction::make('manageInviteLink'),
            TestAction::make('disableInviteLink'),
        ]);

    livewire(InviteTeamMembers::class, ['team' => $this->team->fresh()])
        ->callAction([
            TestAction::make('manageInviteLink'),
            TestAction::make('enableInviteLink'),
        ]);

    expect($this->team->fresh()->invite_link_token)
        ->toBeString()
        ->toHaveLength(40)
        ->not->toBe($originalToken);
});

test('a workspace with the link off offers no rotate or disable action', function (): void {
    $this->team->disableInviteLink();

    livewire(InviteTeamMembers::class, ['team' => $this->team->fresh()])
        ->mountAction('manageInviteLink')
        ->assertActionHidden('rotateInviteLink')
        ->assertActionHidden('disableInviteLink')
        ->assertActionVisible('enableInviteLink');
});

test('the members list is searchable by name and by email', function (): void {
    $needle = User::factory()->create(['name' => 'Zoltan Searchme', 'email' => 'searchme@example.test']);
    $this->team->users()->attach($needle, ['role' => TeamRole::Editor->value]);

    livewire(TeamMembers::class, ['team' => $this->team])
        ->assertCanSeeTableRecords([$needle, $this->owner])
        ->searchTable('Zoltan')
        ->assertCanSeeTableRecords([$needle])
        ->assertCanNotSeeTableRecords([$this->owner])
        ->searchTable('searchme@example.test')
        ->assertCanSeeTableRecords([$needle])
        ->assertCanNotSeeTableRecords([$this->owner]);
});

test('a search term containing SQL wildcards is matched literally', function (): void {
    $literal = User::factory()->create(['name' => 'Ann_Lee', 'email' => 'ann-underscore@example.test']);
    $decoy = User::factory()->create(['name' => 'AnnXLee', 'email' => 'ann-decoy@example.test']);

    $this->team->users()->attach($literal, ['role' => TeamRole::Editor->value]);
    $this->team->users()->attach($decoy, ['role' => TeamRole::Editor->value]);

    livewire(TeamMembers::class, ['team' => $this->team])
        ->searchTable('Ann_Lee')
        ->assertCanSeeTableRecords([$literal])
        ->assertCanNotSeeTableRecords([$decoy]);
});

test('a search that matches nobody explains itself instead of showing a blank table', function (): void {
    livewire(TeamMembers::class, ['team' => $this->team])
        ->searchTable('nobody-by-that-name')
        ->assertSee(__('teams.table.no_results.heading'))
        ->assertDontSee($this->owner->email);
});

test('the members list paginates rather than rendering every member at once', function (): void {
    $members = User::factory()->count(12)->create();

    foreach ($members as $member) {
        $this->team->users()->attach($member, ['role' => TeamRole::Editor->value]);
    }

    $page = livewire(TeamMembers::class, ['team' => $this->team])
        ->instance()
        ->getTableRecords();

    expect($page)->toHaveCount(10)
        ->and($page->total())->toBe(13);
});

test('an admin sees no remove action on another admins row', function (): void {
    $admin = User::factory()->create();
    $peer = User::factory()->create();
    $this->team->users()->attach($admin, ['role' => TeamRole::Admin->value]);
    $this->team->users()->attach($peer, ['role' => TeamRole::Admin->value]);

    $this->actingAs($admin);

    livewire(TeamMembers::class, ['team' => $this->team])
        ->assertTableActionHidden('removeTeamMember', $peer->id);
});

test('an admin still manages a non admin member', function (): void {
    $admin = User::factory()->create();
    $editor = User::factory()->create();
    $this->team->users()->attach($admin, ['role' => TeamRole::Admin->value]);
    $this->team->users()->attach($editor, ['role' => TeamRole::Editor->value]);

    $this->actingAs($admin);

    livewire(TeamMembers::class, ['team' => $this->team])
        ->assertTableActionVisible('updateTeamRole', $editor->id)
        ->assertTableActionVisible('removeTeamMember', $editor->id);
});

test('the owner still manages an admin', function (): void {
    $admin = User::factory()->create();
    $this->team->users()->attach($admin, ['role' => TeamRole::Admin->value]);

    livewire(TeamMembers::class, ['team' => $this->team])
        ->assertTableActionVisible('updateTeamRole', $admin->id)
        ->assertTableActionVisible('removeTeamMember', $admin->id);
});
