<?php

declare(strict_types=1);

use App\Models\People;
use App\Models\User;
use App\Support\TenantFkValidator;
use Illuminate\Validation\ValidationException;

it('passes when every id belongs to the user current workspace', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $a = People::factory()->for($user->currentWorkspace)->create();
    $b = People::factory()->for($user->currentWorkspace)->create();

    TenantFkValidator::assertOwnedMany($user, ['people_ids' => [(string) $a->id, (string) $b->id]], [
        'people_ids' => People::class,
    ]);

    expect(true)->toBeTrue();
});

it('throws when any id is from another workspace', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $other = User::factory()->withPersonalWorkspace()->create();
    $mine = People::factory()->for($user->currentWorkspace)->create();
    $foreign = People::factory()->for($other->currentWorkspace)->create();

    expect(fn () => TenantFkValidator::assertOwnedMany($user, [
        'people_ids' => [(string) $mine->id, (string) $foreign->id],
    ], [
        'people_ids' => People::class,
    ]))->toThrow(ValidationException::class);
});

it('skips empty arrays without throwing', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $user->currentWorkspace;

    TenantFkValidator::assertOwnedMany($user, ['people_ids' => []], ['people_ids' => People::class]);

    expect(true)->toBeTrue();
});

it('throws when the user has no current workspace', function (): void {
    $user = User::factory()->create();

    expect(fn () => TenantFkValidator::assertOwnedMany($user, ['people_ids' => ['01abc']], [
        'people_ids' => People::class,
    ]))->toThrow(ValidationException::class);
});

it('handles duplicate ids in the input array correctly', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $a = People::factory()->for($user->currentWorkspace)->create();

    TenantFkValidator::assertOwnedMany($user, ['people_ids' => [(string) $a->id, (string) $a->id]], [
        'people_ids' => People::class,
    ]);

    expect(true)->toBeTrue();
});
