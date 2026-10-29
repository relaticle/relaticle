<?php

declare(strict_types=1);

use App\Actions\Jetstream\CancelWorkspaceDeletion;
use App\Actions\Jetstream\ScheduleWorkspaceDeletion;
use App\Enums\WorkspaceRole;
use App\Filament\Pages\Dashboard;
use App\Livewire\App\Workspaces\WorkspaceDeletionBanner;
use App\Models\User;
use App\Notifications\WorkspaceDeletionCancelledNotification;
use App\Notifications\WorkspaceDeletionScheduledNotification;
use Filament\Actions\Action;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Subscription;

mutates(ScheduleWorkspaceDeletion::class, CancelWorkspaceDeletion::class, WorkspaceDeletionBanner::class);

test('workspace owner can schedule workspace deletion', function () {
    Notification::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;

    resolve(ScheduleWorkspaceDeletion::class)->schedule($user, $workspace);

    expect($workspace->refresh()->scheduled_deletion_at)->not->toBeNull()
        ->and($workspace->scheduled_deletion_at->isSameDay(now()->addDays(config('relaticle.deletion.grace_period_days'))))->toBeTrue();
});

test('workspace owner is notified when workspace is scheduled for deletion', function () {
    Notification::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'member']);

    resolve(ScheduleWorkspaceDeletion::class)->schedule($user, $workspace);

    Notification::assertSentTo($user, WorkspaceDeletionScheduledNotification::class);
    Notification::assertNotSentTo($member, WorkspaceDeletionScheduledNotification::class);
});

test('pending invitations are cancelled when workspace deletion is scheduled', function () {
    Notification::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->workspaceInvitations()->create([
        'email' => 'invited@example.com',
        'role' => 'member',
        'expires_at' => now()->addDays(7),
    ]);

    expect($workspace->workspaceInvitations)->toHaveCount(1);

    resolve(ScheduleWorkspaceDeletion::class)->schedule($user, $workspace);

    expect($workspace->refresh()->workspaceInvitations)->toBeEmpty();
});

test('personal workspace cannot be directly scheduled for deletion', function () {
    Notification::fake();

    $user = User::factory()->withPersonalWorkspace()->create();
    $personalWorkspace = $user->personalWorkspace();

    expect(fn () => resolve(ScheduleWorkspaceDeletion::class)->schedule($user, $personalWorkspace))
        ->toThrow(ValidationException::class);
});

test('non-owner cannot schedule workspace deletion', function () {
    Notification::fake();

    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'member']);

    expect(fn () => resolve(ScheduleWorkspaceDeletion::class)->schedule($member, $workspace))
        ->toThrow(AuthorizationException::class);
});

test('workspace owner can cancel workspace deletion', function () {
    Notification::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['scheduled_deletion_at' => now()->addDays(30)])->save();
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'member']);

    resolve(CancelWorkspaceDeletion::class)->cancel($user, $workspace);

    expect($workspace->refresh()->scheduled_deletion_at)->toBeNull();

    Notification::assertSentTo($user, WorkspaceDeletionCancelledNotification::class);
    Notification::assertNotSentTo($member, WorkspaceDeletionCancelledNotification::class);
});

test('every page of a workspace scheduled for deletion says when it will be deleted and lets the owner cancel', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $workspace->forceFill(['scheduled_deletion_at' => now()->addDays(14)])->save();

    $this->actingAs($owner)
        ->get(Dashboard::getUrl(['tenant' => $workspace]))
        ->assertOk()
        ->assertSee(__('workspaces.deletion_banner.message', ['date' => $workspace->scheduled_deletion_at->format('F j, Y')]))
        ->assertSee(__('workspaces.actions.cancel_deletion'));
});

test('the owner cancels the deletion from the banner through the same confirmation as workspace settings', function (): void {
    Notification::fake();

    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $workspace->forceFill(['scheduled_deletion_at' => now()->addDays(14)])->save();

    $this->actingAs($owner);

    livewire(WorkspaceDeletionBanner::class, ['workspace' => $workspace])
        ->assertActionExists('cancelDeletion', fn (Action $action): bool => $action->getModalHeading() === __('workspaces.modals.cancel_deletion.heading'))
        ->mountAction('cancelDeletion')
        ->assertActionMounted('cancelDeletion')
        ->callMountedAction()
        ->assertRedirect();

    expect($workspace->refresh()->scheduled_deletion_at)->toBeNull();
    Notification::assertSentTo($owner, WorkspaceDeletionCancelledNotification::class);
});

test('a member of a workspace scheduled for deletion sees the date but no cancel link', function (): void {
    $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $workspace->forceFill(['scheduled_deletion_at' => now()->addDays(14)])->save();

    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);
    $member->switchWorkspace($workspace);

    $this->actingAs($member->fresh())
        ->get(Dashboard::getUrl(['tenant' => $workspace]))
        ->assertOk()
        ->assertSee(__('workspaces.deletion_banner.message', ['date' => $workspace->scheduled_deletion_at->format('F j, Y')]))
        ->assertDontSee(__('workspaces.actions.cancel_deletion'));

    livewire(WorkspaceDeletionBanner::class, ['workspace' => $workspace])
        ->assertActionHidden('cancelDeletion');

    expect($workspace->refresh()->scheduled_deletion_at)->not->toBeNull();
});

test('a workspace that is not scheduled for deletion shows no deletion banner', function (): void {
    $owner = User::factory()->withWorkspace()->create();

    $this->actingAs($owner)
        ->get(Dashboard::getUrl(['tenant' => $owner->currentWorkspace]))
        ->assertOk()
        ->assertDontSeeLivewire(WorkspaceDeletionBanner::class);
});

test('a workspace on an open managed plan is not scheduled for deletion', function (string $type) {
    Notification::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    Subscription::factory()->active()->create(['workspace_id' => $workspace->getKey(), 'type' => $type]);

    expect(fn () => resolve(ScheduleWorkspaceDeletion::class)->schedule($user, $workspace))
        ->toThrow(ValidationException::class, __('workspaces.validation.managed_plan_before_deleting'));

    expect($workspace->refresh()->scheduled_deletion_at)->toBeNull();
})->with(['managed', 'managed_pending']);

test('a workspace whose managed plan ended can be scheduled for deletion', function () {
    Notification::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    Subscription::factory()->canceled()->create(['workspace_id' => $workspace->getKey(), 'type' => 'managed']);

    resolve(ScheduleWorkspaceDeletion::class)->schedule($user, $workspace);

    expect($workspace->refresh()->scheduled_deletion_at)->not->toBeNull();
});
