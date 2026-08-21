<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\EditTeam;
use App\Filament\Pages\Team\Members;
use App\Livewire\App\Teams\InviteTeamMembers;
use App\Livewire\App\Teams\PendingTeamInvitations;
use App\Livewire\App\Teams\TeamMembers;
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
        ->and($components)->not->toContain(TeamMembers::class)
        ->and($components)->not->toContain(PendingTeamInvitations::class);
});

test('the members tab owns invitations and membership', function () {
    $page = app(Members::class);

    $components = collect($page->form(Schema::make($page))->getComponents())
        ->filter(fn ($c): bool => $c instanceof LivewireComponent)
        ->map(fn (LivewireComponent $c): string => $c->getComponent())
        ->all();

    expect($components)->toContain(TeamMembers::class)
        ->and($components)->toContain(PendingTeamInvitations::class);
});

test('the pending invitations card is listed above the members table', function () {
    $page = app(Members::class);

    $components = collect($page->form(Schema::make($page))->getComponents())
        ->filter(fn ($c): bool => $c instanceof LivewireComponent)
        ->map(fn (LivewireComponent $c): string => $c->getComponent())
        ->values()
        ->all();

    expect(array_search(PendingTeamInvitations::class, $components, true))
        ->toBeLessThan(array_search(TeamMembers::class, $components, true));
});

test('admin invites by email and the invitation appears in the pending list', function () {
    Mail::fake();

    livewire(InviteTeamMembers::class, ['team' => $this->team])
        ->fillForm([
            'emails' => 'invitee@example.com',
            'role' => 'editor',
        ])
        ->call('invitePeople');

    $invitation = $this->team->fresh()->teamInvitations->sole();

    expect($invitation->email)->toBe('invitee@example.com')
        ->and($invitation->role)->toBe('editor');

    livewire(PendingTeamInvitations::class, ['team' => $this->team])
        ->assertSee('invitee@example.com');

    Mail::assertQueued(TeamInvitationMail::class);
});

test('inviting keeps the admin on the members tab and announces the new invitation', function () {
    Mail::fake();

    livewire(InviteTeamMembers::class, ['team' => $this->team])
        ->fillForm([
            'emails' => 'invitee@example.com',
            'role' => 'editor',
        ])
        ->call('invitePeople')
        ->assertNoRedirect()
        ->assertDispatched('teamInvitationSent');

    expect($this->team->fresh()->teamInvitations->pluck('email')->all())
        ->toBe(['invitee@example.com']);
});

test('the pending card picks up an invitation announced by the members table', function () {
    Mail::fake();

    $pendingCard = livewire(PendingTeamInvitations::class, ['team' => $this->team]);

    $pendingCard->assertDontSee(__('teams.sections.pending_team_invitations.title'));

    livewire(InviteTeamMembers::class, ['team' => $this->team])
        ->fillForm([
            'emails' => 'invitee@example.com',
            'role' => 'editor',
        ])
        ->call('invitePeople');

    $pendingCard->call('refreshInvitations')
        ->assertSee('invitee@example.com');
});

test('admin can resend a pending invitation', function () {
    Mail::fake();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'pending@example.com',
    ]);

    livewire(PendingTeamInvitations::class, ['team' => $this->team])
        ->callAction(TestAction::make('resendTeamInvitation')->table($invitation->id))
        ->assertNotified(__('teams.notifications.team_invitation_sent.success'));

    Mail::assertQueued(TeamInvitationMail::class, fn ($mail) => $mail->hasTo('pending@example.com'));
});

test('admin can revoke a pending invitation', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
    ]);

    livewire(PendingTeamInvitations::class, ['team' => $this->team])
        ->callAction(TestAction::make('revokeTeamInvitation')->table($invitation->id))
        ->assertNotified(__('teams.notifications.team_invitation_revoked.success'));

    expect(TeamInvitation::query()->whereKey($invitation->getKey())->exists())->toBeFalse();
});

test('extend action is removed from pending invitations', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
    ]);

    livewire(PendingTeamInvitations::class, ['team' => $this->team])
        ->assertActionDoesNotExist(TestAction::make('extendTeamInvitation')->table($invitation->id));
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
