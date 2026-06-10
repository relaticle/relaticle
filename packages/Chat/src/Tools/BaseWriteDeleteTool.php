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
use Relaticle\Chat\Tools\Concerns\WithConversationContext;

abstract class BaseWriteDeleteTool implements Tool
{
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

        $requestedIds = $this->requestedIds($request);

        if ($requestedIds === []) {
            return (string) json_encode(['error' => 'Provide `ids` (a non-empty array) of records to delete.']);
        }

        /** @var Collection<int, Model> $models */
        $models = $this->modelClass()::query()
            ->whereBelongsTo($user->currentTeam)
            ->whereKey($requestedIds)
            ->with('team')
            ->get();

        $deletable = $models->filter(fn (Model $model): bool => $user->can('delete', $model))->values();

        $foundIds = $deletable->map(fn (Model $model): string => (string) $model->getKey())->all();
        $skipped = array_values(array_diff($requestedIds, $foundIds));

        if ($deletable->isEmpty()) {
            return (string) json_encode([
                'error' => "No matching {$this->entityLabel()} records you can delete were found.",
                'skipped' => $skipped,
            ]);
        }

        $pending = resolve(PendingActionService::class)->createProposal(
            user: $user,
            conversationId: $this->resolveConversationId(),
            actionClass: $this->actionClass(),
            operation: PendingActionOperation::Delete,
            entityType: $this->entityType(),
            actionData: [
                '_record_ids' => $deletable->map(fn (Model $model) => $model->getKey())->all(),
                '_model_class' => $this->modelClass(),
            ],
            displayData: $this->displayData($deletable),
        );

        return (string) json_encode([
            'type' => 'pending_action',
            'pending_action_id' => $pending->id,
            'action' => class_basename($this->actionClass()),
            'entity_type' => $this->entityType(),
            'operation' => 'delete',
            'data' => ['ids' => $foundIds],
            'skipped' => $skipped,
            'display' => $pending->display_data,
            'meta' => ['agent_should_stop' => true],
        ], JSON_PRETTY_PRINT);
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
     * @param  Collection<int, Model>  $models
     * @return array<string, mixed>
     */
    private function displayData(Collection $models): array
    {
        $count = $models->count();
        $isSingle = $count === 1;
        $plural = Str::plural(strtolower($this->entityLabel()), $count);

        $fields = $models->values()
            ->map(fn (Model $model, int $i): array => [
                'label' => $isSingle ? 'Name' : "{$this->entityLabel()} ".($i + 1),
                'value' => (string) $model->{$this->nameAttribute()},
            ])
            ->all();

        $summary = $isSingle
            ? "Delete {$this->entityLabel()} \"".$models->first()->{$this->nameAttribute()}.'"'
            : "Delete {$count} {$plural}";

        return [
            'title' => $isSingle ? "Delete {$this->entityLabel()}" : "Delete {$count} {$plural}",
            'summary' => $summary,
            'fields' => $fields,
        ];
    }
}
