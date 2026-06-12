<?php

declare(strict_types=1);

use App\Models\People;
use App\Models\User;
use App\Support\TenantFkValidator;
use Illuminate\Validation\ValidationException;

it('passes when every id belongs to the user current team', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $a = People::factory()->for($user->currentTeam)->create();
    $b = People::factory()->for($user->currentTeam)->create();

    TenantFkValidator::assertOwnedMany($user, ['people_ids' => [(string) $a->id, (string) $b->id]], [
        'people_ids' => People::class,
    ]);

    expect(true)->toBeTrue();
});

it('throws when any id is from another team', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $other = User::factory()->withPersonalTeam()->create();
    $mine = People::factory()->for($user->currentTeam)->create();
    $foreign = People::factory()->for($other->currentTeam)->create();

    expect(fn () => TenantFkValidator::assertOwnedMany($user, [
        'people_ids' => [(string) $mine->id, (string) $foreign->id],
    ], [
        'people_ids' => People::class,
    ]))->toThrow(ValidationException::class);
});

it('skips empty arrays without throwing', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->currentTeam;

    TenantFkValidator::assertOwnedMany($user, ['people_ids' => []], ['people_ids' => People::class]);

    expect(true)->toBeTrue();
});

it('throws when the user has no current team', function (): void {
    $user = User::factory()->create();

    expect(fn () => TenantFkValidator::assertOwnedMany($user, ['people_ids' => ['01abc']], [
        'people_ids' => People::class,
    ]))->toThrow(ValidationException::class);
});

it('handles duplicate ids in the input array correctly', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $a = People::factory()->for($user->currentTeam)->create();

    TenantFkValidator::assertOwnedMany($user, ['people_ids' => [(string) $a->id, (string) $a->id]], [
        'people_ids' => People::class,
    ]);

    expect(true)->toBeTrue();
});
