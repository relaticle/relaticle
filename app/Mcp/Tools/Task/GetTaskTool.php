<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Task;

use App\Http\Resources\V1\TaskResource;
use App\Mcp\Tools\BaseShowTool;
use App\Models\Task;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Get a single task by ID with full details and relationships.')]
final class GetTaskTool extends BaseShowTool
{
    protected function modelClass(): string
    {
        return Task::class;
    }

    /** @return class-string<JsonResource> */
    protected function resourceClass(): string
    {
        return TaskResource::class;
    }

    protected function entityLabel(): string
    {
        return 'Task';
    }

    /** @return array<int, string> */
    protected function allowedIncludes(): array
    {
        return ['creator', 'assignees', 'companies', 'people', 'opportunities', 'assigneesCount', 'companiesCount', 'peopleCount', 'opportunitiesCount'];
    }
}
