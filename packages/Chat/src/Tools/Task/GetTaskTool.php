<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Task;

use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use App\Http\Resources\V1\TaskResource;
use Relaticle\Chat\Tools\BaseReadShowTool;

final class GetTaskTool extends BaseReadShowTool
{
    use OperatesOnCrmEntity;

    public function description(): string
    {
        return 'Get a single task by ID with full details.';
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::Task;
    }

    protected function resourceClass(): string
    {
        return TaskResource::class;
    }

    /** @return array<int, string> */
    protected function eagerLoad(): array
    {
        return ['assignees', 'customFieldValues.customField.options'];
    }
}
