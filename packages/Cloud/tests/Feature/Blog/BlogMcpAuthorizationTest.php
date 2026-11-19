<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Relaticle\Ink\Mcp\BlogServer;
use Relaticle\Ink\Mcp\Tools\DeleteCategoryTool;
use Relaticle\Ink\Mcp\Tools\DeletePostTool;
use Relaticle\Ink\Models\Category;
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
    $user = User::factory()->withPersonalWorkspace()->create();

    expect(Gate::forUser($user)->allows('viewAny', Company::class))->toBeTrue();
});

it('denies blog deletion to a staff member demoted below super administrator', function (string $model, string $tool): void {
    $administrator = SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::Administrator]);
    $record = $model::factory()->create();

    BlogServer::actingAs($administrator, config('ink.mcp.guard'))
        ->tool($tool, ['id' => $record->getKey()])
        ->assertHasErrors(['This action is unauthorized.']);

    expect($record->refresh()->deleted_at)->toBeNull();
})->with([
    'posts' => [Post::class, DeletePostTool::class],
    'categories' => [Category::class, DeleteCategoryTool::class],
]);
