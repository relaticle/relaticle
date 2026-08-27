<?php

declare(strict_types=1);

use App\Actions\Jetstream\InviteTeamMember;
use App\Actions\Team\CreateTeamInvitation;
use App\Enums\TeamRole;
use App\Livewire\App\Teams\AddTeamMember;
use App\Livewire\App\Teams\PendingTeamInvitations;
use App\Models\TeamInvitation;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Mail\TeamInvitation as TeamInvitationMail;

mutates(User::class, InviteTeamMember::class, CreateTeamInvitation::class);

beforeEach(function () {
    Mail::fake();

    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

test('team members can be invited to team', function () {
    livewire(AddTeamMember::class, ['team' => $this->team])
        ->fillForm([
            'email' => 'test@example.com',
            'role' => 'admin',
        ])
        ->call('addTeamMember', $this->team);

    expect($this->team->fresh()->teamInvitations)->toHaveCount(1);

    $invitation = $this->team->fresh()->teamInvitations->first();
    expect($invitation->email)->toBe('test@example.com')
        ->and($invitation->role)->toBe('admin')
        ->and($invitation->expires_at)->not->toBeNull()
        ->and($invitation->expires_at->isFuture())->toBeTrue();
});

test('invitation expires_at is set based on config', function () {
    config(['jetstream.invitation_expiry_days' => 14]);

    livewire(AddTeamMember::class, ['team' => $this->team])
        ->fillForm([
            'email' => 'test@example.com',
            'role' => 'editor',
        ])
        ->call('addTeamMember', $this->team);

    $invitation = $this->team->fresh()->teamInvitations->first();
    expect((int) round($invitation->expires_at->diffInDays(now(), absolute: true)))->toBe(14);
});

test('team member invitations can be revoked', function () {
    livewire(AddTeamMember::class, ['team' => $this->team])
        ->fillForm([
            'email' => 'test@example.com',
            'role' => 'admin',
        ])
        ->call('addTeamMember', $this->team);

    expect($this->team->fresh()->teamInvitations)->toHaveCount(1);

    $invitation = $this->team->fresh()->teamInvitations->first();

    livewire(PendingTeamInvitations::class, ['team' => $this->team])
        ->callAction(TestAction::make('revokeTeamInvitation')->table($invitation));

    expect($this->team->fresh()->teamInvitations)->toHaveCount(0);
});

test('team members cannot be invited with a disposable email address', function () {
    livewire(AddTeamMember::class, ['team' => $this->team])
        ->fillForm([
            'email' => 'burner@mailinator.com',
            'role' => 'admin',
        ])
        ->call('addTeamMember', $this->team)
        ->assertNotified(__('validation.indisposable'));

    expect($this->team->fresh()->teamInvitations)->toHaveCount(0);
});

test('invite returns the created invitation', function () {
    $invitation = resolve(InviteTeamMember::class)->invite($this->user, $this->team, 'direct@example.com', 'admin');

    expect($invitation)->toBeInstanceOf(TeamInvitation::class)
        ->and($invitation->email)->toBe('direct@example.com')
        ->and($invitation->role)->toBe('admin')
        ->and($invitation->team_id)->toBe($this->team->getKey());
});

test('creates an invitation through the chat adapter action', function () {
    $invitation = resolve(CreateTeamInvitation::class)->execute(
        $this->user,
        ['email' => 'new@example.com', 'role' => TeamRole::Editor->value],
    );

    expect($invitation->email)->toBe('new@example.com')
        ->and($invitation->role)->toBe(TeamRole::Editor->value)
        ->and($invitation->team_id)->toBe($this->team->getKey());

    Mail::assertQueued(TeamInvitationMail::class);
});

test('the chat adapter action defaults to the editor role when none is given', function () {
    $invitation = resolve(CreateTeamInvitation::class)->execute(
        $this->user,
        ['email' => 'no-role@example.com'],
    );

    expect($invitation->role)->toBe(TeamRole::Editor->value);
});

test('the chat adapter action rejects an invitation for an existing team member', function () {
    $member = User::factory()->create();
    $this->team->users()->attach($member->getKey(), ['role' => TeamRole::Editor->value]);

    expect(fn () => resolve(CreateTeamInvitation::class)->execute(
        $this->user,
        ['email' => $member->email, 'role' => TeamRole::Editor->value],
    ))->toThrow(ValidationException::class);

    expect($this->team->fresh()->teamInvitations)->toHaveCount(0);
});

/**
 * The chat approval path calls this inside PendingActionService::approve()'s
 * transaction, which holds lockForUpdate() on the pending action. A
 * synchronous send put a third-party SMTP round trip inside that transaction
 * and let the provider's latency decide how long the row stayed locked:
 * measured at DB::transactionLevel() === 1 during MessageSending before the
 * fix. Queueing moves the send out; afterCommit keeps even the queue push
 * outside, so a rolled back approval cannot leave a real invitation in flight.
 */
test('queues the invitation mail rather than sending it inline', function () {
    resolve(InviteTeamMember::class)->invite($this->user, $this->team, 'queued@example.com', TeamRole::Editor->value);

    Mail::assertNotSent(TeamInvitationMail::class);
    Mail::assertQueued(TeamInvitationMail::class, fn (TeamInvitationMail $mail): bool => $mail->afterCommit === true);
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
        resolve(InviteTeamMember::class)->invite($this->user, $this->team, 'in-tx@example.com', TeamRole::Editor->value);
    });

    expect($levelAtDispatch)->toBeNull();
    Mail::assertQueued(TeamInvitationMail::class);
});
