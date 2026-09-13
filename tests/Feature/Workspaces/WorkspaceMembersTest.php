<?php

declare(strict_types=1);

use App\Actions\Jetstream\UpdateInviteLinkSettings;
use App\Actions\Jetstream\UpdateWorkspaceMemberRole;
use App\Enums\WorkspaceRole;
use App\Livewire\App\Workspaces\InviteWorkspaceMembers;
use App\Livewire\App\Workspaces\WorkspaceMembers;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

mutates(WorkspaceMembers::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);
});

test('the owner appears in the members list even though they have no pivot row', function (): void {
    expect($this->workspace->users()->count())->toBe(0);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertSee($this->owner->email)
        ->assertSee(__('workspaces.roles.owner.label'));
});

test('a pending invitation shares the roster with the joined members', function (): void {
    $this->workspace->workspaceInvitations()->create([
        'email' => 'pending@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertSee('pending@example.test')
        ->assertSee(__('workspaces.table.invite_pending'))
        ->assertSee($this->owner->email);
});

test('the owner row offers no leave action', function (): void {
    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertTableActionHidden('leaveWorkspace', $this->owner->id);
});

test('the owner row offers no remove action', function (): void {
    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertTableActionHidden('removeWorkspaceMember', $this->owner->id);
});

test('the owner row offers no role change action', function (): void {
    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertTableActionHidden('updateWorkspaceRole', $this->owner->id);
});

test('a member can be removed', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Editor->value]);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction(TestAction::make('removeWorkspaceMember')->table($member->id));

    expect($member->fresh()->belongsToWorkspace($this->workspace))->toBeFalse();
});

test('an admin cannot promote another member to admin', function (): void {
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);

    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Editor->value]);

    $this->actingAs($admin);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction(TestAction::make('updateWorkspaceRole')->table($member->id), ['role' => WorkspaceRole::Admin->value])
        ->assertHasActionErrors(['role']);

    expect($member->fresh()->workspaceRole($this->workspace)->key)->toBe(WorkspaceRole::Editor->value);
});

test('an admin cannot demote a peer admin', function (): void {
    $adminA = User::factory()->create();
    $this->workspace->users()->attach($adminA, ['role' => WorkspaceRole::Admin->value]);

    $adminB = User::factory()->create();
    $this->workspace->users()->attach($adminB, ['role' => WorkspaceRole::Admin->value]);

    $this->actingAs($adminA);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertTableActionHidden('updateWorkspaceRole', $adminB->id);

    expect($adminB->fresh()->workspaceRole($this->workspace)->key)->toBe(WorkspaceRole::Admin->value);
});

test('the owner can change a member role', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Editor->value]);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction(TestAction::make('updateWorkspaceRole')->table($member->id), ['role' => WorkspaceRole::Viewer->value])
        ->assertHasNoActionErrors();

    expect($member->fresh()->workspaceRole($this->workspace)->key)->toBe(WorkspaceRole::Viewer->value);
});

test('multiple people can be invited in one submission', function (): void {
    Mail::fake();

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => "one@example.test\ntwo@example.test",
            'role' => WorkspaceRole::Editor->value,
        ]);

    expect($this->workspace->fresh()->workspaceInvitations->pluck('email')->all())
        ->toEqualCanonicalizing(['one@example.test', 'two@example.test']);
});

test('invitePeople rejects an admin role for a non-owner actor', function (): void {
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    $this->actingAs($admin);

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'nope@example.test',
            'role' => WorkspaceRole::Admin->value,
        ])
        ->assertHasActionErrors();

    expect($this->workspace->fresh()->workspaceInvitations)->toHaveCount(0);
});

