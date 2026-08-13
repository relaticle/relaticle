<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Relaticle\Ink\Models\Post;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Relaticle\SystemAdmin\Policies\CategoryPolicy;
use Relaticle\SystemAdmin\Policies\PostPolicy;

mutates(PostPolicy::class, CategoryPolicy::class);

it('lets a super administrator pass blog gates outside the sysadmin panel', function (): void {
    $admin = SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::SuperAdministrator]);

    expect(Gate::forUser($admin)->allows('create', Post::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('viewAny', Post::class))->toBeTrue();
});

it('denies regular users blog gates', function (): void {
    expect(Gate::forUser(User::factory()->create())->allows('create', Post::class))->toBeFalse();
});

it('does not interfere with a non-blog gate for a regular user', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    expect(Gate::forUser($user)->allows('viewAny', Company::class))->toBeTrue();
});
