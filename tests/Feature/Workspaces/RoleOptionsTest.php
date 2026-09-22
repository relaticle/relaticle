<?php

declare(strict_types=1);

use App\Enums\WorkspaceCapability;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Support\Workspaces\RoleOptions;

mutates(RoleOptions::class);

test('offers admin only to a user who can promote admins', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $admin = User::factory()->create();
    $workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);

    expect(array_keys(RoleOptions::assignable($owner, $workspace)))
        ->toBe([WorkspaceRole::Admin->value, WorkspaceRole::Member->value, WorkspaceRole::Viewer->value])
        ->and(array_keys(RoleOptions::assignable($admin->fresh(), $workspace)))
        ->toBe([WorkspaceRole::Member->value, WorkspaceRole::Viewer->value]);
});

test('never offers admin on the invite link, even to the owner', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    expect(array_keys(RoleOptions::forInviteLink($owner, $workspace)))
        ->toBe([WorkspaceRole::Member->value, WorkspaceRole::Viewer->value]);
});

test('carries one hint per assignable role', function (): void {
    $owner = User::factory()->withWorkspace()->create();

    foreach (array_keys(RoleOptions::assignable($owner, $owner->currentWorkspace)) as $key) {
        expect(RoleOptions::descriptions()[$key] ?? '')->not->toBe('');
    }
});

test('every hint is one sentence with no trailing full stop', function (): void {
    expect(RoleOptions::descriptions())->each->not->toEndWith('.');
});

test('builds the matrix from the capability map', function (): void {
    $matrix = RoleOptions::matrix();

    expect($matrix[WorkspaceCapability::RecordsDelete->value][WorkspaceRole::Member->value])->toBeTrue()
        ->and($matrix[WorkspaceCapability::RecordsForceDelete->value][WorkspaceRole::Member->value])->toBeFalse()
        ->and($matrix[WorkspaceCapability::FieldsManage->value][WorkspaceRole::Admin->value])->toBeTrue()
        ->and($matrix[WorkspaceCapability::DataExport->value][WorkspaceRole::Viewer->value])->toBeFalse();
});

test('grants the owner column every capability in the matrix', function (): void {
    $matrix = RoleOptions::matrix();

    foreach (WorkspaceCapability::cases() as $capability) {
        expect($matrix[$capability->value]['owner'])->toBeTrue();
    }
});
