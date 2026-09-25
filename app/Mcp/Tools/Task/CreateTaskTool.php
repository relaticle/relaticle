<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Task;

use App\Actions\Task\CreateTask;
use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use App\Http\Resources\V1\TaskResource;
use App\Mcp\Tools\BaseCreateTool;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\ArrayExistsForWorkspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('Create Task')]
#[Description('Create a new task in the CRM. Use the crm-schema resource to discover available custom fields.')]
final class CreateTaskTool extends BaseCreateTool
{
    use OperatesOnCrmEntity;

    protected function openWorldHint(): bool
    {
        return true;
    }

    protected function actionClass(): string
    {
        return CreateTask::class;
    }

    protected function resourceClass(): string
    {
        return TaskResource::class;
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::Task;
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
        /** @var Workspace $workspace */
        $workspace = $user->currentWorkspace;
        $workspaceId = $workspace->getKey();
        $workspaceMemberIds = $workspace->allUsers()->pluck('id')->all();

        return [
            'title' => ['required', 'string', 'max:255'],
            'company_ids' => ['sometimes', 'array'],
            'company_ids.*' => ['string', new ArrayExistsForWorkspace('companies', 'company_ids', $workspaceId)],
            'people_ids' => ['sometimes', 'array'],
            'people_ids.*' => ['string', new ArrayExistsForWorkspace('people', 'people_ids', $workspaceId)],
            'opportunity_ids' => ['sometimes', 'array'],
            'opportunity_ids.*' => ['string', new ArrayExistsForWorkspace('opportunities', 'opportunity_ids', $workspaceId)],
            'assignee_ids' => ['sometimes', 'array'],
            'assignee_ids.*' => ['string', Rule::in($workspaceMemberIds)],
        ];
    }
}
