<?php

declare(strict_types=1);

use App\Actions\Workspace\CreateWorkspaceInvitation;
use App\Enums\WorkspaceRole;
use App\Filament\Pages\Workspace\Members;
use App\Mail\WorkspaceInvitationMail;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Livewire\Chat\ProposalCard;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Support\DestinationResolver;
use Relaticle\Chat\Support\ProposalCoreFields;
use Relaticle\Chat\Support\ResolvedActionText;
use Relaticle\Chat\Tools\Task\CreateTaskTool;
use Relaticle\Chat\Tools\Workspace\InviteWorkspaceMemberTool;
use Symfony\Component\Mailer\Exception\TransportException;

mutates(InviteWorkspaceMemberTool::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);
});

function pendingActionForWorkspace(User $user): PendingAction
{
    return PendingAction::query()
        ->where('workspace_id', $user->currentWorkspace->getKey())
        ->latest()
        ->firstOrFail();
}

function seedInvitationConversation(User $user): string
{
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'workspace_id' => $user->currentWorkspace->getKey(),
        'participant_type' => $user->getMorphClass(),
        'participant_id' => (string) $user->getKey(),
        'title' => 'Invites',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $conversationId;
}

/**
 * @param  list<string>  $emails
 */
function proposeInvitationsInConversation(User $user, array $emails, ?string $conversationId = null, ?string $turnId = null): PendingAction
{
    $conversationId ??= seedInvitationConversation($user);

    $tool = app(InviteWorkspaceMemberTool::class);
    $tool->setConversationId($conversationId);
    $tool->setTurnId($turnId);
    $tool->handle(new Request([
        'records' => array_map(
            static fn (string $email): array => ['email' => $email, 'role' => WorkspaceRole::Member->value],
            $emails,
        ),
    ]));

    return PendingAction::query()
        ->where('conversation_id', $conversationId)
        ->where('entity_type', 'workspace_invitations')
        ->orderByDesc('id')
        ->firstOrFail();
}

function openInvitationDock(PendingAction $anchor): Testable
{
    return Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $anchor->getKey(), context: 'conversation');
}

function resolvedInvitationText(PendingAction $pending): string
{
    return collect(resolve(PendingActionService::class)->resolvedForConversation((string) $pending->conversation_id, null))
        ->flatMap(fn (array $action): array => ResolvedActionText::lines($action, cite: false))
        ->implode("\n");
}

it('creates one pending action for a batch of two invitations, carrying both emails', function (): void {
    $tool = app(InviteWorkspaceMemberTool::class);

    $tool->handle(new Request([
        'records' => [
            ['email' => 'alex@example.com', 'role' => 'admin'],
            ['email' => 'jamie@example.com', 'role' => 'member'],
        ],
    ]));

    expect(PendingAction::query()->where('workspace_id', $this->workspace->getKey())->count())->toBe(1);

    $pending = pendingActionForWorkspace($this->user);

    expect($pending->action_class)->toBe(CreateWorkspaceInvitation::class)
        ->and($pending->entity_type)->toBe('workspace_invitations')
        ->and($pending->action_data['_batch'])->toBeTrue()
        ->and(collect($pending->action_data['records'])->pluck('email')->all())
        ->toBe(['alex@example.com', 'jamie@example.com']);
});

it('approving a single invitation proposal writes the row and sends the invite mail', function (): void {
    Mail::fake();
    $tool = app(InviteWorkspaceMemberTool::class);

    $tool->handle(new Request([
        'records' => [
            ['email' => 'new-teammate@example.com', 'role' => 'member'],
        ],
    ]));

    $pending = pendingActionForWorkspace($this->user);

    resolve(PendingActionService::class)->approve($pending, $this->user);

    expect(WorkspaceInvitation::query()
        ->where('workspace_id', $this->workspace->getKey())
        ->where('email', 'new-teammate@example.com')
        ->exists())->toBeTrue();

    Mail::assertQueued(WorkspaceInvitationMail::class);
});

