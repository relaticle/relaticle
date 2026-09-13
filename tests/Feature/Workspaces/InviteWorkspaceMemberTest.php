<?php

declare(strict_types=1);

use App\Actions\Jetstream\InviteWorkspaceMember;
use App\Actions\Workspace\CreateWorkspaceInvitation;
use App\Enums\WorkspaceRole;
use App\Livewire\App\Workspaces\InviteWorkspaceMembers;
use App\Livewire\App\Workspaces\WorkspaceMembers;
use App\Mail\WorkspaceInvitationMail;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

mutates(User::class, InviteWorkspaceMember::class, CreateWorkspaceInvitation::class);

beforeEach(function () {
    Mail::fake();

    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
});

test('an invite with only an email defaults to the editor role', function () {
    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->mountAction('invitePeople')
        ->assertActionDataSet(['role' => WorkspaceRole::Editor->value])
        ->setActionData(['emails' => 'default-role@example.com'])
        ->callMountedAction();

    $invitation = $this->workspace->fresh()->workspaceInvitations->sole();
    expect($invitation->email)->toBe('default-role@example.com')
        ->and($invitation->role)->toBe(WorkspaceRole::Editor->value);
});

test('workspace members can be invited to workspace', function () {
    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'test@example.com',
            'role' => 'admin',
        ]);

    expect($this->workspace->fresh()->workspaceInvitations)->toHaveCount(1);

    $invitation = $this->workspace->fresh()->workspaceInvitations->first();
    expect($invitation->email)->toBe('test@example.com')
        ->and($invitation->role)->toBe('admin')
        ->and($invitation->expires_at)->not->toBeNull()
        ->and($invitation->expires_at->isFuture())->toBeTrue();
});

test('invitation expires_at is set based on config', function () {
    config(['jetstream.invitation_expiry_days' => 14]);

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'test@example.com',
            'role' => 'editor',
        ]);

    $invitation = $this->workspace->fresh()->workspaceInvitations->first();
    expect((int) round($invitation->expires_at->diffInDays(now(), absolute: true)))->toBe(14);
});

test('workspace member invitations can be revoked', function () {
    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'test@example.com',
            'role' => 'admin',
        ]);

    expect($this->workspace->fresh()->workspaceInvitations)->toHaveCount(1);

    $invitation = $this->workspace->fresh()->workspaceInvitations->first();

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction(TestAction::make('revokeWorkspaceInvitation')->table($invitation->id));

    expect($this->workspace->fresh()->workspaceInvitations)->toBeEmpty();
});

test('workspace members cannot be invited with a disposable email address', function () {
    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'burner@mailinator.com',
            'role' => 'admin',
        ])
        ->assertNotified(__('workspaces.notifications.some_invites_failed.title'));

    expect($this->workspace->fresh()->workspaceInvitations)->toBeEmpty();
});

test('invite returns the created invitation', function () {
    $invitation = resolve(InviteWorkspaceMember::class)->invite($this->user, $this->workspace, 'direct@example.com', 'admin');

    expect($invitation)->toBeInstanceOf(WorkspaceInvitation::class)
        ->and($invitation->email)->toBe('direct@example.com')
        ->and($invitation->role)->toBe('admin')
        ->and($invitation->workspace_id)->toBe($this->workspace->getKey());
});

test('creates an invitation through the chat adapter action', function () {
    $invitation = resolve(CreateWorkspaceInvitation::class)->execute(
        $this->user,
        ['email' => 'new@example.com', 'role' => WorkspaceRole::Editor->value],
    );

    expect($invitation->email)->toBe('new@example.com')
        ->and($invitation->role)->toBe(WorkspaceRole::Editor->value)
        ->and($invitation->workspace_id)->toBe($this->workspace->getKey());

    Mail::assertQueued(WorkspaceInvitationMail::class);
});

test('the chat adapter action defaults to the editor role when none is given', function () {
    $invitation = resolve(CreateWorkspaceInvitation::class)->execute(
        $this->user,
        ['email' => 'no-role@example.com'],
    );

    expect($invitation->role)->toBe(WorkspaceRole::Editor->value);
});

