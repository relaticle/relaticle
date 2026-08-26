<?php

declare(strict_types=1);

use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\BaseAttachTool;
use App\Mcp\Tools\BaseCreateTool;
use App\Mcp\Tools\BaseDeleteTool;
use App\Mcp\Tools\BaseDetachTool;
use App\Mcp\Tools\BaseListTool;
use App\Mcp\Tools\BaseShowTool;
use App\Mcp\Tools\BaseUpdateTool;
use App\Mcp\Tools\Company\CreateCompanyTool;
use App\Mcp\Tools\Company\DeleteCompanyTool;
use App\Mcp\Tools\Company\GetCompanyTool;
use App\Mcp\Tools\Company\ListCompaniesTool;
use App\Mcp\Tools\Company\UpdateCompanyTool;
use App\Mcp\Tools\Note\AttachNoteToEntitiesTool;
use App\Mcp\Tools\Note\DetachNoteFromEntitiesTool;
use App\Mcp\Tools\Task\AttachTaskToEntitiesTool;
use App\Mcp\Tools\Task\CreateTaskTool;
use App\Mcp\Tools\Task\UpdateTaskTool;
use Laravel\Mcp\Server\Tool;

mutates(
    BaseListTool::class,
    BaseShowTool::class,
    BaseCreateTool::class,
    BaseUpdateTool::class,
    BaseDeleteTool::class,
    BaseAttachTool::class,
    BaseDetachTool::class,
);

dataset('annotation_matrix', [
    [ListCompaniesTool::class, ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],
    [GetCompanyTool::class, ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],
    [CreateCompanyTool::class, ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false]],
    [UpdateCompanyTool::class, ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false]],
    [DeleteCompanyTool::class, ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false]],
    [AttachNoteToEntitiesTool::class, ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],
    [DetachNoteFromEntitiesTool::class, ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false]],
    [CreateTaskTool::class, ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true]],
    [UpdateTaskTool::class, ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => true]],
    [AttachTaskToEntitiesTool::class, ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true]],
]);

it('exposes the required annotation matrix', function (string $toolClass, array $expected): void {
    $tool = new $toolClass;
    assert($tool instanceof Tool);

    $annotations = $tool->annotations();

    expect($annotations)->toBe($expected);
})->with('annotation_matrix');

// The per-category matrix above only covers the classes someone remembered to list,
// which is how WhoAmiTool shipped without openWorldHint. Enumerate the server's own
// registration instead so a new tool cannot opt out of the submission policy by
// simply not being added to the dataset.
it('declares all four annotation hints on every registered tool', function (): void {
    $tools = new ReflectionClass(RelaticleServer::class)
        ->getDefaultProperties()['tools'];

    expect($tools)->toBeArray()->not->toBeEmpty();

    foreach ($tools as $toolClass) {
        $annotations = resolve($toolClass)->annotations();

        expect($annotations)->toHaveKeys([
            'readOnlyHint',
            'destructiveHint',
            'idempotentHint',
            'openWorldHint',
        ]);
    }
});

it('declares an output schema on every registered tool', function (): void {
    $tools = new ReflectionClass(RelaticleServer::class)
        ->getDefaultProperties()['tools'];

    expect($tools)->toBeArray()->toHaveCount(37);

    foreach ($tools as $toolClass) {
        $definition = resolve($toolClass)->toArray();

        expect($definition)->toHaveKey('outputSchema');
        expect($definition['outputSchema']['properties'] ?? [])->not->toBeEmpty();
    }
});
