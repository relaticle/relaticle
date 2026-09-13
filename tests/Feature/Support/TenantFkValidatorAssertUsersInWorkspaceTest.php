<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\TenantFkValidator;
use Illuminate\Validation\ValidationException;

mutates(TenantFkValidator::class);

it('assertUsersInWorkspace accepts ids belonging to the user workspace', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $teammate = User::factory()->create();
    $user->currentWorkspace->users()->attach($teammate, ['role' => 'editor']);

    TenantFkValidator::assertUsersInWorkspace($user, [
        'assignee_ids' => [$teammate->getKey()],
    ], ['assignee_ids']);

    expect(true)->toBeTrue();
});

it('assertUsersInWorkspace rejects ids belonging to a different workspace', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $outsider = User::factory()->withPersonalWorkspace()->create();

    expect(fn () => TenantFkValidator::assertUsersInWorkspace($user, [
        'assignee_ids' => [$outsider->getKey()],
    ], ['assignee_ids']))->toThrow(ValidationException::class);
});

it('assertUsersInWorkspace accepts the workspace owner as an assignee', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    TenantFkValidator::assertUsersInWorkspace($user, [
        'assignee_ids' => [$user->getKey()],
    ], ['assignee_ids']);

    expect(true)->toBeTrue();
});