it('keeps the mail transport failure off the card when the invite email cannot be sent', function (): void {
    $transportMessage = 'Connection could not be established with host "smtp.internal.test:587": authentication failed for user "postmaster@relaticle"';

    Mail::shouldReceive('to')->andReturnSelf();
    Mail::shouldReceive('queue')->andThrow(new TransportException($transportMessage));

    app(InviteWorkspaceMemberTool::class)->handle(new Request([
        'records' => [['email' => 'undeliverable@example.com', 'role' => 'member']],
    ]));

    $pending = pendingActionForWorkspace($this->user);

    $component = Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $pending->getKey(), context: 'conversation')
        ->call('createCurrent')
        ->assertDispatched('proposal:resolve-failed')
        ->assertNotDispatched('proposal:resolved')
        ->assertHasErrors('resolve');

    $shown = $component->errors()->first('resolve');

    expect($shown)->toBe('The email could not be sent, so nothing was saved. Please try again in a moment.')
        ->and($shown)->not->toContain('smtp.internal.test')
        ->and($shown)->not->toContain('postmaster@relaticle');

    expect(WorkspaceInvitation::query()->where('workspace_id', $this->workspace->getKey())->count())->toBe(0)
        ->and($pending->fresh()->status)->toBe(PendingActionStatus::Pending);
});

describe('an invitation proposal', function (): void {
    beforeEach(function (): void {
        Queue::fake();

        $this->conversationId = seedInvitationConversation($this->user);
        $this->turnId = (string) Str::ulid();

        $this->planTask = function (string $title = 'Call Lena'): PendingAction {
            $tool = app(CreateTaskTool::class);
            $tool->setConversationId($this->conversationId);
            $tool->setTurnId($this->turnId);
            $tool->handle(new Request(['records' => [['title' => $title]]]));

            return PendingAction::query()
                ->where('conversation_id', $this->conversationId)
                ->where('entity_type', 'task')
                ->orderByDesc('id')
                ->firstOrFail();
        };

        $this->planInvite = fn (array $emails = ['alex@example.com']): PendingAction => proposeInvitationsInConversation($this->user, $emails, $this->conversationId, $this->turnId);
    });

    it('is left pending by approve all, which creates the task and sends no invitation', function (): void {
        Mail::fake();
        $task = ($this->planTask)();
        $invite = ($this->planInvite)();

        openInvitationDock($task)
            ->call('approveAll')
            ->assertHasNoErrors()
            ->assertNotDispatched('proposal:resolve-failed');

        expect(Task::query()->where('title', 'Call Lena')->exists())->toBeTrue()
            ->and($task->fresh()->status)->toBe(PendingActionStatus::Approved)
            ->and($invite->fresh()->status)->toBe(PendingActionStatus::Pending)
            ->and(WorkspaceInvitation::query()->count())->toBe(0);

        Mail::assertNothingQueued();
    });

    it('sends every invitation of its step with one click of its own button', function (): void {
        Mail::fake();
        $task = ($this->planTask)();
        $invite = ($this->planInvite)(['alex@example.com', 'jamie@example.com']);

        openInvitationDock($task)
            ->call('approveStep', (string) $invite->getKey())
            ->assertHasNoErrors();

        expect(WorkspaceInvitation::query()->orderBy('email')->pluck('email')->all())->toBe(['alex@example.com', 'jamie@example.com'])
            ->and($invite->fresh()->status)->toBe(PendingActionStatus::Approved)
            ->and($task->fresh()->status)->toBe(PendingActionStatus::Pending);

        Mail::assertQueued(WorkspaceInvitationMail::class, 2);
    });

    it('labels its own button with the invitations still to send', function (): void {
        $task = ($this->planTask)();
        $invite = ($this->planInvite)(['alex@example.com', 'jamie@example.com', 'sam@example.com']);

        openInvitationDock($task)
            ->assertSeeHtml('data-proposal-send-step="'.$invite->getKey().'"')
            ->assertSee('Send 3 invitations')
            ->assertDontSee('3 records')
            ->call('skipItem', (string) $invite->getKey(), 0)
            ->assertSee('Send 2 invitations');
    });

    it('heads a paginated card with the one invitation its button sends', function (): void {
        $invite = ($this->planInvite)(['alex@example.com', 'jamie@example.com']);

        openInvitationDock($invite)
            ->assertSee('Invite alex@example.com as Member')
            ->assertDontSee('Invite 2 teammates')
            ->call('nextItem')
            ->assertSee('Invite jamie@example.com as Member')
            ->assertDontSee('Invite alex@example.com as Member');
    });

    it('counts only the steps approve all will approve, and says each send has its own button', function (): void {
        ($this->planTask)('First task');
        ($this->planTask)('Second task');
        $invite = ($this->planInvite)();

        openInvitationDock($invite)
            ->assertSee('Approve all 2')
            ->assertSee('Send invitation')
            ->assertSee(__('Each send has its own button'))
            ->assertDontSee(__('Approved together, in order'));
    });

    it('is not sent by the keyboard shortcut when it is the only step left, but by its own footer button', function (): void {
        Mail::fake();
        $invite = ($this->planInvite)();

        $dock = openInvitationDock($invite)->dispatch('proposal:create-current', context: 'conversation');

        expect($invite->fresh()->status)->toBe(PendingActionStatus::Pending);

        Mail::assertNothingQueued();

        $dock->assertDontSeeHtml('<kbd')->call('createCurrent');

        expect($invite->fresh()->status)->toBe(PendingActionStatus::Approved);

        Mail::assertQueued(WorkspaceInvitationMail::class, 1);
    });

    it('keeps the invitation already sent when a later one in its step fails', function (): void {
        Mail::fake();
        $task = ($this->planTask)();
        $invite = ($this->planInvite)(['alex@example.com', 'jamie@example.com']);

        $member = User::factory()->create(['email' => 'jamie@example.com']);
        $this->workspace->users()->attach($member->getKey(), ['role' => WorkspaceRole::Member->value]);

        openInvitationDock($task)
            ->call('approveStep', (string) $invite->getKey())
            ->assertDispatched('proposal:resolve-failed')
            ->assertHasErrors('resolve');

        expect(WorkspaceInvitation::query()->pluck('email')->all())->toBe(['alex@example.com'])
            ->and($invite->fresh()->status)->toBe(PendingActionStatus::Pending)
            ->and($invite->fresh()->result_data['items'][0]['status'] ?? null)->toBe('approved');

        Mail::assertQueued(WorkspaceInvitationMail::class, 1);
    });

    it('keeps the mail transport failure off the plan card when its own button cannot send it', function (): void {
        $transportMessage = 'Connection could not be established with host "smtp.internal.test:587": authentication failed for user "postmaster@relaticle"';

        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('queue')->andThrow(new TransportException($transportMessage));

        ($this->planTask)('Follow up');
        $invite = ($this->planInvite)(['undeliverable@example.com']);

        $component = openInvitationDock($invite)
            ->call('approveStep', (string) $invite->getKey())
            ->assertDispatched('proposal:resolve-failed')
            ->assertHasErrors('resolve');

        $shown = $component->errors()->first('resolve');

        expect($shown)->toContain('The email could not be sent, so nothing was saved. Please try again in a moment.')
            ->and($shown)->not->toContain('smtp.internal.test')
            ->and($shown)->not->toContain('postmaster@relaticle')
            ->and($invite->fresh()->status)->toBe(PendingActionStatus::Pending);
    });
});

