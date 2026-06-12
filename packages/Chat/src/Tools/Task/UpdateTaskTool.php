<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Task;

use App\Actions\Task\UpdateTask;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Support\TeamMembersContext;
use Relaticle\Chat\Tools\BaseWriteUpdateTool;
use Relaticle\Chat\Tools\Concerns\NormalizesToolInput;

final class UpdateTaskTool extends BaseWriteUpdateTool
{
    use NormalizesToolInput;

    public function description(): string
    {
        return 'Propose updating an existing task. Returns a proposal for user approval.';
    }

    protected function modelClass(): string
    {
        return Task::class;
    }

    protected function actionClass(): string
    {
        return UpdateTask::class;
    }

    protected function entityType(): string
    {
        return 'task';
    }

    protected function entityLabel(): string
    {
        return 'Task';
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('The new task title.'),
            'assignee_ids' => $schema->array()->description('User ULIDs to assign. Pass [] to clear assignees.'),
            'people_ids' => $schema->array()->description('People ULIDs to link. Pass [] to clear linked people.'),
            'company_ids' => $schema->array()->description('Company ULIDs to link. Pass [] to clear linked companies.'),
            'opportunity_ids' => $schema->array()->description('Opportunity ULIDs to link. Pass [] to clear linked opportunities.'),
        ];
    }

    protected function validateRequest(Request $request, User $user): ?string
    {
        return TeamMembersContext::memberFieldError($user, 'assignee_ids', $request['assignee_ids'] ?? null);
    }

    protected function extractActionData(Request $request): array
    {
        $payload = $request->all();
        $data = [];

        if (array_key_exists('title', $payload)) {
            $data['title'] = $payload['title'];
        }
        foreach (['assignee_ids', 'people_ids', 'company_ids', 'opportunity_ids'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $data[$key] = $this->idListOrNull($request, $key);
        }

        return array_filter($data, static fn (mixed $v): bool => $v !== null);
    }

    protected function buildDisplayData(Request $request, Model $model): array
    {
        /** @var User $user */
        $user = auth()->user();
        $team = $user->currentTeam;
        $payload = $request->all();

        $fields = [];
        if (array_key_exists('title', $payload)) {
            $fields[] = ['label' => 'Title', 'old' => $model->getAttribute('title'), 'new' => $payload['title']];
        }

        $peopleIds = $this->idListOrNull($request, 'people_ids');
        if ($peopleIds !== null) {
            $fields[] = ['label' => 'Linked people', 'value' => $this->namesForIds($peopleIds, People::class, 'name', $team)];
        }

        $companyIds = $this->idListOrNull($request, 'company_ids');
        if ($companyIds !== null) {
            $fields[] = ['label' => 'Linked companies', 'value' => $this->namesForIds($companyIds, Company::class, 'name', $team)];
        }

        $opportunityIds = $this->idListOrNull($request, 'opportunity_ids');
        if ($opportunityIds !== null) {
            $fields[] = ['label' => 'Linked opportunities', 'value' => $this->namesForIds($opportunityIds, Opportunity::class, 'name', $team)];
        }

        $assigneeIds = $this->idListOrNull($request, 'assignee_ids');
        if ($assigneeIds !== null) {
            $fields[] = ['label' => 'Assignees', 'value' => $this->namesForIds($assigneeIds, User::class, 'name', null)];
        }

        return [
            'title' => 'Update Task',
            'summary' => "Update task \"{$model->getAttribute('title')}\"",
            'fields' => $fields,
        ];
    }

    /**
     * @param  list<string>  $ids
     * @param  class-string<Model>  $modelClass
     */
    private function namesForIds(array $ids, string $modelClass, string $nameAttribute, ?Team $team): string
    {
        if ($ids === []) {
            return '(none)';
        }

        $instance = new $modelClass;
        $query = $modelClass::query()->whereIn($instance->getKeyName(), $ids);
        if ($team instanceof Team) {
            $query->where('team_id', $team->getKey());
        }

        return $query->pluck($nameAttribute)->implode(', ');
    }
}
