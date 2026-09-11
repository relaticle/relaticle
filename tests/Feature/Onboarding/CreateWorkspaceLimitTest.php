<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Filament\Pages\CreateWorkspace;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\WorkspacePolicy;
use Laravel\Pennant\Feature;

mutates(CreateWorkspace::class, WorkspacePolicy::class);

// This file is the coverage for demo seeding itself, so it opts back into the
// feature that TestCase switches off for the rest of the suite.
beforeEach(function (): void {
    Feature::define(OnboardSeed::class, true);
});

it('allows a fourth workspace under the default cap', function (): void {
    $user = User::factory()->create();

    Workspace::factory()->count(3)->create(['user_id' => $user->id]);

    $this->actingAs($user);

    $this->get(route('filament.app.tenant.registration'))->assertSuccessful();
});

it('explains the workspace limit instead of returning a bare 404', function (): void {
    // A low cap keeps the fixture small; the point is the behavior at the cap,
    // whatever the configured number happens to be.
    config()->set('relaticle.workspaces.max_owned_per_user', 3);

    $user = User::factory()->create();

    Workspace::factory()->count(3)->create(['user_id' => $user->id]);

    $this->actingAs($user);

    expect($user->refresh()->ownedWorkspaces()->count())->toBe(3);

    $this->get(route('filament.app.tenant.registration'))
        ->assertRedirect()
        ->assertSessionHas('filament.notifications');
});
