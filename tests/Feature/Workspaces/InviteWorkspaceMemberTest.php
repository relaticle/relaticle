<?php

declare(strict_types=1);

use App\Actions\Jetstream\InviteWorkspaceMember;
use App\Actions\Workspace\CreateWorkspaceInvitation;
use App\Enums\WorkspaceCapability;
use App\Enums\WorkspaceRole;
use App\Exceptions\WorkspaceInvitationRateLimitedException;
use App\Livewire\App\Workspaces\InviteWorkspaceMembers;
use App\Livewire\App\Workspaces\InviteWorkspaceMembersModal;
use App\Livewire\App\Workspaces\WorkspaceMembers;
use App\Mail\WorkspaceInvitationMail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;
use Livewire\Features\SupportTesting\Testable;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\Workspace\InviteWorkspaceMemberTool;

mutates(User::class, InviteWorkspaceMember::class, CreateWorkspaceInvitation::class);

beforeEach(function () {
    Mail::fake();

    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
});

test('an invite with only an email defaults to the member role', function () {
    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->mountAction('invitePeople')
        ->assertActionDataSet(['role' => WorkspaceRole::Member->value])
        ->setActionData(['emails' => 'default-role@example.com'])
        ->callMountedAction();

    $invitation = $this->workspace->fresh()->workspaceInvitations->sole();
    expect($invitation->email)->toBe('default-role@example.com')
        ->and($invitation->role)->toBe(WorkspaceRole::Member->value);
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
            'role' => 'member',
        ]);

    $invitation = $this->workspace->fresh()->workspaceInvitations->first();
    expect((int) round($invitation->expires_at->diffInDays(now(), absolute: true)))->toBe(14);
});

test('inviting several people at once confirms how many were sent', function () {
    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'first@example.com, second@example.com, third@example.com',
            'role' => 'member',
        ])
        ->assertNotified(__('workspaces.notifications.workspace_invitation_sent.success_many', ['count' => 3]));
});

test('inviting an address whose invitation expired sends it a fresh one', function () {
    $invite = fn (string $role) => resolve(InviteWorkspaceMember::class)->invite($this->user, $this->workspace, 'lapsed@example.com', $role);

    $invite('member');
    WorkspaceInvitation::query()->where('email', 'lapsed@example.com')->update(['expires_at' => now()->subDay()]);

    $invite('admin');

    $invitation = $this->workspace->fresh()->workspaceInvitations->sole();
    expect($invitation->isExpired())->toBeFalse()
        ->and($invitation->role)->toBe('admin');

    Mail::assertQueued(WorkspaceInvitationMail::class, 2);
});

test('inviting an address with a live invitation is still refused', function () {
    resolve(InviteWorkspaceMember::class)->invite($this->user, $this->workspace, 'pending@example.com', 'member');

    expect(fn () => resolve(InviteWorkspaceMember::class)->invite($this->user, $this->workspace, 'pending@example.com', 'member'))
        ->toThrow(ValidationException::class, __('workspaces.validation.email_already_invited'));
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
        ['email' => 'new@example.com', 'role' => WorkspaceRole::Member->value],
    );

    expect($invitation->email)->toBe('new@example.com')
        ->and($invitation->role)->toBe(WorkspaceRole::Member->value)
        ->and($invitation->workspace_id)->toBe($this->workspace->getKey());

    Mail::assertQueued(WorkspaceInvitationMail::class);
});

test('the chat adapter action defaults to the member role when none is given', function () {
    $invitation = resolve(CreateWorkspaceInvitation::class)->execute(
        $this->user,
        ['email' => 'no-role@example.com'],
    );

    expect($invitation->role)->toBe(WorkspaceRole::Member->value);
});

test('the chat adapter action rejects an invitation for an existing workspace member', function () {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member->getKey(), ['role' => WorkspaceRole::Member->value]);

    expect(fn () => resolve(CreateWorkspaceInvitation::class)->execute(
        $this->user,
        ['email' => $member->email, 'role' => WorkspaceRole::Member->value],
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
    resolve(InviteWorkspaceMember::class)->invite($this->user, $this->workspace, 'queued@example.com', WorkspaceRole::Member->value);

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
        resolve(InviteWorkspaceMember::class)->invite($this->user, $this->workspace, 'in-tx@example.com', WorkspaceRole::Member->value);
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

test('a member cannot manage members', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);

    expect($member->can('manageMembers', $workspace))->toBeFalse()
        ->and($member->can('addWorkspaceMember', $workspace))->toBeFalse()
        ->and($member->can('updateWorkspaceMember', $workspace))->toBeFalse()
        ->and($member->can('removeWorkspaceMember', $workspace))->toBeFalse()
        ->and($member->can('promoteToAdmin', $workspace))->toBeFalse()
        ->and($member->can('update', $workspace))->toBeFalse();
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
            'role' => 'member',
        ]);

    $invitation = $this->workspace->fresh()->workspaceInvitations->first();

    expect($invitation->inviter_id)->toBe($this->user->id)
        ->and($invitation->token)->not->toBeNull();
});

