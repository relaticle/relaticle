<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Task;

use App\Actions\Task\ListTasks;
use App\Http\Resources\V1\TaskResource;
use App\Mcp\Tools\BaseListTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List tasks in the CRM with optional search and pagination.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class ListTasksTool extends BaseListTool
{
    protected function actionClass(): string
    {
        return ListTasks::class;
    }

    protected function resourceClass(): string
    {
        return TaskResource::class;
    }

    protected function searchFilterName(): string
    {
        return 'title';
    }

    protected function additionalSchema(JsonSchema $schema): array
    {
        return [
            'assigned_to_me' => $schema->boolean()->description('Filter tasks assigned to the current user.'),
            'company_id' => $schema->string()->description('Filter tasks linked to a specific company.'),
            'people_id' => $schema->string()->description('Filter tasks linked to a specific person.'),
            'opportunity_id' => $schema->string()->description('Filter tasks linked to a specific opportunity.'),
        ];
    }

    protected function additionalFilters(Request $request): array
    {
        return [
            'assigned_to_me' => $request->get('assigned_to_me') ? '1' : null,
            'company_id' => $request->get('company_id'),
            'people_id' => $request->get('people_id'),
            'opportunity_id' => $request->get('opportunity_id'),
        ];
    }
}
