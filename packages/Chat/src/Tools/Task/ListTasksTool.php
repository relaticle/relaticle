<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Task;

use App\Actions\Task\ListTasks;
use App\Http\Resources\V1\TaskResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\BaseReadListTool;

final class ListTasksTool extends BaseReadListTool
{
    public function description(): string
    {
        return 'List tasks with optional search, pagination, and filtering to the tasks attached to a specific company, person, or opportunity.';
    }

    protected function actionClass(): string
    {
        return ListTasks::class;
    }

    protected function resourceClass(): string
    {
        return TaskResource::class;
    }

    /** @return array<string, mixed> */
    protected function additionalSchema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->description('Restrict to tasks attached to this company ID.'),
            'people_id' => $schema->string()->description('Restrict to tasks attached to this person ID.'),
            'opportunity_id' => $schema->string()->description('Restrict to tasks attached to this opportunity ID.'),
        ];
    }

    /** @return array<string, mixed> */
    protected function additionalFilters(Request $request): array
    {
        return array_filter([
            'company_id' => $request['company_id'] ?? null,
            'people_id' => $request['people_id'] ?? null,
            'opportunity_id' => $request['opportunity_id'] ?? null,
        ]);
    }

    protected function searchFilterName(): string
    {
        return 'title';
    }

    protected function citationType(): string
    {
        return 'task';
    }
}