test('inviting lowercases a mixed-case email', function (): void {
    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'Mixed-Case@Example.Test',
            'role' => 'member',
        ]);

    $invitation = $this->workspace->fresh()->workspaceInvitations->first();

    expect($invitation->email)->toBe('mixed-case@example.test');
});

test('inviting a case-variant of an already-invited email is rejected as a duplicate', function (): void {
    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'bob@example.test',
            'role' => 'member',
        ]);

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'Bob@Example.Test',
            'role' => 'member',
        ])
        ->assertNotified(
            Notification::make()
                ->title(__('workspaces.notifications.some_invites_failed.title'))
                ->body('Bob@Example.Test: '.__('workspaces.validation.email_already_invited'))
                ->warning()
        );

    expect($this->workspace->fresh()->workspaceInvitations)->toHaveCount(1);
});

test('inviting an address longer than the database holds is reported, not thrown', function (): void {
    $address = str_repeat('a', 250).'@northwind.test';

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => $address,
            'role' => 'member',
        ])
        ->assertNotified(
            Notification::make()
                ->title(__('workspaces.notifications.some_invites_failed.title'))
                ->body("{$address}: ".__('validation.max.string', ['attribute' => 'email', 'max' => 255]))
                ->warning()
        );

    expect($this->workspace->fresh()->workspaceInvitations)->toHaveCount(0);
});

test('inviting someone who already belongs to the workspace names the workspace, not the workspace', function (): void {
    $member = User::factory()->create(['email' => 'member@example.test']);
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'member@example.test',
            'role' => 'member',
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
            'role' => 'member',
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
            'role' => 'member',
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
            'role' => WorkspaceRole::Member->value,
            'token' => hash('sha256', Str::random(40)),
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    expect(fn (): WorkspaceInvitation => resolve(InviteWorkspaceMember::class)
        ->invite($this->user, $this->workspace, $email, WorkspaceRole::Member->value))
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

test('an administrator can invite someone as a member', function (): void {
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);

    resolve(InviteWorkspaceMember::class)->invite($admin, $this->workspace, 'fine@example.com', WorkspaceRole::Member->value);

    expect(WorkspaceInvitation::query()->where('email', 'fine@example.com')->exists())->toBeTrue();
});

test('inviting into a workspace scheduled for deletion is refused with the reason and sends no mail', function (): void {
    $this->workspace->forceFill(['scheduled_deletion_at' => now()->addDays(30)])->save();

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'closing@example.com',
            'role' => WorkspaceRole::Member->value,
        ])
        ->assertNotified(
            Notification::make()
                ->title(__('workspaces.notifications.some_invites_failed.title'))
                ->body('closing@example.com: '.__('workspaces.validation.workspace_scheduled_for_deletion'))
                ->warning(),
        );

    expect($this->workspace->workspaceInvitations()->exists())->toBeFalse();
    Mail::assertNothingQueued();
});

test('explains the chosen role under the invite modal role select', function (WorkspaceRole $role): void {
    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->mountAction('invitePeople')
        ->setActionData(['role' => $role->value])
        ->assertSchemaComponentExists('role', checkComponentUsing: fn (Select $component): bool => $component->getChildSchema($component::BELOW_CONTENT_SCHEMA_KEY)?->toHtml() !== null
            && str_contains((string) $component->getChildSchema($component::BELOW_CONTENT_SCHEMA_KEY)->toHtml(), e($role->description())));
})->with(WorkspaceRole::cases());

test('the sidebar invite row opens the invite modal in place and sends the invitation', function (): void {
    livewire(InviteWorkspaceMembersModal::class, ['workspace' => $this->workspace])
        ->dispatch('open-invite-workspace-members')
        ->assertActionMounted('invitePeople')
        ->setActionData(['emails' => 'from-sidebar@example.com'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertDispatched('workspaceInvitationSent');

    expect($this->workspace->fresh()->workspaceInvitations->sole()->email)->toBe('from-sidebar@example.com');
});

test('a member cannot open the invite modal', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);
    $member->switchWorkspace($this->workspace);
    $this->actingAs($member->fresh());

    livewire(InviteWorkspaceMembersModal::class, ['workspace' => $this->workspace])
        ->dispatch('open-invite-workspace-members')
        ->assertActionNotMounted();
});

