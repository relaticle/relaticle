<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Task;

use App\Actions\Task\ListTasks;
use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use App\Http\Resources\V1\CompanyResource;
use App\Http\Resources\V1\OpportunityResource;
use App\Http\Resources\V1\PeopleResource;
use App\Http\Resources\V1\TaskResource;
use Illuminate\Http\Resources\Json\JsonResource;
use Relaticle\Chat\Tools\BaseReadListTool;

final class ListTasksTool extends BaseReadListTool
{
    use OperatesOnCrmEntity;

    public function description(): string
    {
        return 'List tasks with optional filters and pagination. For "my tasks" filter assigned_to_me with {"$eq": true}; for anyone else filter assignees with $in on their member id (resolve the name with ListWorkspaceMembersTool first); without either, the result is every task in the workspace.';
    }

    protected function actionClass(): string
    {
        return ListTasks::class;
    }

    protected function resourceClass(): string
    {
        return TaskResource::class;
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::Task;
    }

    /** @return array<string, class-string<JsonResource>> */
    protected function availableIncludes(): array
    {
        return [
            'companies' => CompanyResource::class,
            'people' => PeopleResource::class,
            'opportunities' => OpportunityResource::class,
        ];
    }
}
