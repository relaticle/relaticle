<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Actions\Merge\MergeRecords;
use App\Enums\WorkspaceCapability;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\ProposalEntity;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Support\PendingActionEnvelope;
use Relaticle\Chat\Tools\Concerns\LimitsPlanSteps;
use Relaticle\Chat\Tools\Concerns\RequiresWorkspaceCapability;
use Relaticle\Chat\Tools\Concerns\WithConversationContext;

abstract class BaseMergeTool implements Tool
{
    use LimitsPlanSteps;
    use RequiresWorkspaceCapability;
    use WithConversationContext;

    private const array REQUIRED_CAPABILITIES = [
        WorkspaceCapability::RecordsCreate,
        WorkspaceCapability::RecordsUpdate,
        WorkspaceCapability::RecordsDelete,
    ];

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    abstract protected function entityLabel(): string;

    abstract protected function entityType(): string;

    abstract protected function nameAttribute(): string;

    abstract public function description(): string;

    public function schema(JsonSchema $schema): array
    {
        $label = strtolower($this->entityLabel());

        return [
            'merges' => $schema->array()
                ->items($schema->object([
                    'primary_id' => $schema->string()->description("The {$label} ID that wins wherever both records hold a value.")->required(),
                    'secondary_id' => $schema->string()->description("The {$label} ID merged into the primary. Must differ from the primary.")->required(),
                ]))
                ->required()
                ->description("The {$label} pairs to merge. Pass one pair to merge two {$label} records, or several to merge each pair in one call. A record may appear in only one pair."),
        ];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        foreach (self::REQUIRED_CAPABILITIES as $capability) {
            $capabilityError = $this->capabilityError($user, $capability);

            if ($capabilityError !== null) {
                return $capabilityError;
            }
        }

        $planLimitError = $this->planStepLimitError();

        if ($planLimitError !== null) {
            return $this->error($planLimitError);
        }

        $pairs = $this->requestedPairs($request);
        $pairsError = $this->pairsError($pairs);

        if ($pairsError !== null) {
            return $this->error($pairsError);
        }

        ['mergeable' => $mergeable, 'skipped' => $skipped] = $this->splitMergeable($user, $pairs);

        if ($mergeable === []) {
            return (string) json_encode([
                'error' => "No matching {$this->entityLabel()} pairs you can merge were found.",
                'skipped' => $skipped,
            ], JSON_UNESCAPED_SLASHES);
        }

        $pending = resolve(PendingActionService::class)->createProposal(
            user: $user,
            conversationId: $this->resolveConversationId(),
            actionClass: MergeRecords::class,
            operation: PendingActionOperation::Merge,
            entityType: $this->proposalEntity(),
            actionData: $this->actionData($mergeable),
            displayData: $this->displayData($mergeable),
            turnId: $this->resolveTurnId(),
        );

        return (string) json_encode(
            PendingActionEnvelope::for($pending, class_basename(MergeRecords::class), [
                'merges' => array_map(static fn (array $pair): array => [
                    'primary_id' => (string) $pair['primary']->getKey(),
                    'secondary_id' => (string) $pair['secondary']->getKey(),
                ], $mergeable),
            ], ['skipped' => $skipped]),
            JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @param  list<array{primary_id: string, secondary_id: string}>  $pairs
     */
    private function pairsError(array $pairs): ?string
    {
        if ($pairs === []) {
            return 'Provide `merges` (a non-empty array of {primary_id, secondary_id}) of records to merge.';
        }

        $maxBatchSize = (int) config('chat.max_batch_size');

        if (count($pairs) > $maxBatchSize) {
            return "Too many pairs: at most {$maxBatchSize} per proposal.";
        }

        $ids = array_merge(...array_map(array_values(...), $pairs));

        if (count($ids) !== count(array_unique($ids))) {
            return 'A record can appear in only one pair, and a pair needs two different records. Merge the first pair, then propose the next once it is approved.';
        }

        return null;
    }

    /**
     * @param  list<array{primary_id: string, secondary_id: string}>  $pairs
     * @return array{mergeable: list<array{primary: Model, secondary: Model}>, skipped: list<array{primary_id: string, secondary_id: string}>}
     */
    private function splitMergeable(User $user, array $pairs): array
    {
        $models = $this->modelClass()::query()
            ->whereBelongsTo($user->currentWorkspace)
            ->whereKey(array_merge(...array_map(array_values(...), $pairs)))
            ->get()
            ->keyBy(fn (Model $model): string => (string) $model->getKey());

        $mergeable = [];
        $skipped = [];

        foreach ($pairs as $pair) {
            $primary = $models->get($pair['primary_id']);
            $secondary = $models->get($pair['secondary_id']);

            if ($primary instanceof Model && $secondary instanceof Model && $this->canMerge($user, $primary, $secondary)) {
                $mergeable[] = ['primary' => $primary, 'secondary' => $secondary];

                continue;
            }

            $skipped[] = $pair;
        }

        return ['mergeable' => $mergeable, 'skipped' => $skipped];
    }

    protected function proposalEntity(): ProposalEntity
    {
        return ProposalEntity::from($this->entityType());
    }

    private function canMerge(User $user, Model $primary, Model $secondary): bool
    {
        return $user->can('create', $this->modelClass())
            && $user->can('update', $primary)
            && $user->can('delete', $primary)
            && $user->can('delete', $secondary);
    }

    /** @return list<array{primary_id: string, secondary_id: string}> */
    private function requestedPairs(Request $request): array
    {
        $merges = $request['merges'] ?? null;

        if (! is_array($merges)) {
            return [];
        }

        $pairs = [];

        foreach ($merges as $merge) {
            $primary = is_array($merge) && is_scalar($merge['primary_id'] ?? null) ? (string) $merge['primary_id'] : '';
            $secondary = is_array($merge) && is_scalar($merge['secondary_id'] ?? null) ? (string) $merge['secondary_id'] : '';

            if ($primary === '' || $secondary === '') {
                return [];
            }

            $pairs[] = ['primary_id' => $primary, 'secondary_id' => $secondary];
        }

        return $pairs;
    }

    /**
     * A single pair is a plain proposal; several become a per-pair `_batch` so the dock can
     * approve or skip each one.
     *
     * @param  list<array{primary: Model, secondary: Model}>  $pairs
     * @return array<string, mixed>
     */
    private function actionData(array $pairs): array
    {
        $records = array_map(fn (array $pair): array => [
            '_record_id' => $pair['primary']->getKey(),
            '_model_class' => $this->modelClass(),
            '_merge_with' => $pair['secondary']->getKey(),
        ], $pairs);

        return count($records) === 1 ? $records[0] : ['_batch' => true, 'records' => $records];
    }

    /**
     * @param  list<array{primary: Model, secondary: Model}>  $pairs
     * @return array<string, mixed>
     */
    private function displayData(array $pairs): array
    {
        $entity = $this->proposalEntity();
        $items = array_map(function (array $pair) use ($entity): array {
            $name = $entity->mergePair((string) $pair['secondary']->{$this->nameAttribute()}, (string) $pair['primary']->{$this->nameAttribute()});

            return [
                'summary' => $entity->mergeSummary($name),
                'name' => $name,
                'fields' => [['label' => __('Name'), 'value' => $name]],
            ];
        }, $pairs);

        if (count($items) === 1) {
            return ['title' => $entity->mergeTitle(), ...$items[0]];
        }

        return [
            'title' => $entity->batchTitle(PendingActionOperation::Merge, count($items)),
            'summary' => $entity->batchSummary(PendingActionOperation::Merge, count($items)),
            'items' => $items,
        ];
    }

    private function error(string $message): string
    {
        return (string) json_encode(['error' => $message], JSON_UNESCAPED_SLASHES);
    }
}
