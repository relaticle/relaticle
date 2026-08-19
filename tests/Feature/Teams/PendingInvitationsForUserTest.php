<?php

declare(strict_types=1);

use App\Actions\Jetstream\AcceptTeamInvitation;
use App\Actions\Jetstream\DeclineTeamInvitation;
use App\Filament\Pages\Dashboard;
use App\Http\Middleware\ApplyTenantScopes;
use App\Livewire\App\Teams\PendingInvitationsForUser;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Jetstream\Events\TeamMemberAdded;

mutates(PendingInvitationsForUser::class, AcceptTeamInvitation::class, DeclineTeamInvitation::class);

test('an independently registered invitee sees their pending invitation', function (): void {
    $team = User::factory()->withTeam()->create()->currentTeam;
    $team->teamInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $invitee = User::factory()->create(['email' => 'later@example.test']);
    $this->actingAs($invitee);

    livewire(PendingInvitationsForUser::class)->assertSee($team->name);
});

test('a teamless invitee sees the card on the tenant-registration page', function (): void {
    // CreateTeam extends RegisterTenant, which uses a custom onboarding view that
    // bypasses the standard page component the card's PAGE_START hook fires from.
    // A user with current_team_id = null lands here (/app/new) first -- exactly
    // the case this card exists to catch someone before they build a throwaway
    // personal workspace instead of accepting the invitation waiting for them.
    $team = User::factory()->withTeam()->create()->currentTeam;
    $team->teamInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $invitee = User::factory()->create(['email' => 'later@example.test']);
    $this->actingAs($invitee);

    $response = $this->get(route('filament.app.tenant.registration'))->assertOk();
    $response->assertSee($team->name);

    expect(substr_count($response->getContent(), __('teams.pending_for_user.accept')))->toBe(1);
});

test('the card still renders exactly once on an ordinary panel page', function (): void {
    $inviter = User::factory()->withTeam()->create();
    $inviter->currentTeam->teamInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $invitee = User::factory()->withTeam()->create(['email' => 'later@example.test']);
    $this->actingAs($invitee);
    Filament::setTenant($invitee->currentTeam);

    $html = $this->get(Dashboard::getUrl(['tenant' => $invitee->currentTeam]))->assertOk()->getContent();

    expect(substr_count($html, __('teams.pending_for_user.accept')))->toBe(1);
});

test('accepting from the card joins the team', function (): void {
    $team = User::factory()->withTeam()->create()->currentTeam;
    $invitation = $team->teamInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $invitee = User::factory()->create(['email' => 'later@example.test']);
    $this->actingAs($invitee);

    livewire(PendingInvitationsForUser::class)->call('accept', $invitation->id);

    expect($invitee->fresh()->belongsToTeam($team))->toBeTrue();
    expect(TeamInvitation::query()->whereKey($invitation->id)->exists())->toBeFalse();
});

test('accepting still joins a team other than the ambient panel tenant', function (): void {
    // Regression: inside a real Filament panel request, ApplyTenantScopes
    // globally scopes the User model to members of whichever team the request
    // is currently bound to. The team being joined here is a *different* team
    // by definition, so every User lookup the accept path performs internally
    // (the inviting team's owner, membership checks) must not be blinded by
    // that scope. Livewire::test() alone never applies panel middleware, so
    // the scope is registered here exactly as ApplyTenantScopes would.
    $originalScopes = User::getAllGlobalScopes();

    try {
        $team = User::factory()->withTeam()->create()->currentTeam;
        $invitation = $team->teamInvitations()->create([
            'email' => 'later@example.test',
            'role' => 'editor',
            'expires_at' => now()->addDays(5),
        ]);

        $invitee = User::factory()->withTeam()->create(['email' => 'later@example.test']);
        $this->actingAs($invitee);

        Filament::setTenant($invitee->currentTeam, isQuiet: true);
        (new ApplyTenantScopes)->handle(request(), fn (Request $request): Request => $request);

        livewire(PendingInvitationsForUser::class)->call('accept', $invitation->id);

        expect($invitee->fresh()->belongsToTeam($team))->toBeTrue();
        expect(TeamInvitation::query()->whereKey($invitation->id)->exists())->toBeFalse();
    } finally {
        User::setAllGlobalScopes($originalScopes);
    }
});

