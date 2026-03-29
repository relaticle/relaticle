<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Prompts\CrmOverviewPrompt;
use App\Mcp\Resources\CompanySchemaResource;
use App\Mcp\Resources\CrmSummaryResource;
use App\Mcp\Resources\NoteSchemaResource;
use App\Mcp\Resources\OpportunitySchemaResource;
use App\Mcp\Resources\PeopleSchemaResource;
use App\Mcp\Resources\TaskSchemaResource;
use App\Mcp\Tools\Company\CreateCompanyTool;
use App\Mcp\Tools\Company\DeleteCompanyTool;
use App\Mcp\Tools\Company\GetCompanyTool;
use App\Mcp\Tools\Company\ListCompaniesTool;
use App\Mcp\Tools\Company\UpdateCompanyTool;
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
use App\Mcp\Tools\Task\AttachTaskToEntitiesTool;
use App\Mcp\Tools\Task\CreateTaskTool;
use App\Mcp\Tools\Task\DeleteTaskTool;
use App\Mcp\Tools\Task\DetachTaskFromEntitiesTool;
use App\Mcp\Tools\Task\GetTaskTool;
use App\Mcp\Tools\Task\ListTasksTool;
use App\Mcp\Tools\Task\UpdateTaskTool;
use App\Mcp\Tools\WhoAmiTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;

#[Name('Relaticle CRM')]
#[Version('1.0.0')]
#[Instructions('This server provides access to Relaticle CRM data including companies, people, opportunities, tasks, and notes. All operations are scoped to the authenticated user\'s current team.')]
final class RelaticleServer extends Server
{
    public int $defaultPaginationLength = 50;

    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        WhoAmiTool::class,
        ListCompaniesTool::class,
        GetCompanyTool::class,
        CreateCompanyTool::class,
        UpdateCompanyTool::class,
        DeleteCompanyTool::class,
        ListPeopleTool::class,
        GetPeopleTool::class,
        CreatePeopleTool::class,
        UpdatePeopleTool::class,
        DeletePeopleTool::class,
        ListOpportunitiesTool::class,
        GetOpportunityTool::class,
        CreateOpportunityTool::class,
        UpdateOpportunityTool::class,
        DeleteOpportunityTool::class,
        ListTasksTool::class,
        GetTaskTool::class,
        CreateTaskTool::class,
        UpdateTaskTool::class,
        DeleteTaskTool::class,
        AttachTaskToEntitiesTool::class,
        DetachTaskFromEntitiesTool::class,
        ListNotesTool::class,
        GetNoteTool::class,
        CreateNoteTool::class,
        UpdateNoteTool::class,
        DeleteNoteTool::class,
        AttachNoteToEntitiesTool::class,
        DetachNoteFromEntitiesTool::class,
    ];

    /** @var array<int, class-string<Server\Resource>> */
    protected array $resources = [
        CompanySchemaResource::class,
        PeopleSchemaResource::class,
        OpportunitySchemaResource::class,
        TaskSchemaResource::class,
        NoteSchemaResource::class,
        CrmSummaryResource::class,
    ];

    /** @var array<int, class-string<Prompt>> */
    protected array $prompts = [
        CrmOverviewPrompt::class,
    ];
}
