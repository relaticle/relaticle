<?php

declare(strict_types=1);

use App\Actions\Jetstream\RemoveWorkspaceMember;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\WorkspaceMemberRemovedNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Jetstream\Events\TeamMemberRemoved;
use Laravel\Jetstream\Http\Livewire\TeamMemberManager;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Jobs\IncrementalEmailSyncJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;

mutates(User::class);

test('workspace members can be removed from workspaces', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    $user->currentWorkspace->users()->attach(
        $otherUser = User::factory()->create(), ['role' => 'admin']
    );

    Livewire::test(TeamMemberManager::class, ['team' => $user->currentWorkspace])
        ->set('teamMemberIdBeingRemoved', $otherUser->id)
        ->call('removeTeamMember');

    expect($user->currentWorkspace->fresh()->users)->toBeEmpty();
});

test('removed workspace member receives notification', function () {
    Notification::fake();

    $this->actingAs($user = User::factory()->withWorkspace()->create());

    $user->currentWorkspace->users()->attach(
        $otherUser = User::factory()->create(), ['role' => 'admin']
    );

    Livewire::test(TeamMemberManager::class, ['team' => $user->currentWorkspace])
        ->set('teamMemberIdBeingRemoved', $otherUser->id)
        ->call('removeTeamMember');

    Notification::assertSentTo($otherUser, WorkspaceMemberRemovedNotification::class);
});

test('editor cannot remove workspace members', function () {
    $user = User::factory()->withWorkspace()->create();

    $user->currentWorkspace->users()->attach(
        $otherUser = User::factory()->create(), ['role' => 'member']
    );

    $this->actingAs($otherUser);

    Livewire::test(TeamMemberManager::class, ['team' => $user->currentWorkspace])
        ->set('teamMemberIdBeingRemoved', $user->id)
        ->call('removeTeamMember')
        ->assertStatus(403);
});

test('admin cannot remove the workspace owner', function () {
    Event::fake([TeamMemberRemoved::class]);

    $user = User::factory()->withWorkspace()->create();

    $user->currentWorkspace->users()->attach(
        $admin = User::factory()->create(), ['role' => 'admin']
    );

    $this->actingAs($admin);

    Livewire::test(TeamMemberManager::class, ['team' => $user->currentWorkspace])
        ->set('teamMemberIdBeingRemoved', $user->id)
        ->call('removeTeamMember');

    Event::assertNotDispatched(TeamMemberRemoved::class);
    expect($user->currentWorkspace->fresh()->owner->id)->toBe($user->id);
});

test('admin cannot remove a peer admin', function () {
    Event::fake([TeamMemberRemoved::class]);

    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $adminA = User::factory()->create();
    $workspace->users()->attach($adminA, ['role' => 'admin']);

    $adminB = User::factory()->create();
    $workspace->users()->attach($adminB, ['role' => 'admin']);

    $this->actingAs($adminA);

    Livewire::test(TeamMemberManager::class, ['team' => $workspace])
        ->set('teamMemberIdBeingRemoved', $adminB->id)
        ->call('removeTeamMember');

    Event::assertNotDispatched(TeamMemberRemoved::class);
    expect(WorkspaceRole::keyIsAdmin($adminB->fresh()->membershipRole($workspace->fresh())))->toBeTrue();
});

test('admin can still remove an editor', function () {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $admin = User::factory()->create();
    $workspace->users()->attach($admin, ['role' => 'admin']);

    $editor = User::factory()->create();
    $workspace->users()->attach($editor, ['role' => 'member']);

    $this->actingAs($admin);

    Livewire::test(TeamMemberManager::class, ['team' => $workspace])
        ->set('teamMemberIdBeingRemoved', $editor->id)
        ->call('removeTeamMember');

    expect($workspace->fresh()->users()->where('users.id', $editor->id)->exists())->toBeFalse();
});