test('offers an admin every role but admin in the invite modal', function (): void {
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    $admin->switchWorkspace($this->workspace);
    $this->actingAs($admin->fresh());

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->mountAction('invitePeople')
        ->assertSchemaComponentExists('role', checkComponentUsing: fn (Select $component): bool => array_keys($component->getOptions()) === [WorkspaceRole::Member->value, WorkspaceRole::Viewer->value]);
});

test('shows a hint for every role option in the invite-link default role picker', function (): void {
    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->mountAction('manageInviteLink')
        ->assertSchemaComponentExists('invite_link_default_role', checkComponentUsing: function (Radio $component): bool {
            $options = $component->getOptions();
            $descriptions = $component->getDescriptions();

            return array_keys($options) === [WorkspaceRole::Member->value, WorkspaceRole::Viewer->value]
                && $descriptions[WorkspaceRole::Member->value] === __('workspaces.roles.member.description')
                && $descriptions[WorkspaceRole::Viewer->value] === __('workspaces.roles.viewer.description');
        });
});

test('the compare-roles modal marks every cell exactly as WorkspaceRole::capabilities() says, not a hand-written copy', function (): void {
    $component = livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->mountAction([
            TestAction::make('invitePeople'),
            TestAction::make('compareRoles')->schemaComponent('role'),
        ]);

    $content = (string) $component->instance()->getMountedAction()->getModalContent();

    $roleCapabilities = [
        'owner' => WorkspaceCapability::forOwner(),
        WorkspaceRole::Admin->value => WorkspaceRole::Admin->capabilities(),
        WorkspaceRole::Member->value => WorkspaceRole::Member->capabilities(),
        WorkspaceRole::Viewer->value => WorkspaceRole::Viewer->capabilities(),
    ];

    foreach (WorkspaceCapability::cases() as $capability) {
        foreach ($roleCapabilities as $roleKey => $capabilities) {
            $cellPattern = sprintf(
                '/data-capability="%s" data-role="%s">(.*?)<\/td>/s',
                preg_quote($capability->value, '/'),
                preg_quote($roleKey, '/'),
            );

            expect($content)->toMatch($cellPattern);

            preg_match($cellPattern, $content, $cellMatch);

            $granted = in_array($capability, $capabilities, true);
            $expectedText = $granted
                ? __('workspaces.role_matrix.granted')
                : __('workspaces.role_matrix.not_granted');
            $otherText = $granted
                ? __('workspaces.role_matrix.not_granted')
                : __('workspaces.role_matrix.granted');

            expect($cellMatch[1])
                ->toContain($expectedText)
                ->not->toContain($otherText);
        }
    }
});

/** @param  list<string>  $emails */
function inviteThroughForm(User $actor, Workspace $workspace, array $emails): Testable
{
    test()->actingAs($actor);
    Filament::setTenant($workspace);

    return livewire(InviteWorkspaceMembers::class, ['workspace' => $workspace])
        ->callAction('invitePeople', ['emails' => implode(',', $emails), 'role' => WorkspaceRole::Member->value]);
}

/** @return list<string> */
function invitationEmails(string $prefix, int $count): array
{
    return array_map(fn (int $n): string => "{$prefix}{$n}@example.com", range(1, $count));
}

function fillWorkspaceHourlyCeiling(User $owner, int $count, string $prefix = 'relay'): void
{
    $admins = User::factory()->count(3)->create();

    foreach ($admins as $admin) {
        $owner->currentWorkspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    }

    foreach (array_chunk(invitationEmails($prefix, $count), 10) as $index => $batch) {
        inviteThroughForm($admins[$index % 3], $owner->currentWorkspace, $batch);
    }

    test()->actingAs($owner);
}

function approveInvitationProposal(User $user, string $email): void
{
    app(InviteWorkspaceMemberTool::class)->handle(new Request([
        'records' => [['email' => $email, 'role' => WorkspaceRole::Member->value]],
    ]));

    $pending = PendingAction::query()
        ->where('workspace_id', $user->currentWorkspace->getKey())
        ->latest()
        ->firstOrFail();

    resolve(PendingActionService::class)->approve($pending, $user);
}

test('invitations are capped per workspace across different inviting users', function (): void {
    fillWorkspaceHourlyCeiling($this->user, 50);

    Mail::assertQueuedCount(50);

    inviteThroughForm($this->user, $this->workspace, ['one-too-many@example.com'])
        ->assertNotified(__('workspaces.notifications.invite_rate_limited.title'));

    expect($this->workspace->workspaceInvitations()->count())->toBe(50);
});

