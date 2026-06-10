<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Task;

use App\Actions\Task\DeleteTask;
use App\Models\Task;
use Relaticle\Chat\Tools\BaseWriteDeleteTool;

final class DeleteTaskTool extends BaseWriteDeleteTool
{
    public function description(): string
    {
        return 'Propose deleting a task. Returns a proposal for user approval.';
    }

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

    protected function entityType(): string
    {
        return 'task';
    }

    protected function nameAttribute(): string
    {
        return 'title';
    }
}
