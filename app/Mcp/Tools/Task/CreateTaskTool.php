<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Task;

use App\Actions\Task\CreateTask;
use App\Http\Resources\V1\TaskResource;
use App\Mcp\Tools\BaseCreateTool;
use App\Models\Team;
use App\Models\User;
use App\Rules\ArrayExistsForTeam;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Description('Create a new task in the CRM. Use the crm-schema resource to discover available custom fields.')]
#[IsOpenWorld(false)]
final class CreateTaskTool extends BaseCreateTool
{
    protected function actionClass(): string
    {
        return CreateTask::class;
    }

    protected function resourceClass(): string
    {
        return TaskResource::class;
    }

    protected function entityType(): string
    {
        return 'task';
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('The task title.')->required(),
            'company_ids' => $schema->array()->description('Company IDs to link this task to.'),
            'people_ids' => $schema->array()->description('People IDs to link this task to.'),
            'opportunity_ids' => $schema->array()->description('Opportunity IDs to link this task to.'),
            'assignee_ids' => $schema->array()->description('User IDs to assign this task to. Use whoami tool to discover valid user IDs.'),
        ];
    }

    protected function entityRules(User $user): array
    {
        /** @var Team $team */
        $team = $user->currentTeam;
        $teamId = $team->getKey();
        $teamMemberIds = $team->allUsers()->pluck('id')->all();

        return [
            'title' => ['required', 'string', 'max:255'],
            'company_ids' => ['sometimes', 'array'],
            'company_ids.*' => ['string', new ArrayExistsForTeam('companies', 'company_ids', $teamId)],
            'people_ids' => ['sometimes', 'array'],
            'people_ids.*' => ['string', new ArrayExistsForTeam('people', 'people_ids', $teamId)],
            'opportunity_ids' => ['sometimes', 'array'],
            'opportunity_ids.*' => ['string', new ArrayExistsForTeam('opportunities', 'opportunity_ids', $teamId)],
            'assignee_ids' => ['sometimes', 'array'],
            'assignee_ids.*' => ['string', Rule::in($teamMemberIds)],
        ];
    }
}
