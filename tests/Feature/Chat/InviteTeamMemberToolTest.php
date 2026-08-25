<?php

declare(strict_types=1);

use App\Actions\Team\CreateTeamInvitation;
use App\Enums\TeamRole;
use App\Models\TeamInvitation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;
use Laravel\Jetstream\Mail\TeamInvitation as TeamInvitationMail;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\Team\InviteTeamMemberTool;

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

    Mail::assertSent(TeamInvitationMail::class);
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
