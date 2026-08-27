<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\Pages\EditSystemAdministrator;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\RelationManagers\ApiTokensRelationManager;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Relaticle\SystemAdmin\Policies\PersonalAccessTokenPolicy;

mutates(ApiTokensRelationManager::class, PersonalAccessTokenPolicy::class);

beforeEach(function (): void {
    $this->admin = SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::SuperAdministrator]);
    $this->actingAs($this->admin, 'sysadmin');
    Filament::setCurrentPanel('sysadmin');
});

it('creates a token and persists only its sha256 hash', function (): void {
    $component = livewire(ApiTokensRelationManager::class, [
        'ownerRecord' => $this->admin,
        'pageClass' => EditSystemAdministrator::class,
    ])
        ->callAction(TestAction::make('create-token')->table(), [
            'name' => 'Claude Code',
            'abilities' => ['posts:read', 'posts:create'],
        ])
        ->assertHasNoActionErrors();

    $plainTextToken = (string) $component->get('plainTextToken');
    $secret = explode('|', $plainTextToken, 2)[1] ?? '';
    $persisted = $this->admin->tokens()->first();

    expect($this->admin->tokens()->count())->toBe(1)
        ->and($persisted->abilities)->toBe(['posts:read', 'posts:create'])
        ->and($plainTextToken)->not->toBeEmpty()
        ->and($persisted->token)->toBe(hash('sha256', $secret))
        ->and($persisted->token)->not->toBe($plainTextToken);
});

it('clears the plaintext token from component state once the "shown once" modal is closed', function (): void {
    $component = livewire(ApiTokensRelationManager::class, [
        'ownerRecord' => $this->admin,
        'pageClass' => EditSystemAdministrator::class,
    ])
        ->callAction(TestAction::make('create-token')->table(), [
            'name' => 'Claude Code',
            'abilities' => ['posts:read'],
        ])
        ->assertHasNoActionErrors();

    expect((string) $component->get('plainTextToken'))->not->toBeEmpty();

    // The "showCreatedToken" modal has no submit action (modalSubmitAction(false)),
    // so "Done"/Escape/backdrop all resolve to unmountAction() — never the ->after()
    // hook that used to live on the action, which never actually ran.
    $component->call('unmountAction')
        ->assertSet('plainTextToken', '');
});

it('revokes a token', function (): void {
    $this->admin->createToken('Old client', ['posts:read']);
    $token = $this->admin->tokens()->first();

    livewire(ApiTokensRelationManager::class, [
        'ownerRecord' => $this->admin,
        'pageClass' => EditSystemAdministrator::class,
    ])
        ->callTableAction('delete', $token)
        ->assertHasNoTableActionErrors();

    expect($this->admin->tokens()->count())->toBe(0);
});

it('never persists a token ability outside the eight ink checks, even a crafted wildcard', function (): void {
    // Filament's CheckboxList dehydrates an `abilities.*` => in(...) rule from the
    // same options() the enum populates (Concerns/CanBeValidated::dehydrateValidationRules()),
    // so this whole submission is rejected before the action closure runs. The
    // array_intersect() in the action closure is a second, independent layer that
    // enforces the same allowlist server-side regardless of whether that Filament
    // behaviour ever changes -- this test asserts the end-to-end guarantee (no
    // token is ever minted with an ability outside the eight), not which layer
    // caught it.
    livewire(ApiTokensRelationManager::class, [
        'ownerRecord' => $this->admin,
        'pageClass' => EditSystemAdministrator::class,
    ])
        ->callAction(TestAction::make('create-token')->table(), [
            'name' => 'Crafted',
            'abilities' => ['*', 'posts:read', 'not-a-real-ability'],
        ]);

    // Filament reports one error per invalid array element (mountedActions.0.data.abilities.0,
    // .2), so this asserts the outcome rather than a specific error key: nothing is minted.
    expect($this->admin->tokens()->count())->toBe(0);
});

it('refuses to mint a token bound to a different administrator', function (): void {
    $otherAdmin = SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::SuperAdministrator]);

    livewire(ApiTokensRelationManager::class, [
        'ownerRecord' => $otherAdmin,
        'pageClass' => EditSystemAdministrator::class,
    ])
        ->callAction(TestAction::make('create-token')->table(), [
            'name' => 'Cross-admin',
            'abilities' => ['posts:read'],
        ])
        ->assertForbidden();

    expect($otherAdmin->tokens()->count())->toBe(0);
});

it('denies viewing or revoking another administrator\'s token', function (): void {
    $otherAdmin = SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::SuperAdministrator]);
    $otherAdmin->createToken('Not yours', ['posts:read']);
    $otherToken = $otherAdmin->tokens()->first();

    expect(Gate::forUser($this->admin)->allows('view', $otherToken))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('delete', $otherToken))->toBeFalse();

    // Filament's DeleteAction authorizes per record automatically; denial hides the
    // button rather than leaving it clickable and 403ing after the fact.
    livewire(ApiTokensRelationManager::class, [
        'ownerRecord' => $otherAdmin,
        'pageClass' => EditSystemAdministrator::class,
    ])
        ->assertTableActionHidden('delete', $otherToken);

    expect($otherAdmin->tokens()->count())->toBe(1);
});

it('allows an administrator to view and revoke its own token', function (): void {
    $this->admin->createToken('Mine', ['posts:read']);
    $token = $this->admin->tokens()->first();

    expect(Gate::forUser($this->admin)->allows('view', $token))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('delete', $token))->toBeTrue();
});
