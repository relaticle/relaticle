<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Task;

use App\Actions\Task\DetachTaskRelationships;
use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use App\Http\Resources\V1\TaskResource;
use App\Mcp\Tools\BaseDetachTool;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\ArrayExistsForWorkspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('Detach Task Relationships')]
#[Description('Detach a task from companies, people, opportunities, or unassign users. Removes specified links.')]
final class DetachTaskFromEntitiesTool extends BaseDetachTool
{
    use OperatesOnCrmEntity;

    protected function entity(): CrmEntity
    {
        return CrmEntity::Task;
    }

    protected function resourceClass(): string
    {
        return TaskResource::class;
    }

    protected function actionClass(): string
    {
        return DetachTaskRelationships::class;
    }

    /** @return array<int, string> */
    protected function relationshipsToLoad(): array
    {
        return ['companies', 'people', 'opportunities', 'assignees'];
    }

    public function relationshipSchema(JsonSchema $schema): array
    {
        return [
            'company_ids' => $schema->array()->description('Company IDs to detach from this task.'),
            'people_ids' => $schema->array()->description('People IDs to detach from this task.'),
            'opportunity_ids' => $schema->array()->description('Opportunity IDs to detach from this task.'),
            'assignee_ids' => $schema->array()->description('User IDs to unassign from this task.'),
        ];
    }

    public function relationshipRules(User $user): array
    {
        /** @var Workspace $workspace */
        $workspace = $user->currentWorkspace;
        $workspaceId = $workspace->getKey();

        return [
            'company_ids' => ['sometimes', 'array'],
            'company_ids.*' => ['string', new ArrayExistsForWorkspace('companies', 'company_ids', $workspaceId)],
            'people_ids' => ['sometimes', 'array'],
            'people_ids.*' => ['string', new ArrayExistsForWorkspace('people', 'people_ids', $workspaceId)],
            'opportunity_ids' => ['sometimes', 'array'],
            'opportunity_ids.*' => ['string', new ArrayExistsForWorkspace('opportunities', 'opportunity_ids', $workspaceId)],
            'assignee_ids' => ['sometimes', 'array'],
            'assignee_ids.*' => ['string'],
        ];
    }
}