test('suspending the User tenancy scope during accept does not affect other models', function (): void {
    // Regression: the scope suspension inside AcceptTeamInvitation must restore
    // only the one named scope entry it touched on User, not wipe out a scope
    // that some other model registered for the first time while it was
    // suspended (e.g. a lazily-booted #[ScopedBy] model). TeamMemberAdded fires
    // synchronously from inside AddTeamMember::add() -- squarely inside the
    // suspended window -- so registering a scope on an unrelated model there
    // proves the restore is class-scoped to User, not a global snapshot/restore.
    $originalUserScopes = User::getAllGlobalScopes();
    $markerScope = 'regression-marker-'.Str::random(8);

    try {
        $team = User::factory()->withTeam()->create()->currentTeam;
        $invitation = $team->teamInvitations()->create([
            'email' => 'later@example.test',
            'role' => 'editor',
            'expires_at' => now()->addDays(5),
        ]);

        $invitee = User::factory()->withTeam()->create(['email' => 'later@example.test']);
        $this->actingAs($invitee);

        Filament::setTenant($invitee->currentTeam, isQuiet: true);
        (new ApplyTenantScopes)->handle(request(), fn (Request $request): Request => $request);

        Event::listen(TeamMemberAdded::class, function () use ($markerScope): void {
            Team::addGlobalScope($markerScope, fn (Builder $query): Builder => $query);
        });

        livewire(PendingInvitationsForUser::class)->call('accept', $invitation->id);

        expect($invitee->fresh()->belongsToTeam($team))->toBeTrue()
            ->and(Team::hasGlobalScope($markerScope))->toBeTrue();
    } finally {
        User::setAllGlobalScopes($originalUserScopes);
    }
});

test('declining revokes the invitation without joining', function (): void {
    $team = User::factory()->withTeam()->create()->currentTeam;
    $invitation = $team->teamInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $invitee = User::factory()->create(['email' => 'later@example.test']);
    $this->actingAs($invitee);

    livewire(PendingInvitationsForUser::class)->call('decline', $invitation->id);

    expect(TeamInvitation::query()->whereKey($invitation->id)->exists())->toBeFalse()
        ->and($invitee->fresh()->belongsToTeam($team))->toBeFalse();
});

test('another users invitation is invisible and cannot be accepted', function (): void {
    $team = User::factory()->withTeam()->create()->currentTeam;
    $invitation = $team->teamInvitations()->create([
        'email' => 'someone@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $stranger = User::factory()->withTeam()->create();
    $this->actingAs($stranger);

    livewire(PendingInvitationsForUser::class)
        ->assertDontSee($team->name)
        ->call('accept', $invitation->id);

    expect($stranger->fresh()->belongsToTeam($team))->toBeFalse();
    expect(TeamInvitation::query()->whereKey($invitation->id)->exists())->toBeTrue();
});

test('another users invitation cannot be declined either', function (): void {
    $team = User::factory()->withTeam()->create()->currentTeam;
    $invitation = $team->teamInvitations()->create([
        'email' => 'someone@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $stranger = User::factory()->withTeam()->create();
    $this->actingAs($stranger);

    livewire(PendingInvitationsForUser::class)->call('decline', $invitation->id);

    expect(TeamInvitation::query()->whereKey($invitation->id)->exists())->toBeTrue();
});

test('an invitation email matching only by case can still be accepted', function (): void {
    $team = User::factory()->withTeam()->create()->currentTeam;
    $invitation = $team->teamInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $invitee = User::factory()->create(['email' => 'LATER@Example.Test']);
    $this->actingAs($invitee);

    livewire(PendingInvitationsForUser::class)
        ->assertSee($team->name)
        ->call('accept', $invitation->id);

    expect($invitee->fresh()->belongsToTeam($team))->toBeTrue();
});

test('an expired invitation is neither listed nor acceptable', function (): void {
    $team = User::factory()->withTeam()->create()->currentTeam;
    $invitation = $team->teamInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'editor',
        'expires_at' => now()->subDay(),
    ]);

    $invitee = User::factory()->create(['email' => 'later@example.test']);
    $this->actingAs($invitee);

    livewire(PendingInvitationsForUser::class)
        ->assertDontSee($team->name)
        ->call('accept', $invitation->id);

    expect($invitee->fresh()->belongsToTeam($team))->toBeFalse();
    expect(TeamInvitation::query()->whereKey($invitation->id)->exists())->toBeTrue();
});

test('an invitation with a null expiry is neither listed nor acceptable', function (): void {
    $team = User::factory()->withTeam()->create()->currentTeam;
    $invitation = $team->teamInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'editor',
        'expires_at' => null,
    ]);

    $invitee = User::factory()->create(['email' => 'later@example.test']);
    $this->actingAs($invitee);

    livewire(PendingInvitationsForUser::class)
        ->assertDontSee($team->name)
        ->call('accept', $invitation->id);

    expect($invitee->fresh()->belongsToTeam($team))->toBeFalse();
});

test('a user with no pending invitations sees an empty card', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    livewire(PendingInvitationsForUser::class)
        ->assertOk()
        ->assertDontSee(__('teams.pending_for_user.accept'))
        ->assertDontSee(__('teams.pending_for_user.decline'));
});
