<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Actions\Company\CreateCompany;
use App\Actions\Company\DeleteCompany;
use App\Actions\Company\UpdateCompany;
use App\Actions\Note\CreateNote;
use App\Actions\Note\DeleteNote;
use App\Actions\Note\UpdateNote;
use App\Actions\Opportunity\CreateOpportunity;
use App\Actions\Opportunity\DeleteOpportunity;
use App\Actions\Opportunity\UpdateOpportunity;
use App\Actions\People\CreatePeople;
use App\Actions\People\DeletePeople;
use App\Actions\People\UpdatePeople;
use App\Actions\Task\CreateTask;
use App\Actions\Task\DeleteTask;
use App\Actions\Task\UpdateTask;
use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\Concerns\InvalidatesRelatedAiSummaries;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\CustomFields\Services\TenantContextService;
use RuntimeException;

final readonly class PendingActionService
{
    public function __construct(
        private ApprovalContinuationService $continuation,
    ) {}

    /** @var list<class-string<Model>> */
    private const array ALLOWED_MODEL_CLASSES = [
        Company::class,
        People::class,
        Opportunity::class,
        Task::class,
        Note::class,
    ];

    /** @var list<class-string> */
    private const array ALLOWED_ACTION_CLASSES = [
        CreateCompany::class,
        UpdateCompany::class,
        DeleteCompany::class,
        CreatePeople::class,
        UpdatePeople::class,
        DeletePeople::class,
        CreateOpportunity::class,
        UpdateOpportunity::class,
        DeleteOpportunity::class,
        CreateTask::class,
        UpdateTask::class,
        DeleteTask::class,
        CreateNote::class,
        UpdateNote::class,
        DeleteNote::class,
    ];

    /**
     * @param  array<string, mixed>  $actionData
     * @param  array<string, mixed>  $displayData
     */
    public function createProposal(
        User $user,
        ?string $conversationId,
        string $actionClass,
        PendingActionOperation $operation,
        string $entityType,
        array $actionData,
        array $displayData,
        ?string $messageId = null,
    ): PendingAction {
        $expiryMinutes = (int) config('chat.pending_action_expiry_minutes', 15);

        return PendingAction::query()->create([
            'team_id' => $user->currentTeam->getKey(),
            'user_id' => $user->getKey(),
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'action_class' => $actionClass,
            'operation' => $operation,
            'entity_type' => $entityType,
            'action_data' => $actionData,
            'display_data' => $displayData,
            'status' => PendingActionStatus::Pending,
            'expires_at' => now()->addMinutes($expiryMinutes),
        ]);
    }

    public function approve(PendingAction $pendingAction, User $user): PendingAction
    {
        // The action executes the underlying CRM write, which may persist custom-field
        // values. Approvals arrive via the /chat/actions/* routes, which bypass the
        // Filament panel middleware and therefore leave no tenant context. Without one,
        // the custom-fields TenantScope no-ops and saveCustomFields() iterates EVERY
        // tenant's field definitions — writing value rows across all tenants (cross-tenant
        // leak) and, at scale, exceeding the request timeout. Scope it to the action's team,
        // and restore the prior value afterward so the override never outlives this call
        // (TenantContextService resolves its context before the Filament tenant).
        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($pendingAction->team_id);

        try {
            $resolved = DB::transaction(function () use ($pendingAction, $user): PendingAction {
                /** @var PendingAction $pendingAction */
                $pendingAction = PendingAction::query()
                    ->lockForUpdate()
                    ->findOrFail($pendingAction->getKey());

                $this->validateResolvable($pendingAction);

                $result = $this->executeAction($pendingAction, $user);

                $resultData = $result instanceof Model
                    ? ['id' => $result->getKey(), 'type' => $result->getMorphClass()]
                    : ['success' => true];

                $pendingAction->update([
                    'status' => PendingActionStatus::Approved,
                    'resolved_at' => now(),
                    'result_data' => $resultData,
                ]);

                return $pendingAction->refresh();
            });
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }

        $this->continuation->dispatchAfterApproval($resolved, 'approved');

        return $resolved;
    }

    public function reject(PendingAction $pendingAction): PendingAction
    {
        $resolved = DB::transaction(function () use ($pendingAction): PendingAction {
            /** @var PendingAction $locked */
            $locked = PendingAction::query()
                ->lockForUpdate()
                ->findOrFail($pendingAction->getKey());

            $this->validateResolvable($locked);

            $locked->update([
                'status' => PendingActionStatus::Rejected,
                'resolved_at' => now(),
            ]);

            return $locked->refresh();
        });

        $this->continuation->dispatchAfterApproval($resolved, 'rejected');

        return $resolved;
    }

    public function restore(PendingAction $pendingAction, User $user): PendingAction
    {
        return DB::transaction(function () use ($pendingAction, $user): PendingAction {
            /** @var PendingAction $locked */
            $locked = PendingAction::query()
                ->lockForUpdate()
                ->findOrFail($pendingAction->getKey());

            $this->validateRestorable($locked);

            $modelClass = $this->resolveModelClass($locked->action_data);

            throw_unless(in_array(SoftDeletes::class, class_uses_recursive($modelClass), true), RuntimeException::class, 'This record cannot be restored');

            $ids = $locked->action_data['_record_ids'] ?? null;

            throw_if(! is_array($ids) || $ids === [], RuntimeException::class, 'Missing or invalid _record_ids in action data');

            foreach ($ids as $recordId) {
                $record = $this->findTrashedRecord($modelClass, $locked->team_id, $recordId);

                throw_if(! $record instanceof Model, RuntimeException::class, 'Record not found');

                abort_unless($user->can('restore', $record), 403);

                $this->restoreTrashedRecord($record);
            }

            $locked->update([
                'status' => PendingActionStatus::Restored,
            ]);

            return $locked->refresh();
        });
    }

    public function expireStale(): int
    {
        return PendingAction::query()
            ->expired()
            ->update([
                'status' => PendingActionStatus::Expired,
                'resolved_at' => now(),
            ]);
    }

    /**
     * Atomically mark every still-pending action on a conversation as superseded.
     *
     * Called when a new user message arrives on the same conversation thread —
     * the user has effectively moved on without approving or rejecting. Returns
     * the rows in their pre-update state so callers can surface them to the model.
     *
     * @return list<PendingAction>
     */
    public function supersedePendingForConversation(string $conversationId): array
    {
        return DB::transaction(function () use ($conversationId): array {
            $pending = array_values(PendingAction::query()
                ->where('conversation_id', $conversationId)
                ->pending()
                ->lockForUpdate()
                ->get()
                ->all());

            if ($pending === []) {
                return [];
            }

            $resolvedAt = now();

            foreach ($pending as $action) {
                $action->update([
                    'status' => PendingActionStatus::Superseded,
                    'resolved_at' => $resolvedAt,
                ]);
            }

            return $pending;
        });
    }

    /**
     * Actions on this conversation resolved AFTER the latest assistant message —
     * i.e. decisions the replayed transcript does not yet reflect. Used to inject
     * a <resolved_actions> block so the model's knowledge of approvals does not
     * depend on the AI continuation having successfully journaled them.
     *
     * @return list<array{operation: string, entity_type: string, status: string, label: string|null, record_id: string|null}>
     */
    public function resolvedSinceLastAssistantMessage(string $conversationId): array
    {
        $lastAssistantAt = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->latest('created_at')
            ->orderByDesc('id')
            ->value('created_at');

        $query = PendingAction::query()
            ->where('conversation_id', $conversationId)
            ->whereIn('status', [
                PendingActionStatus::Approved->value,
                PendingActionStatus::Rejected->value,
                PendingActionStatus::Expired->value,
                PendingActionStatus::Superseded->value,
            ])
            ->whereNotNull('resolved_at');

        if ($lastAssistantAt !== null) {
            $query->where('resolved_at', '>', $lastAssistantAt);
        }

        $actions = $query->oldest('resolved_at')->limit(20)->get();

        return array_values(array_map(fn (PendingAction $action): array => [
            'operation' => $action->operation->value,
            'entity_type' => $action->entity_type,
            'status' => $action->status->value,
            'label' => $this->resolveActionLabel($action),
            'record_id' => $this->resolveResultRecordId($action),
        ], $actions->all()));
    }

    private function resolveActionLabel(PendingAction $action): ?string
    {
        $display = $action->display_data;
        $data = $action->action_data;

        foreach (['name', 'title'] as $field) {
            if (isset($display[$field]) && is_string($display[$field]) && $display[$field] !== '') {
                return $display[$field];
            }
            if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
                return $data[$field];
            }
        }

        return null;
    }

    private function resolveResultRecordId(PendingAction $action): ?string
    {
        $resultData = $action->result_data;
        $recordId = is_array($resultData) ? ($resultData['id'] ?? null) : null;

        return is_string($recordId) && $recordId !== '' ? $recordId : null;
    }

    private function validateResolvable(PendingAction $pendingAction): void
    {
        if ($pendingAction->isPending() && $pendingAction->isExpired()) {
            $pendingAction->update([
                'status' => PendingActionStatus::Expired,
                'resolved_at' => now(),
            ]);
            throw new RuntimeException('This action has expired');
        }

        throw_unless($pendingAction->isPending(), RuntimeException::class, 'This action has already been resolved');
    }

    private function validateRestorable(PendingAction $pendingAction): void
    {
        throw_if($pendingAction->operation !== PendingActionOperation::Delete, RuntimeException::class, 'Only deleted records can be restored');

        throw_if($pendingAction->status !== PendingActionStatus::Approved, RuntimeException::class, 'Only approved deletions can be restored');

        $resolvedAt = $pendingAction->resolved_at;

        throw_if($resolvedAt === null || $resolvedAt->lt(now()->subMinutes(5)), RuntimeException::class, 'undo_window_expired');
    }

    private function executeAction(PendingAction $pendingAction, User $user): mixed
    {
        $actionClass = $pendingAction->action_class;

        throw_unless(
            in_array($actionClass, self::ALLOWED_ACTION_CLASSES, true),
            RuntimeException::class,
            'Action class not allowlisted',
        );

        $action = app()->make($actionClass);
        $data = $pendingAction->action_data;

        return match ($pendingAction->operation) {
            PendingActionOperation::Create => $action->execute($user, $data, CreationSource::CHAT),
            PendingActionOperation::Update => $this->executeUpdate($action, $user, $pendingAction),
            PendingActionOperation::Delete => $this->executeDelete($action, $user, $pendingAction),
        };
    }

    private function executeUpdate(object $action, User $user, PendingAction $pendingAction): mixed
    {
        $data = $pendingAction->action_data;
        $modelClass = $this->resolveModelClass($data);

        unset($data['_record_id'], $data['_model_class']);

        $model = $this->resolveModel($modelClass, $pendingAction);

        if (! method_exists($action, 'execute')) {
            throw new RuntimeException("Action class {$pendingAction->action_class} does not have an execute method");
        }

        return $action->execute($user, $model, $data);
    }

    private function executeDelete(object $action, User $user, PendingAction $pendingAction): mixed
    {
        if (! method_exists($action, 'execute')) {
            throw new RuntimeException("Action class {$pendingAction->action_class} does not have an execute method");
        }

        foreach ($this->resolveDeleteModels($pendingAction) as $model) {
            $action->execute($user, $model);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return class-string<Model>
     */
    private function resolveModelClass(array $data): string
    {
        $modelClass = $data['_model_class'] ?? null;

        throw_if(! is_string($modelClass) || ! in_array($modelClass, self::ALLOWED_MODEL_CLASSES, true), RuntimeException::class, "Invalid model class: {$modelClass}");

        return $modelClass;
    }

    private function resolveModel(string $modelClass, PendingAction $pendingAction): Model
    {
        $recordId = $pendingAction->action_data['_record_id'] ?? null;

        throw_if(! is_string($recordId) && ! is_int($recordId), RuntimeException::class, 'Missing or invalid _record_id in action data');

        return $modelClass::query()
            ->where('team_id', $pendingAction->team_id)
            ->findOrFail($recordId);
    }

    /**
     * @return list<Model>
     */
    private function resolveDeleteModels(PendingAction $pendingAction): array
    {
        $modelClass = $this->resolveModelClass($pendingAction->action_data);
        $ids = $pendingAction->action_data['_record_ids'] ?? null;

        throw_if(! is_array($ids) || $ids === [], RuntimeException::class, 'Missing or invalid _record_ids in action data');

        return array_values(
            $modelClass::query()
                ->with($this->deleteEagerLoads($modelClass))
                ->where('team_id', $pendingAction->team_id)
                ->findOrFail($ids)
                ->all(),
        );
    }

    /**
     * Relations to load before deleting so model observers (AI-summary
     * invalidation) don't trip Model::preventLazyLoading() in dev/test.
     *
     * @param  class-string<Model>  $modelClass
     * @return list<string>
     */
    private function deleteEagerLoads(string $modelClass): array
    {
        $relations = ['team'];

        if (in_array(InvalidatesRelatedAiSummaries::class, class_uses_recursive($modelClass), true)) {
            return array_merge($relations, array_values(array_filter(
                InvalidatesRelatedAiSummaries::summaryRelations(),
                static fn (string $relation): bool => method_exists($modelClass, $relation),
            )));
        }

        return $relations;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function findTrashedRecord(string $modelClass, string $teamId, string|int $recordId): ?Model
    {
        return match ($modelClass) {
            Company::class => Company::withTrashed()->where('team_id', $teamId)->whereKey($recordId)->first(),
            People::class => People::withTrashed()->where('team_id', $teamId)->whereKey($recordId)->first(),
            Opportunity::class => Opportunity::withTrashed()->where('team_id', $teamId)->whereKey($recordId)->first(),
            Task::class => Task::withTrashed()->where('team_id', $teamId)->whereKey($recordId)->first(),
            Note::class => Note::withTrashed()->where('team_id', $teamId)->whereKey($recordId)->first(),
            default => null,
        };
    }

    private function restoreTrashedRecord(Model $record): void
    {
        match (true) {
            $record instanceof Company => $record->restore(),
            $record instanceof People => $record->restore(),
            $record instanceof Opportunity => $record->restore(),
            $record instanceof Task => $record->restore(),
            $record instanceof Note => $record->restore(),
            default => throw new RuntimeException('This record cannot be restored'),
        };
    }
}