it('approving an email that already belongs to a workspace member surfaces the validation error and writes no row', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member->getKey(), ['role' => WorkspaceRole::Member->value]);

    $tool = app(InviteWorkspaceMemberTool::class);
    $tool->handle(new Request([
        'records' => [
            ['email' => $member->email, 'role' => 'member'],
        ],
    ]));

    $pending = pendingActionForWorkspace($this->user);

    expect(fn () => resolve(PendingActionService::class)->approve($pending, $this->user))
        ->toThrow(ValidationException::class);

    expect(WorkspaceInvitation::query()->where('workspace_id', $this->workspace->getKey())->count())->toBe(0)
        ->and($pending->fresh()->status)->toBe(PendingActionStatus::Pending);
});

it('rejects a role outside member|viewer|admin before proposing', function (): void {
    $tool = app(InviteWorkspaceMemberTool::class);

    $result = $tool->handle(new Request([
        'records' => [
            ['email' => 'owner-role@example.com', 'role' => 'owner'],
        ],
    ]));

    $decoded = json_decode($result, true);

    expect($decoded['error'])->toContain('Role must be one of "admin", "member", "viewer"')
        ->and(PendingAction::query()->where('workspace_id', $this->workspace->getKey())->count())->toBe(0);
});

it('proposes a viewer invitation, the role the members form also offers', function (): void {
    $tool = app(InviteWorkspaceMemberTool::class);

    $result = $tool->handle(new Request([
        'records' => [
            ['email' => 'read-only@example.com', 'role' => WorkspaceRole::Viewer->value],
        ],
    ]));

    expect(json_decode($result, true))->not->toHaveKey('error');

    expect(pendingActionForWorkspace($this->user)->action_data['role'])->toBe(WorkspaceRole::Viewer->value);
});

