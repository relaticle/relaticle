<?php

declare(strict_types=1);

use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\AggregateOpportunitiesTool;
use App\Mcp\Tools\BaseAttachTool;
use App\Mcp\Tools\BaseCreateTool;
use App\Mcp\Tools\BaseDeleteTool;
use App\Mcp\Tools\BaseDetachTool;
use App\Mcp\Tools\BaseListTool;
use App\Mcp\Tools\BaseRelationshipTool;
use App\Mcp\Tools\BaseShowTool;
use App\Mcp\Tools\BaseUpdateTool;
use App\Mcp\Tools\Company\CreateCompanyTool;
use App\Mcp\Tools\Company\DeleteCompanyTool;
use App\Mcp\Tools\Company\GetCompanyTool;
use App\Mcp\Tools\Company\ListCompaniesTool;
use App\Mcp\Tools\Company\UpdateCompanyTool;
use App\Mcp\Tools\FetchTool;
use App\Mcp\Tools\GetCrmSchemaTool;
use App\Mcp\Tools\GetCrmSummaryTool;
use App\Mcp\Tools\ListActivityTool;
use App\Mcp\Tools\ListCustomFieldsTool;
use App\Mcp\Tools\Note\AttachNoteToEntitiesTool;
use App\Mcp\Tools\Note\CreateNoteTool;
use App\Mcp\Tools\Note\DeleteNoteTool;
use App\Mcp\Tools\Note\DetachNoteFromEntitiesTool;
use App\Mcp\Tools\Note\GetNoteTool;
use App\Mcp\Tools\Note\ListNotesTool;
use App\Mcp\Tools\Note\UpdateNoteTool;
use App\Mcp\Tools\Opportunity\CreateOpportunityTool;
use App\Mcp\Tools\Opportunity\DeleteOpportunityTool;
use App\Mcp\Tools\Opportunity\GetOpportunityTool;
use App\Mcp\Tools\Opportunity\ListOpportunitiesTool;
use App\Mcp\Tools\Opportunity\UpdateOpportunityTool;
use App\Mcp\Tools\People\CreatePeopleTool;
use App\Mcp\Tools\People\DeletePeopleTool;
use App\Mcp\Tools\People\GetPeopleTool;
use App\Mcp\Tools\People\ListPeopleTool;
use App\Mcp\Tools\People\UpdatePeopleTool;
use App\Mcp\Tools\SearchTool;
use App\Mcp\Tools\Task\AttachTaskToEntitiesTool;
use App\Mcp\Tools\Task\CreateTaskTool;
use App\Mcp\Tools\Task\DeleteTaskTool;
use App\Mcp\Tools\Task\DetachTaskFromEntitiesTool;
use App\Mcp\Tools\Task\GetTaskTool;
use App\Mcp\Tools\Task\ListTasksTool;
use App\Mcp\Tools\Task\UpdateTaskTool;
use App\Mcp\Tools\WhoAmiTool;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

/** @var array{readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false} $readAnnotations */
$readAnnotations = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];

/** @var array{readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false} $createAnnotations */
$createAnnotations = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false];

/** @var array{readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true} $createTaskAnnotations */
$createTaskAnnotations = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true];

/** @var array{readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false} $updateAnnotations */
$updateAnnotations = ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false];

/** @var array{readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: true} $updateTaskAnnotations */
$updateTaskAnnotations = ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => true];

/** @var array{readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false} $deleteAnnotations */
$deleteAnnotations = ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false];

/** @var array{readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false} $attachAnnotations */
$attachAnnotations = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];

/** @var array{readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true} $attachTaskAnnotations */
$attachTaskAnnotations = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true];

/** @var array{readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false} $detachAnnotations */
$detachAnnotations = ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false];

