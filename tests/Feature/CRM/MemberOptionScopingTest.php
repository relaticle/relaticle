<?php

declare(strict_types=1);

use App\Filament\Resources\TaskResource;
use App\Http\Middleware\ApplyTenantScopes;
use App\Models\User;

mutates(ApplyTenantScopes::class);

it('scopes user options to the tenant once a panel request has run', function (): void {
    $member = User::factory()->withTeam()->create();
    $outsiders = User::factory()->count(3)->create();

    // Before any panel request the scope is not registered.
    expect(User::query()->count())->toBe(4);

    $this->actingAs($member)->get(
        TaskResource::getUrl('index', tenant: $member->currentTeam),
    );

    // ApplyTenantScopes is persistent tenant middleware, so the scope is now
    // registered on the static model for the remainder of this test.
    expect(User::query()->pluck('id')->all())->toBe([$member->getKey()])
        ->and(User::query()->whereKey($outsiders->modelKeys())->count())->toBe(0);
});
