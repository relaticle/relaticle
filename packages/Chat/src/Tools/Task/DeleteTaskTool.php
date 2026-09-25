<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Task;

use App\Actions\Task\DeleteTask;
use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use Relaticle\Chat\Tools\BaseWriteDeleteTool;

final class DeleteTaskTool extends BaseWriteDeleteTool
{
    use OperatesOnCrmEntity;

    public function description(): string
    {
        return 'Propose deleting a task. Returns a proposal for user approval.';
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::Task;
    }

    protected function actionClass(): string
    {
        return DeleteTask::class;
    }
}