it('creates a viewer membership when a viewer invitation is approved and accepted', function (): void {
    $tool = app(InviteWorkspaceMemberTool::class);

    $tool->handle(new Request([
        'records' => [
            ['email' => 'read-only@example.com', 'role' => WorkspaceRole::Viewer->value],
        ],
    ]));

    resolve(PendingActionService::class)->approve(pendingActionForWorkspace($this->user), $this->user);

    expect(WorkspaceInvitation::query()->where('email', 'read-only@example.com')->sole()->role)
        ->toBe(WorkspaceRole::Viewer->value);
});

it('rejects a batch over the configured max batch size', function (): void {
    $max = (int) config('chat.max_batch_size');
    $records = array_map(
        fn (int $i): array => ['email' => "person{$i}@example.com", 'role' => 'member'],
        range(1, $max + 1),
    );

    $tool = app(InviteWorkspaceMemberTool::class);
    $result = $tool->handle(new Request(['records' => $records]));

    $decoded = json_decode($result, true);

    expect($decoded['error'])->toContain('Too many records')
        ->and(PendingAction::query()->where('workspace_id', $this->workspace->getKey())->count())->toBe(0);
});

it('refuses to propose an invitation for a member who does not own the workspace', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);
    $member->forceFill(['current_workspace_id' => $this->workspace->getKey()])->save();

    $this->actingAs($member);
    Filament::setTenant($this->workspace);

    $result = (new InviteWorkspaceMemberTool)->handle(new Request([
        'records' => [['email' => 'alex@example.com', 'role' => WorkspaceRole::Member->value]],
    ]));

    expect($result)->toContain('Only workspace owners and admins can invite teammates')
        ->and(PendingAction::query()->where('workspace_id', $this->workspace->getKey())->count())->toBe(0)
        ->and(WorkspaceInvitation::query()->where('workspace_id', $this->workspace->getKey())->count())->toBe(0);
});

it('never links the non-owner refusal to a page that would 403 for them', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);
    $member->forceFill(['current_workspace_id' => $this->workspace->getKey()])->save();

    $this->actingAs($member);
    Filament::setTenant($this->workspace);

    $result = (new InviteWorkspaceMemberTool)->handle(new Request([
        'records' => [['email' => 'alex@example.com', 'role' => WorkspaceRole::Member->value]],
    ]));

    $membersUrl = resolve(DestinationResolver::class)->resolve('workspace_members', $this->workspace);

    expect(Members::canAccess())->toBeFalse()
        ->and($result)->toContain('Only workspace owners and admins can invite teammates')
        ->and($result)->toContain('ask one')
        ->and($result)->not->toContain($membersUrl)
        ->and($result)->not->toContain('http');
});

it('does not render a name row on the invitation card', function (): void {
    (new InviteWorkspaceMemberTool)->handle(new Request([
        'records' => [['email' => 'alex@example.com', 'role' => WorkspaceRole::Admin->value]],
    ]));

    $display = pendingActionForWorkspace($this->user)->display_data;
    $labels = array_column($display['fields'] ?? [], 'label');

    expect($labels)->not->toContain('Name')
        ->and($labels)->toContain('Email')
        ->and(ProposalCoreFields::titleKey('workspace_invitations'))->toBe('email');
});

it('labels a resolved invitation by its email so the assistant can name it', function (): void {
    Mail::fake();

    (new InviteWorkspaceMemberTool)->handle(new Request([
        'records' => [['email' => 'alex@example.com', 'role' => WorkspaceRole::Admin->value]],
    ]));

    $pending = pendingActionForWorkspace($this->user);
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'workspace_id' => $this->workspace->getKey(),
        'participant_type' => $this->user->getMorphClass(),
        'participant_id' => (string) $this->user->getKey(),
        'title' => 'Invites',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pending->forceFill(['conversation_id' => $conversationId])->save();

    resolve(PendingActionService::class)->approve($pending->fresh(), $this->user);

    $resolved = resolve(PendingActionService::class)->resolvedForConversation($conversationId, null);

    expect($resolved[0]['label'] ?? null)->toBe('alex@example.com');
});

