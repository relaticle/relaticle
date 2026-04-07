<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Task;

use App\Actions\Task\DeleteTask;
use App\Mcp\Tools\BaseDeleteTool;
use App\Models\Task;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Delete a task from the CRM (soft delete).')]
#[IsDestructive]
final class DeleteTaskTool extends BaseDeleteTool
{
    protected function modelClass(): string
    {
        return Task::class;
    }

    protected function actionClass(): string
    {
        return DeleteTask::class;
    }

    protected function entityLabel(): string
    {
        return 'Task';
    }

    protected function nameAttribute(): string
    {
        return 'title';
    }
}
