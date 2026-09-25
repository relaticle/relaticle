<?php

declare(strict_types=1);

use App\Filament\Pages\CreateWorkspace;
use App\Livewire\App\Workspaces\UpdateWorkspaceName;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceUrlPrefix;
use Filament\Facades\Filament;
use Illuminate\Support\Uri;
use Livewire\Livewire;

mutates(UpdateWorkspaceName::class, WorkspaceUrlPrefix::class);

test('workspace name and slug can be updated', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    Livewire::test(UpdateWorkspaceName::class, ['workspace' => $user->currentWorkspace])
        ->fillForm(['name' => 'New Name', 'slug' => 'new-name'])
        ->call('updateWorkspaceName', $user->currentWorkspace)
        ->assertHasNoFormErrors();

    $workspace = $user->currentWorkspace->fresh();

    expect($workspace->name)->toBe('New Name')
        ->and($workspace->slug)->toBe('new-name');
});

test('slug is required', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    Livewire::test(UpdateWorkspaceName::class, ['workspace' => $user->currentWorkspace])
        ->fillForm(['name' => 'Test Workspace', 'slug' => ''])
        ->call('updateWorkspaceName', $user->currentWorkspace)
        ->assertHasFormErrors(['slug' => 'required']);
});

test('slug must be at least 3 characters', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    Livewire::test(UpdateWorkspaceName::class, ['workspace' => $user->currentWorkspace])
        ->fillForm(['name' => 'Test Workspace', 'slug' => 'ab'])
        ->call('updateWorkspaceName', $user->currentWorkspace)
        ->assertHasFormErrors(['slug']);
});

test('slug must match valid format', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    Livewire::test(UpdateWorkspaceName::class, ['workspace' => $user->currentWorkspace])
        ->fillForm(['name' => 'Test Workspace', 'slug' => 'Invalid Slug!'])
        ->call('updateWorkspaceName', $user->currentWorkspace)
        ->assertHasFormErrors(['slug']);
});

test('reserved slug is rejected on workspace update', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    Livewire::test(UpdateWorkspaceName::class, ['workspace' => $user->currentWorkspace])
        ->fillForm(['name' => 'Admin', 'slug' => 'admin'])
        ->call('updateWorkspaceName', $user->currentWorkspace)
        ->assertHasFormErrors(['slug']);
});

test('slug must be unique across workspaces', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    Workspace::factory()->create(['slug' => 'taken-slug']);

    Livewire::test(UpdateWorkspaceName::class, ['workspace' => $user->currentWorkspace])
        ->fillForm(['name' => 'Test Workspace', 'slug' => 'taken-slug'])
        ->call('updateWorkspaceName', $user->currentWorkspace)
        ->assertHasFormErrors(['slug']);
});

test('workspace can keep its own slug on update', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    $currentSlug = $user->currentWorkspace->slug;

    Livewire::test(UpdateWorkspaceName::class, ['workspace' => $user->currentWorkspace])
        ->fillForm(['name' => 'Different Name', 'slug' => $currentSlug])
        ->call('updateWorkspaceName', $user->currentWorkspace)
        ->assertHasNoFormErrors()
        ->assertNotified();
});

test('slug change triggers redirect and still notifies', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    Livewire::test(UpdateWorkspaceName::class, ['workspace' => $user->currentWorkspace])
        ->fillForm(['name' => 'New Name', 'slug' => 'completely-new-slug'])
        ->call('updateWorkspaceName', $user->currentWorkspace)
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();
});

test('same slug does not trigger redirect', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    $currentSlug = $user->currentWorkspace->slug;

    Livewire::test(UpdateWorkspaceName::class, ['workspace' => $user->currentWorkspace])
        ->fillForm(['name' => 'Different Name', 'slug' => $currentSlug])
        ->call('updateWorkspaceName', $user->currentWorkspace)
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertNoRedirect();
});

test('the slug field is prefixed with the workspace address', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    $panel = Filament::getPanel('app');
    $host = Uri::of((string) config('app.url'))->host();

    expect(WorkspaceUrlPrefix::get())->toBe($host.'/'.$panel->getPath().'/');

    Livewire::test(UpdateWorkspaceName::class, ['workspace' => $user->currentWorkspace])
        ->assertSuccessful()
        ->assertSee(WorkspaceUrlPrefix::get());
});

test('the create workspace wizard shows the same slug prefix', function () {
    $this->actingAs(User::factory()->withWorkspace()->create());

    Livewire::test(CreateWorkspace::class)
        ->assertSuccessful()
        ->assertSee(WorkspaceUrlPrefix::get());
});
