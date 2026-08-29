<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Task;

use App\Actions\Task\UpdateTask;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
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

    protected function nameAttribute(): string
    {
        return 'title';
    }

    protected function ownedForeignKeyLists(): array
    {
        return [
            'company_ids' => Company::class,
            'people_ids' => People::class,
            'opportunity_ids' => Opportunity::class,
        ];
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('The new task title.'),
            'assignee_ids' => $schema->array()->description('User ULIDs to assign. Pass null or [] to clear assignees.'),
            'people_ids' => $schema->array()->description('People ULIDs to link. Pass null or [] to clear linked people.'),
            'company_ids' => $schema->array()->description('Company ULIDs to link. Pass null or [] to clear linked companies.'),
            'opportunity_ids' => $schema->array()->description('Opportunity ULIDs to link. Pass null or [] to clear linked opportunities.'),
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
        throw_unless($model instanceof Task, \InvalidArgumentException::class, 'Expected a task model.');

        /** @var User $user */
        $user = auth()->user();
        $team = $user->currentTeam;
        $payload = $request->all();

        $fields = [];
        if (array_key_exists('title', $payload)) {
            $fields[] = ['label' => 'Title', 'old' => $model->getAttribute('title'), 'new' => $payload['title']];
        }

        foreach ([
            ['people_ids', 'Linked people', 'people', People::class, $team],
            ['company_ids', 'Linked companies', 'companies', Company::class, $team],
            ['opportunity_ids', 'Linked opportunities', 'opportunities', Opportunity::class, $team],
            ['assignee_ids', 'Assignees', 'assignees', User::class, null],
        ] as [$key, $label, $relation, $modelClass, $scope]) {
            $ids = $this->idListOrNull($request, $key);

            if ($ids === null) {
                continue;
            }

            $fields[] = [
                'label' => $label,
                'old' => $this->joinNames(array_values($model->{$relation}()->pluck('name')->all())),
                'new' => $ids === [] ? __('(none)') : $this->recordNames()->names($ids, $modelClass, $scope),
                '_oldValue' => array_map(strval(...), $model->{$relation}()->pluck($model->{$relation}()->getRelated()->getQualifiedKeyName())->all()),
                '_newValue' => $ids,
            ];
        }

        return [
            'title' => 'Update Task',
            'summary' => "Update task \"{$model->getAttribute('title')}\"",
            'fields' => $fields,
        ];
    }

    /**
     * A relationship row reads "(none)" when the link list is empty, never as a
     * blank cell: an emptied relationship is a change worth seeing.
     *
     * @param  list<mixed>  $names
     */
    private function joinNames(array $names): string
    {
        $names = array_values(array_filter($names, static fn (mixed $name): bool => is_string($name) && $name !== ''));

        return $names === [] ? __('(none)') : implode(', ', $names);
    }
}
