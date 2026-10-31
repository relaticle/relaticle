<?php

declare(strict_types=1);

use App\Contracts\Billing;
use App\Livewire\App\Workspaces\DeleteWorkspace;
use App\Models\User;
use App\Services\NoBilling;
use Filament\Actions\Action;
use Filament\Schemas\Components\Actions;
use Illuminate\Support\Facades\Notification;
use Laravel\Cashier\Subscription;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Relaticle\Cloud\Billing\StripeBilling;

mutates(DeleteWorkspace::class, NoBilling::class, StripeBilling::class);

function deleteWorkspaceModalDescription(Testable $component): string
{
    $actions = collect($component->instance()->form->getFlatComponents(withActions: true, withHidden: true))
        ->first(fn (mixed $child): bool => $child instanceof Actions);

    $action = collect($actions->getChildComponents())
        ->first(fn (mixed $child): bool => $child instanceof Action && $child->getName() === 'scheduleWorkspaceDeletionAction');

    return (string) $action->getModalDescription();
}

test('workspace owner can schedule workspace deletion', function () {
    Notification::fake();

    $this->actingAs($user = User::factory()->withWorkspace()->create());
    $workspace = $user->currentWorkspace;

    Livewire::test(DeleteWorkspace::class, ['workspace' => $workspace])
        ->call('deleteWorkspace', $workspace)
        ->assertRedirect();

    expect($workspace->refresh()->scheduled_deletion_at)->not->toBeNull();
});

test('workspace owner can cancel scheduled workspace deletion', function () {
    Notification::fake();

    $this->actingAs($user = User::factory()->withWorkspace()->create());
    $workspace = $user->currentWorkspace;
    $workspace->update(['scheduled_deletion_at' => now()->addDays(30)]);

    Livewire::test(DeleteWorkspace::class, ['workspace' => $workspace])
        ->call('cancelWorkspaceDeletion')
        ->assertRedirect();

    expect($workspace->refresh()->scheduled_deletion_at)->toBeNull();
});

test('personal workspaces cant be scheduled for deletion', function () {
    $this->actingAs($user = User::factory()->withPersonalWorkspace()->create());

    Livewire::test(DeleteWorkspace::class, ['workspace' => $user->personalWorkspace()])
        ->call('deleteWorkspace', $user->personalWorkspace())
        ->assertHasErrors(['workspace']);
});

test('the delete confirmation adds the billing notice for a subscribed workspace', function () {
    withHostedBilling();

    $this->actingAs($user = User::factory()->withWorkspace()->create());
    $workspace = $user->currentWorkspace;
    Subscription::factory()->active()->create(['workspace_id' => $workspace->getKey(), 'type' => 'default']);

    $description = deleteWorkspaceModalDescription(Livewire::test(DeleteWorkspace::class, ['workspace' => $workspace]));

    expect($description)->toContain(__('workspaces.modals.delete_workspace.notice'))
        ->and($description)->toContain((string) resolve(Billing::class)->deletionNotice($workspace));
});

test('the delete confirmation carries no billing notice while billing is off', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());
    $workspace = $user->currentWorkspace;
    Subscription::factory()->active()->create(['workspace_id' => $workspace->getKey(), 'type' => 'default']);

    $description = deleteWorkspaceModalDescription(Livewire::test(DeleteWorkspace::class, ['workspace' => $workspace]));

    expect($description)->toBe(__('workspaces.modals.delete_workspace.notice'));
});
