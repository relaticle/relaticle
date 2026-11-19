<?php

declare(strict_types=1);

use Relaticle\Cloud\Commands\BlogMcpCommand;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(BlogMcpCommand::class);

it('no longer serves the blog mcp over http', function (): void {
    $this->postJson('/mcp/blog', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ])->assertNotFound();
});

it('refuses to start without exactly one super administrator', function (int $count): void {
    SystemAdministrator::factory()->count($count)->create(['role' => SystemAdministratorRole::SuperAdministrator]);
    SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::Administrator]);

    $this->artisan('blog:mcp')->assertExitCode(1);
})->with([
    'none' => 0,
    'two' => 2,
]);
