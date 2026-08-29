<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Task;

use App\Actions\Task\DetachTaskRelationships;
use App\Http\Resources\V1\TaskResource;
use App\Mcp\Tools\BaseDetachTool;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Rules\ArrayExistsForTeam;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('Detach Task Relationships')]
#[Description('Detach a task from companies, people, opportunities, or unassign users. Removes specified links.')]
final class DetachTaskFromEntitiesTool extends BaseDetachTool
{
    protected function modelClass(): string
    {
        return Task::class;
    }

    protected function entityLabel(): string
    {
        return 'Task';
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
        /** @var Team $team */
        $team = $user->currentTeam;
        $teamId = $team->getKey();

        return [
            'company_ids' => ['sometimes', 'array'],
            'company_ids.*' => ['string', new ArrayExistsForTeam('companies', 'company_ids', $teamId)],
            'people_ids' => ['sometimes', 'array'],
            'people_ids.*' => ['string', new ArrayExistsForTeam('people', 'people_ids', $teamId)],
            'opportunity_ids' => ['sometimes', 'array'],
            'opportunity_ids.*' => ['string', new ArrayExistsForTeam('opportunities', 'opportunity_ids', $teamId)],
            'assignee_ids' => ['sometimes', 'array'],
            'assignee_ids.*' => ['string'],
        ];
    }
}