test('the chat adapter action rejects an invitation for an existing workspace member', function () {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member->getKey(), ['role' => WorkspaceRole::Editor->value]);

    expect(fn () => resolve(CreateWorkspaceInvitation::class)->execute(
        $this->user,
        ['email' => $member->email, 'role' => WorkspaceRole::Editor->value],
    ))->toThrow(ValidationException::class);

    expect($this->workspace->fresh()->workspaceInvitations)->toBeEmpty();
});

test('admin cannot invite a new member as admin', function (): void {
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);

    $this->actingAs($admin);

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'newadmin@example.com',
            'role' => 'admin',
        ])
        ->assertHasActionErrors();

    expect($this->workspace->fresh()->workspaceInvitations)->toHaveCount(0);
});

test('queues the invitation mail rather than sending it inline', function () {
    resolve(InviteWorkspaceMember::class)->invite($this->user, $this->workspace, 'queued@example.com', WorkspaceRole::Editor->value);

    Mail::assertNotSent(WorkspaceInvitationMail::class);
    Mail::assertQueued(WorkspaceInvitationMail::class, fn (WorkspaceInvitationMail $mail): bool => $mail->afterCommit === true);
});

test('does not dispatch the invitation mail while the transaction is still open', function () {
    $levelAtDispatch = null;

    Event::listen(
        MessageSending::class,
        function () use (&$levelAtDispatch): void {
            $levelAtDispatch = DB::transactionLevel();
        },
    );

    DB::transaction(function (): void {
        resolve(InviteWorkspaceMember::class)->invite($this->user, $this->workspace, 'in-tx@example.com', WorkspaceRole::Editor->value);
    });

    expect($levelAtDispatch)->toBeNull();
    Mail::assertQueued(WorkspaceInvitationMail::class);
});

test('owner can manage members and promote to admin', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    expect($owner->can('manageMembers', $workspace))->toBeTrue()
        ->and($owner->can('addWorkspaceMember', $workspace))->toBeTrue()
        ->and($owner->can('updateWorkspaceMember', $workspace))->toBeTrue()
        ->and($owner->can('removeWorkspaceMember', $workspace))->toBeTrue()
        ->and($owner->can('promoteToAdmin', $workspace))->toBeTrue()
        ->and($owner->can('update', $workspace))->toBeTrue();
});

test('admin can manage members but cannot promote to admin', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $admin = User::factory()->create();
    $workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);

    expect($admin->can('manageMembers', $workspace))->toBeTrue()
        ->and($admin->can('addWorkspaceMember', $workspace))->toBeTrue()
        ->and($admin->can('updateWorkspaceMember', $workspace))->toBeTrue()
        ->and($admin->can('removeWorkspaceMember', $workspace))->toBeTrue()
        ->and($admin->can('promoteToAdmin', $workspace))->toBeFalse()
        ->and($admin->can('update', $workspace))->toBeFalse()
        ->and($admin->can('delete', $workspace))->toBeFalse();
});

test('editor cannot manage members', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $editor = User::factory()->create();
    $workspace->users()->attach($editor, ['role' => WorkspaceRole::Editor->value]);

    expect($editor->can('manageMembers', $workspace))->toBeFalse()
        ->and($editor->can('addWorkspaceMember', $workspace))->toBeFalse()
        ->and($editor->can('updateWorkspaceMember', $workspace))->toBeFalse()
        ->and($editor->can('removeWorkspaceMember', $workspace))->toBeFalse()
        ->and($editor->can('promoteToAdmin', $workspace))->toBeFalse()
        ->and($editor->can('update', $workspace))->toBeFalse();
});

test('viewer cannot manage members', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $viewer = User::factory()->create();
    $workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);

    expect($viewer->can('manageMembers', $workspace))->toBeFalse()
        ->and($viewer->can('addWorkspaceMember', $workspace))->toBeFalse()
        ->and($viewer->can('updateWorkspaceMember', $workspace))->toBeFalse()
        ->and($viewer->can('removeWorkspaceMember', $workspace))->toBeFalse()
        ->and($viewer->can('promoteToAdmin', $workspace))->toBeFalse()
        ->and($viewer->can('update', $workspace))->toBeFalse();
});

test('inviting records the inviter and mints a token', function (): void {
    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'new@example.test',
            'role' => 'editor',
        ]);

    $invitation = $this->workspace->fresh()->workspaceInvitations->first();

    expect($invitation->inviter_id)->toBe($this->user->id)
        ->and($invitation->token)->not->toBeNull();
});

