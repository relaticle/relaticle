<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Task;

use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use App\Http\Resources\V1\TaskResource;
use App\Mcp\Tools\BaseShowTool;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('Get Task')]
#[Description('Get a single task by ID with full details and relationships.')]
final class GetTaskTool extends BaseShowTool
{
    use OperatesOnCrmEntity;

    protected function entity(): CrmEntity
    {
        return CrmEntity::Task;
    }

    /** @return class-string<JsonResource> */
    protected function resourceClass(): string
    {
        return TaskResource::class;
    }

    /** @return array<int, string> */
    protected function allowedIncludes(): array
    {
        return ['creator', 'assignees', 'companies', 'people', 'opportunities', 'assigneesCount', 'companiesCount', 'peopleCount', 'opportunitiesCount'];
    }
}
