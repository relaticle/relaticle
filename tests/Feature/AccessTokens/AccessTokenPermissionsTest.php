<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Features\EmailIntegration;
use App\Livewire\App\AccessTokens\ManageAccessTokens;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Jetstream\Features;
use Laravel\Pennant\Feature;

mutates(User::class);

test('api token permissions can be updated', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    $token = $user->tokens()->create([
        'name' => 'Test Token',
        'token' => Str::random(40),
        'abilities' => ['create', 'read'],
    ]);

    livewire(ManageAccessTokens::class)
        ->callTableAction('permissions', $token, data: [
            'permissions' => ['delete', 'update'],
        ]);

    $freshToken = $user->fresh()->tokens->first();

    expect($freshToken->abilities)->toBe(['delete', 'update']);
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('a viewer cannot widen a pinned token past read', function () {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $viewer = User::factory()->create();
    $workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($workspace);
    $this->actingAs($viewer = $viewer->fresh());

    $token = $viewer->tokens()->create([
        'name' => 'Viewer Token',
        'token' => Str::random(40),
        'abilities' => ['read'],
        'workspace_id' => $workspace->id,
    ]);

    livewire(ManageAccessTokens::class)
        ->callTableAction('permissions', $token, data: [
            'workspace_id' => $owner->personalWorkspace()?->id ?? $workspace->id,
            'permissions' => ['read', 'delete'],
        ]);

    expect($token->fresh()->abilities)->toBe(['read']);
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('table shows workspace name column', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    $user->tokens()->create([
        'name' => 'Test Token',
        'token' => Str::random(40),
        'abilities' => ['read'],
        'workspace_id' => $user->currentWorkspace->id,
    ]);

    livewire(ManageAccessTokens::class)
        ->assertCanRenderTableColumn('workspace.name');
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('table shows expiration column', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    $user->tokens()->create([
        'name' => 'Expiring Token',
        'token' => Str::random(40),
        'abilities' => ['read'],
        'expires_at' => now()->addDays(30),
    ]);

    livewire(ManageAccessTokens::class)
        ->assertCanRenderTableColumn('expires_at');
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

function tokenAbilitiesAfterEdit(User $user, string $workspaceId, array $permissions): array
{
    $token = $user->tokens()->create([
        'name' => 'Mail Token '.Str::random(6),
        'token' => Str::random(40),
        'abilities' => ['read'],
        'workspace_id' => $workspaceId,
    ]);

    livewire(ManageAccessTokens::class)
        ->callTableAction('permissions', $token, data: [
            'workspace_id' => $workspaceId,
            'permissions' => $permissions,
        ]);

    return $token->fresh()->abilities;
}

test('a member can give a pinned token every email ability', function () {
    $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);
    $member->switchWorkspace($workspace);
    $this->actingAs($member = $member->fresh());

    expect(tokenAbilitiesAfterEdit($member, $workspace->id, ['read', 'email:read', 'email:draft', 'email:send']))
        ->toBe(['read', 'email:read', 'email:draft', 'email:send']);
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('a viewer can give a token email read and draft but not send', function () {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $viewer = User::factory()->create();
    $workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($workspace);
    $this->actingAs($viewer = $viewer->fresh());

    expect(tokenAbilitiesAfterEdit($viewer, $workspace->id, ['read', 'email:read', 'email:draft']))
        ->toBe(['read', 'email:read', 'email:draft']);

    $token = $viewer->tokens()->create([
        'name' => 'Viewer Mail Token',
        'token' => Str::random(40),
        'abilities' => ['read'],
        'workspace_id' => $workspace->id,
    ]);

    livewire(ManageAccessTokens::class)
        ->callTableAction('permissions', $token, data: ['permissions' => ['read', 'email:send']])
        ->assertHasTableActionErrors(['permissions.1']);

    expect($token->fresh()->abilities)->toBe(['read']);
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('no email ability can be given to a token while the email feature is off', function () {
    Feature::define(EmailIntegration::class, false);

    $this->actingAs($user = User::factory()->withWorkspace()->create());

    $token = $user->tokens()->create([
        'name' => 'Flag Off Token',
        'token' => Str::random(40),
        'abilities' => ['read'],
        'workspace_id' => $user->currentWorkspace->id,
    ]);

    livewire(ManageAccessTokens::class)
        ->callTableAction('permissions', $token, data: ['permissions' => ['read', 'email:read']])
        ->assertHasTableActionErrors(['permissions.1']);

    expect($token->fresh()->abilities)->toBe(['read']);
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('a token holding an email ability stays editable once the email feature is off', function () {
    Feature::define(EmailIntegration::class, false);

    $this->actingAs($user = User::factory()->withWorkspace()->create());

    $token = $user->tokens()->create([
        'name' => 'Stale Flag Token',
        'token' => Str::random(40),
        'abilities' => ['read', 'delete', 'email:read'],
        'workspace_id' => $user->currentWorkspace->id,
    ]);

    livewire(ManageAccessTokens::class)
        ->callTableAction('permissions', $token)
        ->assertHasNoTableActionErrors();

    expect($token->fresh()->abilities)->toBe(['read', 'delete']);
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('a token stays editable after its holder is demoted below an ability it holds', function () {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);
    $member->switchWorkspace($workspace);

    $token = $member->tokens()->create([
        'name' => 'Demoted Token',
        'token' => Str::random(40),
        'abilities' => ['read', 'email:send'],
        'workspace_id' => $workspace->id,
    ]);

    $workspace->users()->updateExistingPivot($member->getKey(), ['role' => WorkspaceRole::Viewer->value]);
    $this->actingAs($member->fresh());

    livewire(ManageAccessTokens::class)
        ->callTableAction('permissions', $token)
        ->assertHasNoTableActionErrors();

    expect($token->fresh()->abilities)->toBe(['read']);
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('table shows the email ability label rather than its raw name', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    $user->tokens()->create([
        'name' => 'Label Token',
        'token' => Str::random(40),
        'abilities' => ['read', 'email:send'],
        'workspace_id' => $user->currentWorkspace->id,
    ]);

    livewire(ManageAccessTokens::class)
        ->assertSee('Send email')
        ->assertDontSee('Email:send');
})->skip(fn () => ! Features::hasApiFeatures(), 'API support is not enabled.');
