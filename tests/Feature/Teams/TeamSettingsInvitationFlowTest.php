<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\EditTeam;
use App\Filament\Pages\Team\Members;
use App\Livewire\App\Teams\ManageMembers;
use App\Livewire\App\Teams\UpdateTeamName;
use App\Mail\TeamInvitationMail;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Livewire as LivewireComponent;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

test('the general tab owns workspace name and deletion', function () {
    $page = app(EditTeam::class);
    $page->tenant = $this->team;

    $components = collect($page->form(Schema::make($page))->getComponents())
        ->filter(fn ($c): bool => $c instanceof LivewireComponent)
        ->map(fn (LivewireComponent $c): string => $c->getComponent())
        ->all();

    expect($components)->toContain(UpdateTeamName::class)
        ->and($components)->not->toContain(ManageMembers::class);
});

test('the members tab owns invitations and membership', function () {
    $page = app(Members::class);

    $components = collect($page->form(Schema::make($page))->getComponents())
        ->filter(fn ($c): bool => $c instanceof LivewireComponent)
        ->map(fn (LivewireComponent $c): string => $c->getComponent())
        ->all();

    expect($components)->toContain(ManageMembers::class);
});

test('admin invites by email and the invitation appears in the pending list', function () {
    Mail::fake();

    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('invitePeople')->table(), [
            'invites' => [['email' => 'invitee@example.com', 'role' => 'editor']],
        ]);

    $invitation = $this->team->fresh()->teamInvitations->sole();

    expect($invitation->email)->toBe('invitee@example.com')
        ->and($invitation->role)->toBe('editor');

    livewire(ManageMembers::class, ['team' => $this->team])
        ->assertSee('invitee@example.com');

    Mail::assertQueued(TeamInvitationMail::class);
});

test('inviting keeps the admin on the members tab and refreshes the pending list', function () {
    Mail::fake();

    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('invitePeople')->table(), [
            'invites' => [['email' => 'invitee@example.com', 'role' => 'editor']],
        ])
        ->assertNoRedirect()
        ->assertSee('invitee@example.com');

    expect($this->team->fresh()->teamInvitations->pluck('email')->all())
        ->toBe(['invitee@example.com']);
});

test('admin can resend a pending invitation', function () {
    Mail::fake();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'pending@example.com',
    ]);

    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('resendTeamInvitation')->table('invite:'.$invitation->id))
        ->assertNotified(__('teams.notifications.team_invitation_sent.success'));

    Mail::assertQueued(TeamInvitationMail::class, fn ($mail) => $mail->hasTo('pending@example.com'));
});

test('admin can revoke a pending invitation', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
    ]);

    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('revokeTeamInvitation')->table('invite:'.$invitation->id))
        ->assertNotified(__('teams.notifications.team_invitation_revoked.success'));

    expect(TeamInvitation::query()->whereKey($invitation->getKey())->exists())->toBeFalse();
});

test('extend action is removed from pending invitations', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
    ]);

    livewire(ManageMembers::class, ['team' => $this->team])
        ->assertActionDoesNotExist(TestAction::make('extendTeamInvitation')->table('invite:'.$invitation->id));
});

test('onboarding-generated invite link still works for an authenticated user', function () {
    $owner = User::factory()->create();
    /** @var Team $team */
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $token = $team->invite_link_token;

    expect($token)->toBeString()->toHaveLength(40);

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->post(route('teams.join.confirm', ['token' => $token]))
        ->assertRedirect(Dashboard::getUrl(['tenant' => $team]));

    expect($team->fresh()->users()->where('users.id', $joiner->id)->exists())->toBeTrue()
        ->and($joiner->fresh()->current_team_id)->toBe($team->id);
});
