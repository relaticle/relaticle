<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Task;

use App\Actions\Task\DeleteTask;
use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use App\Mcp\Tools\BaseDeleteTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('Delete Task')]
#[Description('Delete a task from the CRM (soft delete).')]
final class DeleteTaskTool extends BaseDeleteTool
{
    use OperatesOnCrmEntity;

    protected function entity(): CrmEntity
    {
        return CrmEntity::Task;
    }

    protected function actionClass(): string
    {
        return DeleteTask::class;
    }
}