test('the members list skips a membership row whose user no longer exists', function (): void {
    Schema::table('workspace_user', function (Blueprint $table): void {
        $table->dropForeign(['user_id']);
    });

    $member = User::factory()->create();
    $deletedUser = User::factory()->create();

    $this->workspace->users()->attach([
        $member->id => ['role' => WorkspaceRole::Editor->value],
        $deletedUser->id => ['role' => WorkspaceRole::Editor->value],
    ]);

    $deletedEmail = $deletedUser->email;
    $deletedUser->delete();

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertSee($member->email)
        ->assertDontSee($deletedEmail);
});

test('a crafted payload above the batch cap is rejected server-side', function (): void {
    $emails = collect(range(1, 11))->map(fn (int $i): string => "batch{$i}@example.test")->implode("\n");

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', ['emails' => $emails, 'role' => WorkspaceRole::Editor->value])
        ->assertHasActionErrors(['emails']);

    expect($this->workspace->fresh()->workspaceInvitations)->toHaveCount(0);
});

test('a submission exactly at the batch cap succeeds', function (): void {
    Mail::fake();

    $emails = collect(range(1, 10))->map(fn (int $i): string => "atcap{$i}@example.test")->implode("\n");

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', ['emails' => $emails, 'role' => WorkspaceRole::Editor->value])
        ->assertHasNoActionErrors();

    expect($this->workspace->fresh()->workspaceInvitations)->toHaveCount(10);
});

test('cumulative invite volume beyond the window cap is throttled, not just the call count', function (): void {
    Mail::fake();

    $firstBatch = collect(range(1, 10))->map(fn (int $i): string => "first{$i}@example.test")->implode("\n");
    $secondBatch = collect(range(1, 10))->map(fn (int $i): string => "second{$i}@example.test")->implode("\n");

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', ['emails' => $firstBatch, 'role' => WorkspaceRole::Editor->value]);

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', ['emails' => $secondBatch, 'role' => WorkspaceRole::Editor->value]);

    expect($this->workspace->fresh()->workspaceInvitations)->toHaveCount(20);

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'onemore@example.test',
            'role' => WorkspaceRole::Editor->value,
        ]);

    expect($this->workspace->fresh()->workspaceInvitations)->toHaveCount(20);
});

test('the owner can change the invite link default role', function (): void {
    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->mountAction('manageInviteLink')
        ->setActionData(['invite_link_default_role' => WorkspaceRole::Viewer->value])
        ->assertHasNoActionErrors();

    expect($this->workspace->fresh()->invite_link_default_role)->toBe(WorkspaceRole::Viewer->value);
});

test('an admin cannot set the invite link default role to admin', function (): void {
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    $this->actingAs($admin);

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->mountAction('manageInviteLink')
        ->setActionData(['invite_link_default_role' => WorkspaceRole::Admin->value]);

    expect($this->workspace->fresh()->invite_link_default_role)->toBe(WorkspaceRole::Editor->value);
});

test('not even the owner can point the invite link at the admin role', function (): void {
    expect(fn () => resolve(UpdateInviteLinkSettings::class)->update($this->owner, $this->workspace, WorkspaceRole::Admin->value))
        ->toThrow(ValidationException::class);

    expect($this->workspace->fresh()->invite_link_default_role)->toBe(WorkspaceRole::Editor->value);
});

test('the owner setting the invite link to admin through the modal changes nothing', function (): void {
    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->mountAction('manageInviteLink')
        ->setActionData(['invite_link_default_role' => WorkspaceRole::Admin->value]);

    expect($this->workspace->fresh()->invite_link_default_role)->toBe(WorkspaceRole::Editor->value);
});

test('the invite link default role must be a role the app actually registers', function (): void {
    expect(fn () => resolve(UpdateInviteLinkSettings::class)->update($this->owner, $this->workspace, 'superuser'))
        ->toThrow(ValidationException::class);

    expect($this->workspace->fresh()->invite_link_default_role)->toBe(WorkspaceRole::Editor->value);
});