test('the chat approval path stops at the workspace ceiling and leaves the proposal pending', function (): void {
    fillWorkspaceHourlyCeiling($this->user, 50);

    expect(fn () => approveInvitationProposal($this->user, 'chat-over@example.com'))
        ->toThrow(WorkspaceInvitationRateLimitedException::class);

    expect($this->workspace->workspaceInvitations()->where('email', 'chat-over@example.com')->exists())->toBeFalse()
        ->and(PendingAction::query()->latest()->firstOrFail()->status)->toBe(PendingActionStatus::Pending);
});

test('a batch the ceiling cuts lists the addresses that were not sent', function (): void {
    fillWorkspaceHourlyCeiling($this->user, 45);

    $batch = invitationEmails('batch', 8);

    inviteThroughForm($this->user, $this->workspace, $batch)
        ->assertNotified(
            Notification::make()
                ->title(__('workspaces.notifications.some_invites_failed.title'))
                ->body(collect(array_slice($batch, 5))
                    ->map(fn (string $email): string => "{$email}: ".__('workspaces.notifications.some_invites_failed.not_sent_limit'))
                    ->implode("\n"))
                ->warning(),
        );

    expect($this->workspace->workspaceInvitations()->where('email', 'like', 'batch%')->pluck('email')->sort()->values()->all())
        ->toBe(collect(array_slice($batch, 0, 5))->sort()->values()->all());
});

test('the hourly ceiling resets after an hour and the daily ceiling holds', function (): void {
    foreach (range(1, 4) as $hour) {
        fillWorkspaceHourlyCeiling($this->user, 50, "day{$hour}x");

        $this->travelTo(now()->addMinutes(61));
    }

    inviteThroughForm($this->user, $this->workspace, ['over-day@example.com'])
        ->assertNotified(__('workspaces.notifications.invite_rate_limited.title'));

    expect($this->workspace->workspaceInvitations()->where('email', 'over-day@example.com')->exists())->toBeFalse();
});

test('the wait is shown as a duration, not raw seconds', function (): void {
    $this->freezeTime();

    fillWorkspaceHourlyCeiling($this->user, 50);

    inviteThroughForm($this->user, $this->workspace, ['wait@example.com'])
        ->assertNotified(
            Notification::make()
                ->title(__('workspaces.notifications.invite_rate_limited.title'))
                ->body(__('workspaces.notifications.invite_rate_limited.body', ['time' => '1 hour']))
                ->danger(),
        );
});

test('one workspace reaching its ceiling does not block another workspace', function (): void {
    fillWorkspaceHourlyCeiling($this->user, 50);

    $other = User::factory()->withWorkspace()->create();

    inviteThroughForm($other, $other->currentWorkspace, ['fresh@example.com']);

    expect($other->currentWorkspace->workspaceInvitations()->where('email', 'fresh@example.com')->exists())->toBeTrue();
});

test('one inviter is capped across all the workspaces they own and the trip is logged', function (): void {
    Log::spy();

    $owned = [$this->workspace, ...Workspace::factory()->count(2)->create(['user_id' => $this->user->getKey()])->all()];

    foreach ([$owned[0], $owned[1]] as $workspace) {
        foreach (array_chunk(invitationEmails("inv{$workspace->getKey()}x", 50), 10) as $batch) {
            inviteThroughForm($this->user, $workspace, $batch);

            $this->travelTo(now()->addSeconds(31));
        }
    }

    inviteThroughForm($this->user, $owned[2], ['inviter-over@example.com'])
        ->assertNotified(__('workspaces.notifications.invite_rate_limited.title'));

    expect($owned[2]->workspaceInvitations()->count())->toBe(0);

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $context['limits'] === ['inviter_hour']
            && $context['workspace_id'] === $owned[2]->getKey()
            && $context['inviter_id'] === $this->user->getKey(),
    )->once();
});

test('a rolled back invitation does not use up a slot', function (): void {
    foreach (range(1, 6) as $round) {
        try {
            DB::transaction(function () use ($round): void {
                inviteThroughForm($this->user, $this->workspace, invitationEmails("rolled{$round}x", 10));

                throw new RuntimeException('approval rolled back');
            });
        } catch (RuntimeException) {
        }

        $this->travelTo(now()->addSeconds(31));
    }

    inviteThroughForm($this->user, $this->workspace, ['after-rollback@example.com']);

    expect($this->workspace->workspaceInvitations()->where('email', 'after-rollback@example.com')->exists())->toBeTrue();
});

test('resending an invitation counts against the workspace ceiling', function (): void {
    fillWorkspaceHourlyCeiling($this->user, 50);

    $invitation = $this->workspace->workspaceInvitations()->orderBy('email')->firstOrFail();

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction(TestAction::make('resendWorkspaceInvitation')->table($invitation->id))
        ->assertNotified(__('workspaces.notifications.invite_rate_limited.title'));

    Mail::assertQueuedCount(50);
});