it('names the entity in plain words on a batch card', function (): void {
    (new InviteWorkspaceMemberTool)->handle(new Request([
        'records' => [
            ['email' => 'one@example.com', 'role' => WorkspaceRole::Member->value],
            ['email' => 'two@example.com', 'role' => WorkspaceRole::Member->value],
        ],
    ]));

    $display = pendingActionForWorkspace($this->user)->display_data;

    expect($display['title'] ?? '')->toBe('Invite Teammates')
        ->and($display['summary'] ?? '')->toBe('Invite 2 teammates');
});

it('labels its decision with the invitation it sends', function (): void {
    $pending = proposeInvitationsInConversation($this->user, ['alex@example.com']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $pending->getKey(), context: 'conversation')
        ->assertSeeHtmlInOrder(['wire:click="createCurrent"', '<span>Send invitation</span>'])
        ->assertDontSeeHtml('<span>Create</span>');
});

it('tells the assistant in plain words who was invited and who was not', function (): void {
    Mail::fake();
    $service = resolve(PendingActionService::class);

    $invited = proposeInvitationsInConversation($this->user, ['invited@example.com']);
    $declined = proposeInvitationsInConversation($this->user, ['declined@example.com']);

    $service->approve($invited, $this->user);
    $service->reject($declined, $this->user);

    expect(resolvedInvitationText($invited))->toContain('APPROVED (written): invite teammate "invited@example.com"')
        ->not->toContain('create workspace_invitations')
        ->and(resolvedInvitationText($declined))->toContain('REJECTED (nothing was written): invite teammate "declined@example.com"');
});

it('says NOT invited for an invitation the user skipped', function (): void {
    Mail::fake();
    $service = resolve(PendingActionService::class);

    $pending = proposeInvitationsInConversation($this->user, ['first@example.com', 'second@example.com']);

    $service->approveItem($pending, $this->user, 0);
    $service->rejectItem($pending->fresh(), $this->user, 1);

    expect(resolvedInvitationText($pending))->toContain('skipped by the user, NOT invited: "second@example.com"');
});

it('keeps the mail transport failure off the card on the batch path too', function (): void {
    $transportMessage = 'Connection could not be established with host "smtp.internal.test:587": authentication failed for user "postmaster@relaticle"';

    Mail::shouldReceive('to')->andReturnSelf();
    Mail::shouldReceive('queue')->andThrow(new TransportException($transportMessage));

    app(InviteWorkspaceMemberTool::class)->handle(new Request([
        'records' => [
            ['email' => 'first@example.com', 'role' => 'member'],
            ['email' => 'second@example.com', 'role' => 'member'],
        ],
    ]));

    $pending = pendingActionForWorkspace($this->user);

    $component = Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $pending->getKey(), context: 'conversation')
        ->call('createCurrent')
        ->assertHasErrors('resolve');

    $shown = $component->errors()->first('resolve');

    expect($shown)->not->toContain('smtp.internal.test')
        ->and($shown)->not->toContain('postmaster@relaticle')
        ->and($shown)->not->toContain('587');

    expect(WorkspaceInvitation::query()->where('workspace_id', $this->workspace->getKey())->count())->toBe(0);
});

it('lets an administrator propose an invitation', function (): void {
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    $admin->forceFill(['current_workspace_id' => $this->workspace->getKey()])->save();

    $this->actingAs($admin);
    Filament::setTenant($this->workspace);

    $result = (new InviteWorkspaceMemberTool)->handle(new Request([
        'records' => [['email' => 'alex@example.com', 'role' => WorkspaceRole::Member->value]],
    ]));

    expect($result)->not->toContain('Only the workspace owner can invite teammates')
        ->and(PendingAction::query()->where('workspace_id', $this->workspace->getKey())->count())->toBe(1);
});

it('refuses an administrator proposing another administrator', function (): void {
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    $admin->forceFill(['current_workspace_id' => $this->workspace->getKey()])->save();

    $this->actingAs($admin);
    Filament::setTenant($this->workspace);

    $result = (new InviteWorkspaceMemberTool)->handle(new Request([
        'records' => [['email' => 'alex@example.com', 'role' => WorkspaceRole::Admin->value]],
    ]));

    expect($result)->toContain('Only the workspace owner')
        ->and(PendingAction::query()->where('workspace_id', $this->workspace->getKey())->count())->toBe(0);
});