/** @var array<class-string<Tool>, array{title: string, name: string, annotations: array<string, bool>}> $toolContracts */
$toolContracts = [
    WhoAmiTool::class => ['title' => 'Get Account Context', 'name' => 'who-ami-tool', 'annotations' => $readAnnotations],
    SearchTool::class => ['title' => 'Search CRM', 'name' => 'search', 'annotations' => $readAnnotations],
    FetchTool::class => ['title' => 'Fetch CRM Record', 'name' => 'fetch', 'annotations' => $readAnnotations],
    GetCrmSchemaTool::class => ['title' => 'Get CRM Schema', 'name' => 'get-crm-schema-tool', 'annotations' => $readAnnotations],
    GetCrmSummaryTool::class => ['title' => 'Get CRM Summary', 'name' => 'get-crm-summary-tool', 'annotations' => $readAnnotations],
    AggregateOpportunitiesTool::class => ['title' => 'Aggregate Opportunities', 'name' => 'aggregate-opportunities-tool', 'annotations' => $readAnnotations],
    ListActivityTool::class => ['title' => 'List CRM Activity', 'name' => 'list-activity-tool', 'annotations' => $readAnnotations],
    ListCustomFieldsTool::class => ['title' => 'List Custom Fields', 'name' => 'list-custom-fields-tool', 'annotations' => $readAnnotations],
    ListCompaniesTool::class => ['title' => 'List Companies', 'name' => 'list-companies-tool', 'annotations' => $readAnnotations],
    GetCompanyTool::class => ['title' => 'Get Company', 'name' => 'get-company-tool', 'annotations' => $readAnnotations],
    CreateCompanyTool::class => ['title' => 'Create Company', 'name' => 'create-company-tool', 'annotations' => $createAnnotations],
    UpdateCompanyTool::class => ['title' => 'Update Company', 'name' => 'update-company-tool', 'annotations' => $updateAnnotations],
    DeleteCompanyTool::class => ['title' => 'Delete Company', 'name' => 'delete-company-tool', 'annotations' => $deleteAnnotations],
    ListPeopleTool::class => ['title' => 'List People', 'name' => 'list-people-tool', 'annotations' => $readAnnotations],
    GetPeopleTool::class => ['title' => 'Get Person', 'name' => 'get-people-tool', 'annotations' => $readAnnotations],
    CreatePeopleTool::class => ['title' => 'Create Person', 'name' => 'create-people-tool', 'annotations' => $createAnnotations],
    UpdatePeopleTool::class => ['title' => 'Update Person', 'name' => 'update-people-tool', 'annotations' => $updateAnnotations],
    DeletePeopleTool::class => ['title' => 'Delete Person', 'name' => 'delete-people-tool', 'annotations' => $deleteAnnotations],
    ListOpportunitiesTool::class => ['title' => 'List Opportunities', 'name' => 'list-opportunities-tool', 'annotations' => $readAnnotations],
    GetOpportunityTool::class => ['title' => 'Get Opportunity', 'name' => 'get-opportunity-tool', 'annotations' => $readAnnotations],
    CreateOpportunityTool::class => ['title' => 'Create Opportunity', 'name' => 'create-opportunity-tool', 'annotations' => $createAnnotations],
    UpdateOpportunityTool::class => ['title' => 'Update Opportunity', 'name' => 'update-opportunity-tool', 'annotations' => $updateAnnotations],
    DeleteOpportunityTool::class => ['title' => 'Delete Opportunity', 'name' => 'delete-opportunity-tool', 'annotations' => $deleteAnnotations],
    ListTasksTool::class => ['title' => 'List Tasks', 'name' => 'list-tasks-tool', 'annotations' => $readAnnotations],
    GetTaskTool::class => ['title' => 'Get Task', 'name' => 'get-task-tool', 'annotations' => $readAnnotations],
    CreateTaskTool::class => ['title' => 'Create Task', 'name' => 'create-task-tool', 'annotations' => $createTaskAnnotations],
    UpdateTaskTool::class => ['title' => 'Update Task', 'name' => 'update-task-tool', 'annotations' => $updateTaskAnnotations],
    DeleteTaskTool::class => ['title' => 'Delete Task', 'name' => 'delete-task-tool', 'annotations' => $deleteAnnotations],
    AttachTaskToEntitiesTool::class => ['title' => 'Attach Task Relationships', 'name' => 'attach-task-to-entities-tool', 'annotations' => $attachTaskAnnotations],
    DetachTaskFromEntitiesTool::class => ['title' => 'Detach Task Relationships', 'name' => 'detach-task-from-entities-tool', 'annotations' => $detachAnnotations],
    ListNotesTool::class => ['title' => 'List Notes', 'name' => 'list-notes-tool', 'annotations' => $readAnnotations],
    GetNoteTool::class => ['title' => 'Get Note', 'name' => 'get-note-tool', 'annotations' => $readAnnotations],
    CreateNoteTool::class => ['title' => 'Create Note', 'name' => 'create-note-tool', 'annotations' => $createAnnotations],
    UpdateNoteTool::class => ['title' => 'Update Note', 'name' => 'update-note-tool', 'annotations' => $updateAnnotations],
    DeleteNoteTool::class => ['title' => 'Delete Note', 'name' => 'delete-note-tool', 'annotations' => $deleteAnnotations],
    AttachNoteToEntitiesTool::class => ['title' => 'Attach Note Relationships', 'name' => 'attach-note-to-entities-tool', 'annotations' => $attachAnnotations],
    DetachNoteFromEntitiesTool::class => ['title' => 'Detach Note Relationships', 'name' => 'detach-note-from-entities-tool', 'annotations' => $detachAnnotations],
];

mutates(...array_merge(
    [
        BaseListTool::class,
        BaseShowTool::class,
        BaseCreateTool::class,
        BaseUpdateTool::class,
        BaseDeleteTool::class,
        BaseRelationshipTool::class,
        BaseAttachTool::class,
        BaseDetachTool::class,
    ],
    array_keys($toolContracts),
));

// Enumerating the server's own registration, rather than a hand-listed subset, is
// what stops a new tool opting out of the submission policy by simply not being
// added to a dataset, which is how WhoAmiTool once shipped without openWorldHint.
it('publishes the exact explicit title and stable technical name for every registered tool', function () use ($toolContracts): void {
    $registeredTools = new ReflectionClass(RelaticleServer::class)
        ->getDefaultProperties()['tools'];

    expect($registeredTools)->toBeArray()->toHaveCount(37);
    expect($registeredTools)->toEqualCanonicalizing(array_keys($toolContracts));

    foreach ($registeredTools as $toolClass) {
        $definition = resolve($toolClass)->toArray();
        $titleAttributes = new ReflectionClass($toolClass)->getAttributes(Title::class);

        expect($titleAttributes)->toHaveCount(1);
        expect($definition['title'])->toBe($toolContracts[$toolClass]['title']);
        expect($definition['title'])->not->toEndWith(' Tool');
        expect($definition['name'])->toBe($toolContracts[$toolClass]['name']);
        expect($definition['annotations'])->toBe($toolContracts[$toolClass]['annotations']);
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
