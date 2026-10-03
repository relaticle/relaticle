<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Task;

use App\Actions\Task\ListTasks;
use App\Enums\CrmEntity;
use App\Http\Resources\V1\TaskResource;
use App\Mcp\Tools\BaseListTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('List Tasks')]
#[Description('List tasks in the CRM with optional filters and pagination.')]
final class ListTasksTool extends BaseListTool
{
    protected function actionClass(): string
    {
        return ListTasks::class;
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::Task;
    }

    protected function resourceClass(): string
    {
        return TaskResource::class;
    }
}