test('admin can still leave the workspace themselves', function () {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $admin = User::factory()->create();
    $workspace->users()->attach($admin, ['role' => 'admin']);

    $this->actingAs($admin);

    Livewire::test(TeamMemberManager::class, ['team' => $workspace])
        ->set('teamMemberIdBeingRemoved', $admin->id)
        ->call('removeTeamMember');

    expect($workspace->fresh()->users()->where('users.id', $admin->id)->exists())->toBeFalse();
});

function syncingMailbox(User $member, Workspace $workspace, array $attributes = []): ConnectedAccount
{
    return ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $member->getKey(),
        'status' => EmailAccountStatus::ACTIVE,
        'sync_cursor' => 'cursor-1',
        ...$attributes,
    ]));
}

test('removing a member disconnects their mailbox and stops its sync', function () {
    Bus::fake([IncrementalEmailSyncJob::class]);
    Http::fake(['oauth2.googleapis.com/*' => Http::response()]);

    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $workspace->users()->attach($member = User::factory()->create(), ['role' => 'member']);
    $mailbox = syncingMailbox($member, $workspace);

    resolve(RemoveWorkspaceMember::class)->remove($owner, $workspace, $member);

    $this->artisan('email:incremental-sync');

    $mailbox = ConnectedAccount::withTrashed()->findOrFail($mailbox->getKey());

    expect($mailbox->trashed())->toBeTrue()
        ->and($mailbox->access_token)->toBeNull()
        ->and($mailbox->refresh_token)->toBeNull();

    Bus::assertNotDispatched(IncrementalEmailSyncJob::class);
});

test('removing a member leaves their mailbox in another workspace connected', function () {
    Http::fake();

    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $member = User::factory()->withPersonalWorkspace()->create();
    $workspace->users()->attach($member, ['role' => 'member']);

    $shared = ['email_address' => 'dana@northwind.test', 'provider_account_id' => 'google-123'];
    $removed = syncingMailbox($member, $workspace, $shared);
    $elsewhere = syncingMailbox($member, $member->currentWorkspace, $shared);
    $kept = Email::factory()->create([
        'workspace_id' => $member->current_workspace_id,
        'user_id' => $member->getKey(),
        'connected_account_id' => $elsewhere->getKey(),
    ]);

    resolve(RemoveWorkspaceMember::class)->remove($owner, $workspace, $member);

    expect(ConnectedAccount::withTrashed()->findOrFail($removed->getKey())->trashed())->toBeTrue()
        ->and($elsewhere->fresh()->trashed())->toBeFalse()
        ->and($elsewhere->fresh()->access_token)->not->toBeNull()
        ->and(Email::query()->whereKey($kept->getKey())->exists())->toBeTrue();

    Http::assertNothingSent();
});

test('removing a member leaves a teammate mailbox connected', function () {
    Http::fake();

    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $workspace->users()->attach($member = User::factory()->create(), ['role' => 'member']);
    $ownersMailbox = syncingMailbox($owner, $workspace);
    syncingMailbox($member, $workspace);

    resolve(RemoveWorkspaceMember::class)->remove($owner, $workspace, $member);

    expect($ownersMailbox->fresh()->trashed())->toBeFalse();
});

test('removing a member disconnects their other mailboxes when one disconnect fails', function () {
    Http::fake();
    Exceptions::fake();

    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $workspace->users()->attach($member = User::factory()->create(), ['role' => 'member']);
    $failing = syncingMailbox($member, $workspace, ['email_address' => 'first@northwind.test']);
    $other = syncingMailbox($member, $workspace, ['email_address' => 'second@northwind.test']);

    ConnectedAccount::deleting(function (ConnectedAccount $mailbox) use ($failing): void {
        throw_if($mailbox->is($failing), RuntimeException::class, 'The disconnect failed.');
    });

    resolve(RemoveWorkspaceMember::class)->remove($owner, $workspace, $member);

    expect($member->fresh()->belongsToWorkspace($workspace))->toBeFalse()
        ->and(ConnectedAccount::withTrashed()->findOrFail($other->getKey())->trashed())->toBeTrue()
        ->and($failing->fresh()->trashed())->toBeFalse();

    Exceptions::assertReported(RuntimeException::class);
});
