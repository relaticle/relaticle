<?php

declare(strict_types=1);

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
    [ListCompaniesTool::class,  ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false]],
    [GetCompanyTool::class,     ['readOnlyHint' => true, 'idempotentHint' => true]],
    [CreateCompanyTool::class,  ['openWorldHint' => false]],
    [UpdateCompanyTool::class,  ['openWorldHint' => false]],
    [DeleteCompanyTool::class,  ['destructiveHint' => true, 'openWorldHint' => false]],
    [AttachNoteToEntitiesTool::class, ['idempotentHint' => true, 'openWorldHint' => false]],
    [DetachNoteFromEntitiesTool::class, ['destructiveHint' => true, 'openWorldHint' => false]],
]);

it('exposes the required annotation matrix', function (string $toolClass, array $expected): void {
    $tool = new $toolClass;
    assert($tool instanceof Tool);

    $annotations = $tool->annotations();

    foreach ($expected as $key => $value) {
        expect($annotations)->toHaveKey($key);
        expect($annotations[$key])->toBe($value);
    }
})->with('annotation_matrix');
