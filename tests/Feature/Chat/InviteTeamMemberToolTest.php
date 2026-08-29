<?php

declare(strict_types=1);

use App\Actions\Team\CreateTeamInvitation;
use App\Enums\TeamRole;
use App\Filament\Pages\Team\Members;
use App\Models\TeamInvitation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;
use Laravel\Jetstream\Mail\TeamInvitation as TeamInvitationMail;
use Livewire\Livewire;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Livewire\Chat\ProposalCard;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Support\DestinationResolver;
use Relaticle\Chat\Support\ProposalCoreFields;
use Relaticle\Chat\Tools\Team\InviteTeamMemberTool;
use Symfony\Component\Mailer\Exception\TransportException;

mutates(InviteTeamMemberTool::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);
    Filament::setTenant($this->team);
});

function pendingActionForTeam(User $user): PendingAction
{
    return PendingAction::query()
        ->where('team_id', $user->currentTeam->getKey())
        ->latest()
        ->firstOrFail();
}

it('creates one pending action for a batch of two invitations, carrying both emails', function (): void {
    $tool = app(InviteTeamMemberTool::class);

    $tool->handle(new Request([
        'records' => [
            ['email' => 'alex@example.com', 'role' => 'admin'],
            ['email' => 'jamie@example.com', 'role' => 'editor'],
        ],
    ]));

    expect(PendingAction::query()->where('team_id', $this->team->getKey())->count())->toBe(1);

    $pending = pendingActionForTeam($this->user);

    expect($pending->action_class)->toBe(CreateTeamInvitation::class)
        ->and($pending->entity_type)->toBe('team_invitations')
        ->and($pending->action_data['_batch'])->toBeTrue()
        ->and(collect($pending->action_data['records'])->pluck('email')->all())
        ->toBe(['alex@example.com', 'jamie@example.com']);
});

it('approving a single invitation proposal writes the row and sends the invite mail', function (): void {
    Mail::fake();
    $tool = app(InviteTeamMemberTool::class);

    $tool->handle(new Request([
        'records' => [
            ['email' => 'new-teammate@example.com', 'role' => 'editor'],
        ],
    ]));

    $pending = pendingActionForTeam($this->user);

    resolve(PendingActionService::class)->approve($pending, $this->user);

    expect(TeamInvitation::query()
        ->where('team_id', $this->team->getKey())
        ->where('email', 'new-teammate@example.com')
        ->exists())->toBeTrue();

    Mail::assertQueued(TeamInvitationMail::class);
});

it('keeps the mail transport failure off the card when the invite email cannot be sent', function (): void {
    $transportMessage = 'Connection could not be established with host "smtp.internal.test:587": authentication failed for user "postmaster@relaticle"';

    Mail::shouldReceive('to')->andReturnSelf();
    Mail::shouldReceive('queue')->andThrow(new TransportException($transportMessage));

    app(InviteTeamMemberTool::class)->handle(new Request([
        'records' => [['email' => 'undeliverable@example.com', 'role' => 'editor']],
    ]));

    $pending = pendingActionForTeam($this->user);

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

    // The approve transaction rolls back, so retrying stays safe.
    expect(TeamInvitation::query()->where('team_id', $this->team->getKey())->count())->toBe(0)
        ->and($pending->fresh()->status)->toBe(PendingActionStatus::Pending);
});

it('approving an email that already belongs to a team member surfaces the validation error and writes no row', function (): void {
    $member = User::factory()->create();
    $this->team->users()->attach($member->getKey(), ['role' => TeamRole::Editor->value]);

    $tool = app(InviteTeamMemberTool::class);
    $tool->handle(new Request([
        'records' => [
            ['email' => $member->email, 'role' => 'editor'],
        ],
    ]));

    $pending = pendingActionForTeam($this->user);

    expect(fn () => resolve(PendingActionService::class)->approve($pending, $this->user))
        ->toThrow(ValidationException::class);

    expect(TeamInvitation::query()->where('team_id', $this->team->getKey())->count())->toBe(0)
        ->and($pending->fresh()->status)->toBe(PendingActionStatus::Pending);
});

it('rejects a role outside editor|admin before proposing', function (): void {
    $tool = app(InviteTeamMemberTool::class);

    $result = $tool->handle(new Request([
        'records' => [
            ['email' => 'owner-role@example.com', 'role' => 'owner'],
        ],
    ]));

    $decoded = json_decode($result, true);

    expect($decoded['error'])->toContain('Role must be "editor" or "admin"')
        ->and(PendingAction::query()->where('team_id', $this->team->getKey())->count())->toBe(0);
});

it('rejects a batch over the configured max batch size', function (): void {
    $max = (int) config('chat.max_batch_size');
    $records = array_map(
        fn (int $i): array => ['email' => "person{$i}@example.com", 'role' => 'editor'],
        range(1, $max + 1),
    );

    $tool = app(InviteTeamMemberTool::class);
    $result = $tool->handle(new Request(['records' => $records]));

    $decoded = json_decode($result, true);

    expect($decoded['error'])->toContain('Too many records')
        ->and(PendingAction::query()->where('team_id', $this->team->getKey())->count())->toBe(0);
});