test('a member role must be a role the app actually registers', function (): void {
    $editor = User::factory()->create();
    $this->workspace->users()->attach($editor, ['role' => WorkspaceRole::Editor->value]);

    expect(fn () => resolve(UpdateWorkspaceMemberRole::class)->update($this->owner, $this->workspace, (string) $editor->getKey(), 'superuser'))
        ->toThrow(ValidationException::class);

    expect($editor->fresh()->workspaceRole($this->workspace->fresh())?->key)->toBe(WorkspaceRole::Editor->value);
});

test('rotating the invite link changes the token', function (): void {
    $originalToken = $this->workspace->invite_link_token;

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction([
            TestAction::make('manageInviteLink'),
            TestAction::make('rotateInviteLink'),
        ]);

    expect($this->workspace->fresh()->invite_link_token)->not->toBe($originalToken);
});

test('turning the workspace link off clears the token', function (): void {
    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction([
            TestAction::make('manageInviteLink'),
            TestAction::make('disableInviteLink'),
        ]);

    expect($this->workspace->fresh()->invite_link_token)->toBeNull()
        ->and($this->workspace->fresh()->invite_link_token_expires_at)->toBeNull();
});

test('turning the link back on issues a different token, never the disabled one', function (): void {
    $originalToken = $this->workspace->invite_link_token;

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction([
            TestAction::make('manageInviteLink'),
            TestAction::make('disableInviteLink'),
        ]);

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace->fresh()])
        ->callAction([
            TestAction::make('manageInviteLink'),
            TestAction::make('enableInviteLink'),
        ]);

    expect($this->workspace->fresh()->invite_link_token)
        ->toBeString()
        ->toHaveLength(40)
        ->not->toBe($originalToken);
});

test('a workspace with the link off offers no rotate or disable action', function (): void {
    $this->workspace->disableInviteLink();

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace->fresh()])
        ->mountAction('manageInviteLink')
        ->assertActionHidden('rotateInviteLink')
        ->assertActionHidden('disableInviteLink')
        ->assertActionVisible('enableInviteLink');
});

test('the members list is searchable by name and by email', function (): void {
    $needle = User::factory()->create(['name' => 'Zoltan Searchme', 'email' => 'searchme@example.test']);
    $this->workspace->users()->attach($needle, ['role' => WorkspaceRole::Editor->value]);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
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

    $this->workspace->users()->attach($literal, ['role' => WorkspaceRole::Editor->value]);
    $this->workspace->users()->attach($decoy, ['role' => WorkspaceRole::Editor->value]);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->searchTable('Ann_Lee')
        ->assertCanSeeTableRecords([$literal])
        ->assertCanNotSeeTableRecords([$decoy]);
});

test('a search that matches nobody explains itself instead of showing a blank table', function (): void {
    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->searchTable('nobody-by-that-name')
        ->assertSee(__('workspaces.table.no_results.heading'))
        ->assertDontSee($this->owner->email);
});

test('the members list paginates rather than rendering every member at once', function (): void {
    $members = User::factory()->count(12)->create();

    foreach ($members as $member) {
        $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Editor->value]);
    }

    $page = livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->instance()
        ->getTableRecords();

    expect($page)->toHaveCount(10)
        ->and($page->total())->toBe(13);
});

test('an admin sees no remove action on another admins row', function (): void {
    $admin = User::factory()->create();
    $peer = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    $this->workspace->users()->attach($peer, ['role' => WorkspaceRole::Admin->value]);

    $this->actingAs($admin);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertTableActionHidden('removeWorkspaceMember', $peer->id);
});

test('an admin still manages a non admin member', function (): void {
    $admin = User::factory()->create();
    $editor = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    $this->workspace->users()->attach($editor, ['role' => WorkspaceRole::Editor->value]);

    $this->actingAs($admin);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertTableActionVisible('updateWorkspaceRole', $editor->id)
        ->assertTableActionVisible('removeWorkspaceMember', $editor->id);
});

test('the owner still manages an admin', function (): void {
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertTableActionVisible('updateWorkspaceRole', $admin->id)
        ->assertTableActionVisible('removeWorkspaceMember', $admin->id);
});
