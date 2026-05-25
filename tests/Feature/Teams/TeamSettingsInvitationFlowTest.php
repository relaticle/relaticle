<?php

declare(strict_types=1);

use App\Filament\Pages\EditTeam;
use App\Livewire\App\Teams\AddTeamMember;
use App\Livewire\App\Teams\PendingTeamInvitations;
use App\Livewire\App\Teams\TeamMembers;
use App\Livewire\App\Teams\UpdateTeamName;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Livewire as LivewireComponent;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Mail;
use Laravel\Jetstream\Mail\TeamInvitation as TeamInvitationMail;

beforeEach(function () {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

test('team settings page does not render ManageInviteLink', function () {
    $page = app(EditTeam::class);
    $page->tenant = $this->team;

    $schema = $page->form(Schema::make($page));

    $components = collect($schema->getComponents())
        ->filter(fn ($c) => $c instanceof LivewireComponent)
        ->map(fn (LivewireComponent $c) => $c->getComponent())
        ->all();

    expect($components)->toContain(UpdateTeamName::class)
        ->and($components)->toContain(AddTeamMember::class)
        ->and($components)->toContain(PendingTeamInvitations::class)
        ->and($components)->toContain(TeamMembers::class)
        ->and($components)->not->toContain('App\\Livewire\\App\\Teams\\ManageInviteLink');
});

test('admin invites by email and the invitation appears in the pending list', function () {
    Mail::fake();

    livewire(AddTeamMember::class, ['team' => $this->team])
        ->fillForm([
            'email' => 'invitee@example.com',
            'role' => 'editor',
        ])
        ->call('addTeamMember', $this->team);

    $invitation = $this->team->fresh()->teamInvitations->sole();

    expect($invitation->email)->toBe('invitee@example.com')
        ->and($invitation->role)->toBe('editor');

    livewire(PendingTeamInvitations::class, ['team' => $this->team])
        ->assertCanSeeTableRecords([$invitation]);

    Mail::assertSent(TeamInvitationMail::class);
});

test('admin can resend a pending invitation', function () {
    Mail::fake();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'pending@example.com',
    ]);

    livewire(PendingTeamInvitations::class, ['team' => $this->team])
        ->callAction(TestAction::make('resendTeamInvitation')->table($invitation))
        ->assertNotified(__('teams.notifications.team_invitation_sent.success'));

    Mail::assertSent(TeamInvitationMail::class, fn ($mail) => $mail->hasTo('pending@example.com'));
});

test('admin can revoke a pending invitation', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
    ]);

    livewire(PendingTeamInvitations::class, ['team' => $this->team])
        ->callAction(TestAction::make('revokeTeamInvitation')->table($invitation))
        ->assertNotified(__('teams.notifications.team_invitation_revoked.success'));

    expect(TeamInvitation::query()->whereKey($invitation->getKey())->exists())->toBeFalse();
});

test('extend action is removed from pending invitations', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
    ]);

    livewire(PendingTeamInvitations::class, ['team' => $this->team])
        ->assertActionDoesNotExist(TestAction::make('extendTeamInvitation')->table($invitation));
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
        ->assertRedirect(config('fortify.home'));

    expect($team->fresh()->users()->where('users.id', $joiner->id)->exists())->toBeTrue()
        ->and($joiner->fresh()->current_team_id)->toBe($team->id);
});
