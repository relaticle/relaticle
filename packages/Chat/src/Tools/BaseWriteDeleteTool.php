<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\Concerns\LimitsPlanSteps;
use Relaticle\Chat\Tools\Concerns\WithConversationContext;

abstract class BaseWriteDeleteTool implements Tool
{
    use LimitsPlanSteps;
    use WithConversationContext;

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** @return class-string */
    abstract protected function actionClass(): string;

    abstract protected function entityLabel(): string;

    abstract protected function entityType(): string;

    abstract public function description(): string;

    protected function nameAttribute(): string
    {
        return 'name';
    }

    public function schema(JsonSchema $schema): array
    {
        $label = strtolower($this->entityLabel());

        return [
            'ids' => $schema->array()->items($schema->string())->required()
                ->description("The {$label} IDs to delete. Pass one id to delete a single {$label}, or many to delete them all in one call."),
        ];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        $planLimitError = $this->planStepLimitError();

        if ($planLimitError !== null) {
            return (string) json_encode(['error' => $planLimitError], JSON_UNESCAPED_SLASHES);
        }

        $requestedIds = $this->requestedIds($request);

        if ($requestedIds === []) {
            return (string) json_encode(['error' => 'Provide `ids` (a non-empty array) of records to delete.'], JSON_UNESCAPED_SLASHES);
        }

        $maxBatchSize = (int) config('chat.max_batch_size');

        if (count($requestedIds) > $maxBatchSize) {
            return (string) json_encode(['error' => "Too many records — at most {$maxBatchSize} per proposal."], JSON_UNESCAPED_SLASHES);
        }

        /** @var Collection<int, Model> $models */
        $models = $this->modelClass()::query()
            ->whereBelongsTo($user->currentTeam)
            ->whereKey($requestedIds)
            ->get();

        $deletable = $models->filter(fn (Model $model): bool => $user->can('delete', $model))->values();

        $foundIds = $deletable->map(fn (Model $model): string => (string) $model->getKey())->all();
        $skipped = array_values(array_diff($requestedIds, $foundIds));

        if ($deletable->isEmpty()) {
            return (string) json_encode([
                'error' => "No matching {$this->entityLabel()} records you can delete were found.",
                'skipped' => $skipped,
            ], JSON_UNESCAPED_SLASHES);
        }

        $pending = resolve(PendingActionService::class)->createProposal(
            user: $user,
            conversationId: $this->resolveConversationId(),
            actionClass: $this->actionClass(),
            operation: PendingActionOperation::Delete,
            entityType: $this->entityType(),
            actionData: $this->actionData($deletable),
            displayData: $this->displayData($deletable),
            turnId: $this->resolveTurnId(),
        );

        return (string) json_encode([
            'type' => 'pending_action',
            'pending_action_id' => $pending->id,
            'turn_id' => $pending->turn_id,
            'action' => class_basename($this->actionClass()),
            'entity_type' => $this->entityType(),
            'operation' => 'delete',
            'data' => ['ids' => $foundIds],
            'skipped' => $skipped,
            'display' => $pending->display_data,
            'meta' => ['agent_should_stop' => true],
        ], JSON_UNESCAPED_SLASHES);
    }

    /** @return list<string> */
    private function requestedIds(Request $request): array
    {
        $ids = $request['ids'] ?? null;

        if (! is_array($ids)) {
            return [];
        }

        $ids = array_filter(array_map(
            static fn (mixed $id): string => is_scalar($id) ? (string) $id : '',
            $ids,
        ), static fn (string $id): bool => $id !== '');

        return array_values(array_unique($ids));
    }

    /**
     * Single delete stays an all-or-nothing proposal (`_record_ids`); a multi-record
     * delete becomes a per-item `_batch` (each record self-contained with its own
     * `_record_id`/`_model_class`) so the dock can approve/skip each one individually,
     * mirroring create.
     *
     * @param  Collection<int, Model>  $models
     * @return array<string, mixed>
     */
    private function actionData(Collection $models): array
    {
        if ($models->count() === 1) {
            return [
                '_record_ids' => [$models->first()->getKey()],
                '_model_class' => $this->modelClass(),
            ];
        }

        return [
            '_batch' => true,
            'records' => $models->values()->map(fn (Model $model): array => [
                '_record_id' => $model->getKey(),
                '_model_class' => $this->modelClass(),
            ])->all(),
        ];
    }

    /**
     * @param  Collection<int, Model>  $models
     * @return array<string, mixed>
     */
    private function displayData(Collection $models): array
    {
        $count = $models->count();

        if ($count === 1) {
            $name = (string) $models->first()->{$this->nameAttribute()};

            return [
                'title' => __('Delete :entity', ['entity' => $this->entityLabel()]),
                'summary' => __('Delete :entity ":name"', ['entity' => $this->entityLabel(), 'name' => $name]),
                'fields' => [['label' => __('Name'), 'value' => $name]],
            ];
        }

        $items = $models->values()
            ->map(function (Model $model): array {
                $name = (string) $model->{$this->nameAttribute()};

                return [
                    'summary' => __('Delete :entity ":name"', ['entity' => $this->entityLabel(), 'name' => $name]),
                    'fields' => [['label' => __('Name'), 'value' => $name]],
                ];
            })
            ->all();

        $titleNoun = Str::plural(Str::headline($this->entityLabel()), $count);

        return [
            'title' => __('Delete :count :entities', ['count' => $count, 'entities' => $titleNoun]),
            'summary' => __('Delete :count :entities', [
                'count' => $count,
                'entities' => Str::plural(strtolower($this->entityLabel()), $count),
            ]),
            'items' => $items,
        ];
    }
}
