<?php

declare(strict_types=1);

use App\Actions\Jetstream\AcceptWorkspaceInvitation;
use App\Actions\Jetstream\DeclineWorkspaceInvitation;
use App\Filament\Pages\Dashboard;
use App\Http\Middleware\ApplyTenantScopes;
use App\Livewire\App\Workspaces\PendingInvitationsForUser;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Jetstream;

mutates(PendingInvitationsForUser::class, AcceptWorkspaceInvitation::class, DeclineWorkspaceInvitation::class);

test('an independently registered invitee sees their pending invitation', function (): void {
    $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $workspace->workspaceInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $invitee = User::factory()->create(['email' => 'later@example.test']);
    $this->actingAs($invitee);

    livewire(PendingInvitationsForUser::class)->assertSee($workspace->name);
});

test('the card names who invited them and what access they get', function (): void {
    $owner = User::factory()->withWorkspace()->create(['name' => 'Dana Okafor']);
    $workspace = $owner->currentWorkspace;
    $workspace->workspaceInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'viewer',
        'inviter_id' => $owner->id,
        'expires_at' => now()->addDays(5),
    ]);

    $invitee = User::factory()->create(['email' => 'later@example.test']);
    $this->actingAs($invitee);

    livewire(PendingInvitationsForUser::class)
        ->assertSee(__('workspaces.pending_for_user.detail_with_inviter', [
            'inviter' => 'Dana Okafor',
            'role' => Jetstream::findRole('viewer')?->name,
        ]));
});

test('the card still renders when the inviter account is gone', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $workspace->workspaceInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'editor',
        'inviter_id' => null,
        'expires_at' => now()->addDays(5),
    ]);

    $invitee = User::factory()->create(['email' => 'later@example.test']);
    $this->actingAs($invitee);

    livewire(PendingInvitationsForUser::class)
        ->assertSee(__('workspaces.pending_for_user.detail', [
            'role' => Jetstream::findRole('editor')?->name,
        ]));
});

test('a workspaceless invitee sees the card on the tenant-registration page', function (): void {
    $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $workspace->workspaceInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $invitee = User::factory()->create(['email' => 'later@example.test']);
    $this->actingAs($invitee);

    $response = $this->get(route('filament.app.tenant.registration'))->assertOk();
    $response->assertSee($workspace->name);

    expect(substr_count($response->getContent(), __('workspaces.pending_for_user.accept')))->toBe(1);
});

test('the card still renders exactly once on an ordinary panel page', function (): void {
    $inviter = User::factory()->withWorkspace()->create();
    $inviter->currentWorkspace->workspaceInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $invitee = User::factory()->withWorkspace()->create(['email' => 'later@example.test']);
    $this->actingAs($invitee);
    Filament::setTenant($invitee->currentWorkspace);

    $html = $this->get(Dashboard::getUrl(['tenant' => $invitee->currentWorkspace]))->assertOk()->getContent();

    expect(substr_count($html, __('workspaces.pending_for_user.accept')))->toBe(1);
});

test('accepting from the card joins the workspace', function (): void {
    $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $invitation = $workspace->workspaceInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $invitee = User::factory()->create(['email' => 'later@example.test']);
    $this->actingAs($invitee);

    livewire(PendingInvitationsForUser::class)->call('accept', $invitation->id);

    expect($invitee->fresh()->belongsToWorkspace($workspace))->toBeTrue();
    expect(WorkspaceInvitation::query()->whereKey($invitation->id)->exists())->toBeFalse();
});

test('accepting still joins a workspace other than the ambient panel tenant', function (): void {
    $originalScopes = User::getAllGlobalScopes();

    try {
        $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
        $invitation = $workspace->workspaceInvitations()->create([
            'email' => 'later@example.test',
            'role' => 'editor',
            'expires_at' => now()->addDays(5),
        ]);

        $invitee = User::factory()->withWorkspace()->create(['email' => 'later@example.test']);
        $this->actingAs($invitee);

        Filament::setTenant($invitee->currentWorkspace, isQuiet: true);
        (new ApplyTenantScopes)->handle(request(), fn (Request $request): Request => $request);

        livewire(PendingInvitationsForUser::class)->call('accept', $invitation->id);

        expect($invitee->fresh()->belongsToWorkspace($workspace))->toBeTrue();
        expect(WorkspaceInvitation::query()->whereKey($invitation->id)->exists())->toBeFalse();
    } finally {
        User::setAllGlobalScopes($originalScopes);
    }
});

test('suspending the User tenancy scope during accept does not affect other models', function (): void {
    $originalUserScopes = User::getAllGlobalScopes();
    $markerScope = 'regression-marker-'.Str::random(8);

    try {
        $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
        $invitation = $workspace->workspaceInvitations()->create([
            'email' => 'later@example.test',
            'role' => 'editor',
            'expires_at' => now()->addDays(5),
        ]);

        $invitee = User::factory()->withWorkspace()->create(['email' => 'later@example.test']);
        $this->actingAs($invitee);

        Filament::setTenant($invitee->currentWorkspace, isQuiet: true);
        (new ApplyTenantScopes)->handle(request(), fn (Request $request): Request => $request);

        Event::listen(TeamMemberAdded::class, function () use ($markerScope): void {
            Workspace::addGlobalScope($markerScope, fn (Builder $query): Builder => $query);
        });

        livewire(PendingInvitationsForUser::class)->call('accept', $invitation->id);

        expect($invitee->fresh()->belongsToWorkspace($workspace))->toBeTrue()
            ->and(Workspace::hasGlobalScope($markerScope))->toBeTrue();
    } finally {
        User::setAllGlobalScopes($originalUserScopes);
    }
});

