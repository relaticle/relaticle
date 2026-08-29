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
        return 'List tasks with optional search, pagination, sorting, and filtering — by custom field values, by the tasks attached to a specific company, person, or opportunity, or by who they are assigned to. Use assigned_to_me for "my tasks"/"tasks assigned to me", and assignee_ids for anyone else (resolve the name with ListTeamMembersTool first); without either, the result is every task in the workspace.';
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
            'assigned_to_me' => $schema->boolean()->description('Restrict to tasks assigned to the current user. Set this for "my tasks" or "tasks assigned to me".'),
            'assignee_ids' => $schema->array()->description('Restrict to tasks assigned to any of these user ULIDs. Use this for "tasks assigned to <someone else>" — call ListTeamMembersTool first to turn the name into an id.'),
            'company_id' => $schema->string()->description('Restrict to tasks attached to this company ID.'),
            'people_id' => $schema->string()->description('Restrict to tasks attached to this person ID.'),
            'opportunity_id' => $schema->string()->description('Restrict to tasks attached to this opportunity ID.'),
        ];
    }

    /** @return array<string, mixed> */
    protected function additionalFilters(Request $request): array
    {
        $assigneeIds = is_array($request['assignee_ids'] ?? null) ? $request['assignee_ids'] : null;

        return array_filter([
            'assigned_to_me' => filter_var($request['assigned_to_me'] ?? false, FILTER_VALIDATE_BOOLEAN) ? '1' : null,
            'assignee_ids' => $assigneeIds !== [] ? $assigneeIds : null,
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
