<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\Pages\EditSystemAdministrator;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\RelationManagers\ApiTokensRelationManager;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(ApiTokensRelationManager::class);

beforeEach(function (): void {
    $this->admin = SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::SuperAdministrator]);
    $this->actingAs($this->admin, 'sysadmin');
    Filament::setCurrentPanel('sysadmin');
});

it('creates a token and keeps the hash only', function (): void {
    livewire(ApiTokensRelationManager::class, [
        'ownerRecord' => $this->admin,
        'pageClass' => EditSystemAdministrator::class,
    ])
        ->callAction(TestAction::make('create-token')->table(), [
            'name' => 'Claude Code',
            'abilities' => ['posts:read', 'posts:create'],
        ])
        ->assertHasNoActionErrors();

    expect($this->admin->tokens()->count())->toBe(1)
        ->and($this->admin->tokens()->first()->abilities)->toBe(['posts:read', 'posts:create']);
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