test('declining revokes the invitation without joining', function (): void {
    $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $invitation = $workspace->workspaceInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $invitee = User::factory()->create(['email' => 'later@example.test']);
    $this->actingAs($invitee);

    livewire(PendingInvitationsForUser::class)->call('decline', $invitation->id);

    expect(WorkspaceInvitation::query()->whereKey($invitation->id)->exists())->toBeFalse()
        ->and($invitee->fresh()->belongsToWorkspace($workspace))->toBeFalse();
});

test('another users invitation is invisible and cannot be accepted', function (): void {
    $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $invitation = $workspace->workspaceInvitations()->create([
        'email' => 'someone@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $stranger = User::factory()->withWorkspace()->create();
    $this->actingAs($stranger);

    livewire(PendingInvitationsForUser::class)
        ->assertDontSee($workspace->name)
        ->call('accept', $invitation->id);

    expect($stranger->fresh()->belongsToWorkspace($workspace))->toBeFalse();
    expect(WorkspaceInvitation::query()->whereKey($invitation->id)->exists())->toBeTrue();
});

test('another users invitation cannot be declined either', function (): void {
    $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $invitation = $workspace->workspaceInvitations()->create([
        'email' => 'someone@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $stranger = User::factory()->withWorkspace()->create();
    $this->actingAs($stranger);

    livewire(PendingInvitationsForUser::class)->call('decline', $invitation->id);

    expect(WorkspaceInvitation::query()->whereKey($invitation->id)->exists())->toBeTrue();
});

test('an invitation email matching only by case can still be accepted', function (): void {
    $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $invitation = $workspace->workspaceInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $invitee = User::factory()->create(['email' => 'LATER@Example.Test']);
    $this->actingAs($invitee);

    livewire(PendingInvitationsForUser::class)
        ->assertSee($workspace->name)
        ->call('accept', $invitation->id);

    expect($invitee->fresh()->belongsToWorkspace($workspace))->toBeTrue();
});

test('an expired invitation is neither listed nor acceptable', function (): void {
    $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $invitation = $workspace->workspaceInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'editor',
        'expires_at' => now()->subDay(),
    ]);

    $invitee = User::factory()->create(['email' => 'later@example.test']);
    $this->actingAs($invitee);

    livewire(PendingInvitationsForUser::class)
        ->assertDontSee($workspace->name)
        ->call('accept', $invitation->id);

    expect($invitee->fresh()->belongsToWorkspace($workspace))->toBeFalse();
    expect(WorkspaceInvitation::query()->whereKey($invitation->id)->exists())->toBeTrue();
});

test('an invitation with a null expiry is neither listed nor acceptable', function (): void {
    $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $invitation = $workspace->workspaceInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'editor',
        'expires_at' => null,
    ]);

    $invitee = User::factory()->create(['email' => 'later@example.test']);
    $this->actingAs($invitee);

    livewire(PendingInvitationsForUser::class)
        ->assertDontSee($workspace->name)
        ->call('accept', $invitation->id);

    expect($invitee->fresh()->belongsToWorkspace($workspace))->toBeFalse();
});

test('a user scheduled for deletion cannot accept from the card', function (): void {
    $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $invitation = $workspace->workspaceInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $invitee = User::factory()->scheduledForDeletion()->create(['email' => 'later@example.test']);
    $this->actingAs($invitee);

    livewire(PendingInvitationsForUser::class)
        ->call('accept', $invitation->id)
        ->assertNotified(__('workspaces.accept.account_deleting'));

    expect($invitee->fresh()->belongsToWorkspace($workspace))->toBeFalse();
    expect(WorkspaceInvitation::query()->whereKey($invitation->id)->exists())->toBeTrue();
});

test('a workspace scheduled for deletion cannot be joined from the card', function (): void {
    $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $workspace->forceFill(['scheduled_deletion_at' => now()->addDays(30)])->save();

    $invitation = $workspace->workspaceInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $invitee = User::factory()->create(['email' => 'later@example.test']);
    $this->actingAs($invitee);

    livewire(PendingInvitationsForUser::class)
        ->call('accept', $invitation->id)
        ->assertNotified(__('workspaces.accept.workspace_deleting'));

    expect($invitee->fresh()->belongsToWorkspace($workspace))->toBeFalse();
    expect(WorkspaceInvitation::query()->whereKey($invitation->id)->exists())->toBeTrue();
});

test('a user with no pending invitations sees an empty card', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    livewire(PendingInvitationsForUser::class)
        ->assertOk()
        ->assertDontSee(__('workspaces.pending_for_user.accept'))
        ->assertDontSee(__('workspaces.pending_for_user.decline'));
});