test('inviting lowercases a mixed-case email', function (): void {
    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'Mixed-Case@Example.Test',
            'role' => 'editor',
        ]);

    $invitation = $this->workspace->fresh()->workspaceInvitations->first();

    expect($invitation->email)->toBe('mixed-case@example.test');
});

test('inviting a case-variant of an already-invited email is rejected as a duplicate', function (): void {
    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'bob@example.test',
            'role' => 'editor',
        ]);

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'Bob@Example.Test',
            'role' => 'editor',
        ])
        ->assertNotified(
            Notification::make()
                ->title(__('workspaces.notifications.some_invites_failed.title'))
                ->body('Bob@Example.Test: '.__('workspaces.validation.email_already_invited'))
                ->warning()
        );

    expect($this->workspace->fresh()->workspaceInvitations)->toHaveCount(1);
});

test('inviting someone who already belongs to the workspace names the workspace, not the workspace', function (): void {
    $member = User::factory()->create(['email' => 'member@example.test']);
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Editor->value]);

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'member@example.test',
            'role' => 'editor',
        ])
        ->assertNotified(
            Notification::make()
                ->title(__('workspaces.notifications.some_invites_failed.title'))
                ->body('member@example.test: '.__('workspaces.validation.email_already_member'))
                ->warning()
        );

    expect($this->workspace->fresh()->workspaceInvitations)->toHaveCount(0);
});

test('invitation email names the inviter and the role', function (): void {
    $this->user->update(['name' => 'Ana Reyes']);

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'new@example.test',
            'role' => 'editor',
        ]);

    Mail::assertQueued(WorkspaceInvitationMail::class, function (WorkspaceInvitationMail $mail): bool {
        return $mail->hasTo('new@example.test')
            && str_contains($mail->envelope()->subject, 'Ana Reyes')
            && str_contains($mail->envelope()->subject, $this->workspace->name);
    });
});

test('invitation email accept URL resolves to the token route and carries the raw token, not the hash', function (): void {
    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'new@example.test',
            'role' => 'editor',
        ]);

    $invitation = $this->workspace->fresh()->workspaceInvitations->sole();

    Mail::assertQueued(WorkspaceInvitationMail::class, function (WorkspaceInvitationMail $mail) use ($invitation): bool {
        $resolved = WorkspaceInvitation::findByRawToken($mail->rawToken);

        $expectedUrl = route('workspace-invitations.token.accept', ['token' => $mail->rawToken]);

        return $mail->rawToken !== $invitation->token
            && $resolved instanceof WorkspaceInvitation
            && $resolved->is($invitation)
            && str_contains($mail->render(), $expectedUrl);
    });
});

test('an invite that loses a race to an identical concurrent invite reports it as already invited', function (): void {
    $email = 'raced@example.com';

    WorkspaceInvitation::creating(function (WorkspaceInvitation $invitation) use ($email): void {
        DB::table('workspace_invitations')->insert([
            'id' => (string) Str::ulid(),
            'workspace_id' => $invitation->workspace_id,
            'inviter_id' => $invitation->inviter_id,
            'email' => $email,
            'role' => WorkspaceRole::Editor->value,
            'token' => hash('sha256', Str::random(40)),
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    expect(fn (): WorkspaceInvitation => resolve(InviteWorkspaceMember::class)
        ->invite($this->user, $this->workspace, $email, WorkspaceRole::Editor->value))
        ->toThrow(
            ValidationException::class,
            __('workspaces.validation.email_already_invited'),
        );
});

test('an administrator cannot invite someone straight to administrator', function (): void {
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);

    expect(fn (): WorkspaceInvitation => resolve(InviteWorkspaceMember::class)
        ->invite($admin, $this->workspace, 'escalate@example.com', WorkspaceRole::Admin->value))
        ->toThrow(AuthorizationException::class);

    expect(WorkspaceInvitation::query()->where('email', 'escalate@example.com')->exists())->toBeFalse();
});

test('an administrator can invite someone as an editor', function (): void {
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);

    resolve(InviteWorkspaceMember::class)->invite($admin, $this->workspace, 'fine@example.com', WorkspaceRole::Editor->value);

    expect(WorkspaceInvitation::query()->where('email', 'fine@example.com')->exists())->toBeTrue();
});