it('refuses to propose an invitation for a member who does not own the workspace', function (): void {
    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => TeamRole::Editor->value]);
    $member->forceFill(['current_team_id' => $this->team->getKey()])->save();

    $this->actingAs($member);
    Filament::setTenant($this->team);

    $result = (new InviteTeamMemberTool)->handle(new Request([
        'records' => [['email' => 'alex@example.com', 'role' => TeamRole::Editor->value]],
    ]));

    expect($result)->toContain('Only the workspace owner can invite teammates')
        ->and(PendingAction::query()->where('team_id', $this->team->getKey())->count())->toBe(0)
        ->and(TeamInvitation::query()->where('team_id', $this->team->getKey())->count())->toBe(0);
});

it('never links the non-owner refusal to a page that would 403 for them', function (): void {
    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => TeamRole::Editor->value]);
    $member->forceFill(['current_team_id' => $this->team->getKey()])->save();

    $this->actingAs($member);
    Filament::setTenant($this->team);

    $result = (new InviteTeamMemberTool)->handle(new Request([
        'records' => [['email' => 'alex@example.com', 'role' => TeamRole::Editor->value]],
    ]));

    // Members::canAccess() is `can('update', $tenant)`, the exact complement of the
    // guard above, so every user who reaches this refusal is barred from that page.
    $membersUrl = resolve(DestinationResolver::class)->resolve('team_members', $this->team);

    expect(Members::canAccess())->toBeFalse()
        ->and($result)->toContain('Only the workspace owner can invite teammates')
        ->and($result)->toContain('ask an owner')
        ->and($result)->not->toContain($membersUrl)
        ->and($result)->not->toContain('http');
});

it('does not render a name row on the invitation card', function (): void {
    (new InviteTeamMemberTool)->handle(new Request([
        'records' => [['email' => 'alex@example.com', 'role' => TeamRole::Admin->value]],
    ]));

    $display = pendingActionForTeam($this->user)->display_data;
    $labels = array_column($display['fields'] ?? [], 'label');

    expect($labels)->not->toContain('Name')
        ->and($labels)->toContain('Email')
        ->and(ProposalCoreFields::titleKey('team_invitations'))->toBe('email');
});

it('labels a resolved invitation by its email so the assistant can name it', function (): void {
    Mail::fake();

    (new InviteTeamMemberTool)->handle(new Request([
        'records' => [['email' => 'alex@example.com', 'role' => TeamRole::Admin->value]],
    ]));

    $pending = pendingActionForTeam($this->user);
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'team_id' => $this->team->getKey(),
        'participant_type' => $this->user->getMorphClass(),
        'participant_id' => (string) $this->user->getKey(),
        'title' => 'Invites',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pending->forceFill(['conversation_id' => $conversationId])->save();

    resolve(PendingActionService::class)->approve($pending->fresh(), $this->user);

    // This is what the next turn re-injects so the assistant can say who it
    // invited. Without the email fallback in recordLabel() it is null, and the
    // transcript calls the invitation "the record".
    $resolved = resolve(PendingActionService::class)->resolvedForConversation($conversationId, null);

    expect($resolved[0]['label'] ?? null)->toBe('alex@example.com');
});

it('names the entity in plain words on a batch card', function (): void {
    (new InviteTeamMemberTool)->handle(new Request([
        'records' => [
            ['email' => 'one@example.com', 'role' => TeamRole::Editor->value],
            ['email' => 'two@example.com', 'role' => TeamRole::Editor->value],
        ],
    ]));

    $display = pendingActionForTeam($this->user)->display_data;

    expect($display['summary'] ?? '')->not->toContain('team_invitations')
        ->and($display['summary'] ?? '')->toContain('team invitations');
});

it('keeps the mail transport failure off the card on the batch path too', function (): void {
    $transportMessage = 'Connection could not be established with host "smtp.internal.test:587": authentication failed for user "postmaster@relaticle"';

    Mail::shouldReceive('to')->andReturnSelf();
    Mail::shouldReceive('queue')->andThrow(new TransportException($transportMessage));

    app(InviteTeamMemberTool::class)->handle(new Request([
        'records' => [
            ['email' => 'first@example.com', 'role' => 'editor'],
            ['email' => 'second@example.com', 'role' => 'editor'],
        ],
    ]));

    $pending = pendingActionForTeam($this->user);

    $component = Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $pending->getKey(), context: 'conversation')
        ->call('createCurrent')
        ->assertHasErrors('resolve');

    $shown = $component->errors()->first('resolve');

    expect($shown)->not->toContain('smtp.internal.test')
        ->and($shown)->not->toContain('postmaster@relaticle')
        ->and($shown)->not->toContain('587');

    expect(TeamInvitation::query()->where('team_id', $this->team->getKey())->count())->toBe(0);
});
