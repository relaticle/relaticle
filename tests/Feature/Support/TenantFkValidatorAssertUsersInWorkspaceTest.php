<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\TenantFkValidator;
use Illuminate\Validation\ValidationException;

mutates(TenantFkValidator::class);

it('assertUsersInWorkspace accepts ids belonging to the user team', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $teammate = User::factory()->create();
    $user->currentTeam->users()->attach($teammate, ['role' => 'editor']);

    TenantFkValidator::assertUsersInWorkspace($user, [
        'assignee_ids' => [$teammate->getKey()],
    ], ['assignee_ids']);

    expect(true)->toBeTrue();
});

it('assertUsersInWorkspace rejects ids belonging to a different team', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $outsider = User::factory()->withPersonalTeam()->create();

    expect(fn () => TenantFkValidator::assertUsersInWorkspace($user, [
        'assignee_ids' => [$outsider->getKey()],
    ], ['assignee_ids']))->toThrow(ValidationException::class);
});

it('assertUsersInWorkspace accepts the team owner as an assignee', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    TenantFkValidator::assertUsersInWorkspace($user, [
        'assignee_ids' => [$user->getKey()],
    ], ['assignee_ids']);

    expect(true)->toBeTrue();
});
