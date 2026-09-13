<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Task;

use App\Actions\Task\AttachTaskRelationships;
use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use App\Http\Resources\V1\TaskResource;
use App\Mcp\Tools\BaseAttachTool;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\ArrayExistsForWorkspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('Attach Task Relationships')]
#[Description('Attach a task to companies, people, opportunities, or assign to users. Adds links without removing existing ones.')]
final class AttachTaskToEntitiesTool extends BaseAttachTool
{
    use OperatesOnCrmEntity;

    protected function openWorldHint(): bool
    {
        return true;
    }

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
        return AttachTaskRelationships::class;
    }

    /** @return array<int, string> */
    protected function relationshipsToLoad(): array
    {
        return ['companies', 'people', 'opportunities', 'assignees'];
    }

    public function relationshipSchema(JsonSchema $schema): array
    {
        return [
            'company_ids' => $schema->array()->description('Company IDs to attach this task to.'),
            'people_ids' => $schema->array()->description('People IDs to attach this task to.'),
            'opportunity_ids' => $schema->array()->description('Opportunity IDs to attach this task to.'),
            'assignee_ids' => $schema->array()->description('User IDs to assign this task to. Use whoami tool to discover valid user IDs.'),
        ];
    }

    public function relationshipRules(User $user): array
    {
        /** @var Workspace $workspace */
        $workspace = $user->currentWorkspace;
        $workspaceId = $workspace->getKey();
        $workspaceMemberIds = $workspace->allUsers()->pluck('id')->all();

        return [
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
