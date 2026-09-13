<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\EditWorkspace;
use App\Filament\Pages\Workspace\Members;
use App\Livewire\App\Workspaces\InviteWorkspaceMembers;
use App\Livewire\App\Workspaces\UpdateWorkspaceName;
use App\Livewire\App\Workspaces\WorkspaceMembers;
use App\Mail\WorkspaceInvitationMail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Livewire as LivewireComponent;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
});

test('the general tab owns workspace name and deletion', function () {
    $page = app(EditWorkspace::class);
    $page->tenant = $this->workspace;

    $components = collect($page->form(Schema::make($page))->getComponents())
        ->filter(fn ($c): bool => $c instanceof LivewireComponent)
        ->map(fn (LivewireComponent $c): string => $c->getComponent())
        ->all();

    expect($components)->toContain(UpdateWorkspaceName::class)
        ->and($components)->not->toContain(WorkspaceMembers::class);
});

test('the members tab owns invitations and membership', function () {
    $page = app(Members::class);

    $components = collect($page->form(Schema::make($page))->getComponents())
        ->filter(fn ($c): bool => $c instanceof LivewireComponent)
        ->map(fn (LivewireComponent $c): string => $c->getComponent())
        ->all();

    expect($components)->toContain(InviteWorkspaceMembers::class)
        ->and($components)->toContain(WorkspaceMembers::class);
});

test('the members tab is the invite form followed by one roster, and nothing else', function () {
    $page = app(Members::class);

    $components = collect($page->form(Schema::make($page))->getComponents())
        ->filter(fn ($c): bool => $c instanceof LivewireComponent)
        ->map(fn (LivewireComponent $c): string => $c->getComponent())
        ->values()
        ->all();

    expect($components)->toBe([InviteWorkspaceMembers::class, WorkspaceMembers::class]);
});

test('admin invites by email and the invitation appears in the roster', function () {
    Mail::fake();

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'invitee@example.com',
            'role' => 'editor',
        ]);

    $invitation = $this->workspace->fresh()->workspaceInvitations->sole();

    expect($invitation->email)->toBe('invitee@example.com')
        ->and($invitation->role)->toBe('editor');

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertSee('invitee@example.com');

    Mail::assertQueued(WorkspaceInvitationMail::class);
});

test('inviting keeps the admin on the members tab and announces the new invitation', function () {
    Mail::fake();

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'invitee@example.com',
            'role' => 'editor',
        ])
        ->assertNoRedirect()
        ->assertDispatched('workspaceInvitationSent');

    expect($this->workspace->fresh()->workspaceInvitations->pluck('email')->all())
        ->toBe(['invitee@example.com']);
});

test('the roster picks up an invitation announced by the invite form', function () {
    Mail::fake();

    $roster = livewire(WorkspaceMembers::class, ['workspace' => $this->workspace]);

    $roster->assertDontSee('invitee@example.com');

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'invitee@example.com',
            'role' => 'editor',
        ]);

    $roster->call('refreshRoster')
        ->assertSee('invitee@example.com');
});

test('admin can resend a pending invitation', function () {
    Mail::fake();

    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'email' => 'pending@example.com',
    ]);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction(TestAction::make('resendWorkspaceInvitation')->table($invitation->id))
        ->assertNotified(__('workspaces.notifications.workspace_invitation_sent.success'));

    Mail::assertNotSent(WorkspaceInvitationMail::class);
    Mail::assertQueued(
        WorkspaceInvitationMail::class,
        fn (WorkspaceInvitationMail $mail): bool => $mail->hasTo('pending@example.com') && $mail->afterCommit === true,
    );
});

test('admin can revoke a pending invitation', function () {
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction(TestAction::make('revokeWorkspaceInvitation')->table($invitation->id))
        ->assertNotified(__('workspaces.notifications.workspace_invitation_revoked.success'));

    expect(WorkspaceInvitation::query()->whereKey($invitation->getKey())->exists())->toBeFalse();
});

test('extend action is removed from the invitation rows', function () {
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertActionDoesNotExist(TestAction::make('extendWorkspaceInvitation')->table($invitation->id));
});

test('onboarding-generated invite link still works for an authenticated user', function () {
    $owner = User::factory()->create();
    /** @var Workspace $workspace */
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $token = $workspace->invite_link_token;

    expect($token)->toBeString()->toHaveLength(40);

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->post(route('workspaces.join.confirm', ['token' => $token]))
        ->assertRedirect(Dashboard::getUrl(['tenant' => $workspace]));

    expect($workspace->fresh()->users()->where('users.id', $joiner->id)->exists())->toBeTrue()
        ->and($joiner->fresh()->current_workspace_id)->toBe($workspace->id);
});
