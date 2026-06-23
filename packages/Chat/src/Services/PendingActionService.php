<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Actions\Company\CreateCompany;
use App\Actions\Company\DeleteCompany;
use App\Actions\Company\UpdateCompany;
use App\Actions\CustomFields\AddCustomFieldOptions;
use App\Actions\CustomFields\CreateCustomField;
use App\Actions\CustomFields\UpdateCustomField;
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
use App\Models\CustomField;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\CustomFields\Models\Scopes\CustomFieldsActivableScope;
use Relaticle\CustomFields\Services\TenantContextService;
use RuntimeException;

final readonly class PendingActionService
{
    /** @var list<class-string<Model>> */
    private const array ALLOWED_MODEL_CLASSES = [
        Company::class,
        People::class,
        Opportunity::class,
        Task::class,
        Note::class,
        CustomField::class,
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
        CreateCustomField::class,
        UpdateCustomField::class,
        AddCustomFieldOptions::class,
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

        // Idempotency across job retries. A continuation creates its proposal mid-stream; if a
        // later chunk throws a transient error (429/529/503) the job is retried from the top and
        // re-emits the identical tool call. Without this guard every retry inserts another
        // duplicate proposal card. Collapse an identical still-pending proposal in the same
        // conversation instead of inserting a duplicate. Only PENDING rows match, so an already
        // approved/rejected proposal never absorbs a legitimate fresh one.
        if ($conversationId !== null) {
            $duplicate = PendingAction::query()
                ->where('conversation_id', $conversationId)
                ->where('action_class', $actionClass)
                ->where('operation', $operation)
                ->where('entity_type', $entityType)
                ->pending()
                ->get()
                ->first(static fn (PendingAction $existing): bool => $existing->action_data === $actionData);

            if ($duplicate instanceof PendingAction) {
                return $duplicate;
            }
        }

        if ($conversationId !== null && $operation === PendingActionOperation::Create) {
            $warning = $this->duplicateCreateWarning($conversationId, $actionClass, $entityType, $actionData);
            if ($warning !== null) {
                $displayData['duplicate_warning'] = $warning;
            }
        }

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
        // values. When approve() runs there may be no resolvable custom-fields tenant
        // context (the Livewire dock sets the Filament tenant but not necessarily the
        // custom-fields one). Without it the custom-fields TenantScope no-ops and
        // saveCustomFields() iterates EVERY tenant's field definitions — writing value rows
        // across all tenants (cross-tenant leak) and, at scale, exceeding the request
        // timeout. Scope it to the action's team, and restore the prior value afterward so
        // the override never outlives this call (TenantContextService resolves its context
        // before the Filament tenant).
        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($pendingAction->team_id);

        try {
            $resolved = DB::transaction(function () use ($pendingAction, $user): PendingAction {
                /** @var PendingAction $pendingAction */
                $pendingAction = PendingAction::query()
                    ->lockForUpdate()
                    ->findOrFail($pendingAction->getKey());

                $this->validateResolvable($pendingAction);

                // Batches resolve one record at a time through approveItem()/rejectItem() —
                // the dock has no whole-batch control. Refuse a whole-batch approve so no
                // caller can bypass the per-item review and commit every record at once.
                throw_if(
                    ($pendingAction->action_data['_batch'] ?? false) === true,
                    RuntimeException::class,
                    'Batch proposals resolve per item via approveItem()/rejectItem(), not approve().',
                );

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

        return $resolved;
    }

    public function reject(PendingAction $pendingAction): PendingAction
    {
        return DB::transaction(function () use ($pendingAction): PendingAction {
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
    }

    /**
     * Resolve a single item of a Create batch proposal. Each item is executed in
     * its own transaction so partial progress survives a later item's failure —
     * unlike approve(), which is atomic for the whole batch. The proposal stays
     * Pending until every item is resolved, then finalizes.
     *
     * @return array{finalized: bool, record: Model|null}
     */
    public function approveItem(PendingAction $pendingAction, User $user, int $index): array
    {
        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($pendingAction->team_id);

        try {
            [$finalized, $record] = DB::transaction(function () use ($pendingAction, $user, $index): array {
                /** @var PendingAction $locked */
                $locked = PendingAction::query()->lockForUpdate()->findOrFail($pendingAction->getKey());

                $this->validateResolvable($locked);
                $records = $this->batchRecords($locked);
                $this->assertItemIndex($records, $index);

                $resultData = is_array($locked->result_data) ? $locked->result_data : [];
                $items = is_array($resultData['items'] ?? null) ? $resultData['items'] : [];

                // Idempotent: an already-resolved item is a no-op (no re-execute).
                if (isset($items[(string) $index])) {
                    return [$this->isComplete($items, $records), null];
                }

                $model = $this->executeBatchItem($locked, $user, $records[$index]);

                $items[(string) $index] = ['status' => 'approved', 'id' => $model->getKey()];
                $resultData['items'] = $items;
                $resultData['type'] ??= $model->getMorphClass();
                $ids = is_array($resultData['ids'] ?? null) ? $resultData['ids'] : [];
                $ids[] = $model->getKey();
                $resultData['ids'] = array_values($ids);

                $finalized = $this->finalizeBatchIfComplete($locked, $items, $records, $resultData);

                return [$finalized, $model];
            });
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }

        return ['finalized' => $finalized, 'record' => $record];
    }

    /**
     * Skip a single item of a Create batch proposal. Executes nothing.
     *
     * @return array{finalized: bool}
     */
    public function rejectItem(PendingAction $pendingAction, int $index): array
    {
        $finalized = DB::transaction(function () use ($pendingAction, $index): bool {
            /** @var PendingAction $locked */
            $locked = PendingAction::query()->lockForUpdate()->findOrFail($pendingAction->getKey());

            $this->validateResolvable($locked);
            $records = $this->batchRecords($locked);
            $this->assertItemIndex($records, $index);

            $resultData = is_array($locked->result_data) ? $locked->result_data : [];
            $items = is_array($resultData['items'] ?? null) ? $resultData['items'] : [];

            if (isset($items[(string) $index])) {
                return $this->isComplete($items, $records);
            }

            $items[(string) $index] = ['status' => 'rejected'];
            $resultData['items'] = $items;

            return $this->finalizeBatchIfComplete($locked, $items, $records, $resultData);
        });

        return ['finalized' => $finalized];
    }

    /**
     * @param  array<string, mixed>  $items
     * @param  array<int, array<string, mixed>>  $records
     * @param  array<string, mixed>  $resultData
     */
    private function finalizeBatchIfComplete(PendingAction $pendingAction, array $items, array $records, array $resultData): bool
    {
        if (! $this->isComplete($items, $records)) {
            $pendingAction->update(['result_data' => $resultData]);

            return false;
        }

        $ids = is_array($resultData['ids'] ?? null) ? $resultData['ids'] : [];
        $resultData['count'] = count($ids);

        $pendingAction->update([
            'status' => $ids === [] ? PendingActionStatus::Rejected : PendingActionStatus::Approved,
            'resolved_at' => now(),
            'result_data' => $resultData,
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $items
     * @param  array<int, mixed>  $records
     */
    private function isComplete(array $items, array $records): bool
    {
        return count($items) >= count($records);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function batchRecords(PendingAction $pendingAction): array
    {
        $data = $pendingAction->action_data;

        throw_if(($data['_batch'] ?? false) !== true, RuntimeException::class, 'Per-item resolution applies only to batch proposals');

        $records = $data['records'] ?? null;

        throw_if(! is_array($records) || $records === [], RuntimeException::class, 'Missing or invalid records in batch action data');

        throw_if(
            array_filter($records, static fn (mixed $r): bool => ! is_array($r)) !== [],
            RuntimeException::class,
            'Batch record data is malformed',
        );

        return array_values($records);
    }

    /**
     * @param  array<int, mixed>  $records
     */
    private function assertItemIndex(array $records, int $index): void
    {
        throw_if($index < 0 || $index >= count($records), RuntimeException::class, 'Item index out of range');
    }

    /**
     * Execute one batch item by the proposal's operation and return the affected model.
     * Create runs the create action on the record payload; Delete resolves the record's
     * own `_record_id`/`_model_class` within the tenant and runs the delete action.
     * Update is never batched (one record per Update proposal), so it is rejected here.
     *
     * @param  array<string, mixed>  $record
     */
    private function executeBatchItem(PendingAction $pendingAction, User $user, array $record): Model
    {
        $action = $this->makeBatchItemAction($pendingAction);

        if (! method_exists($action, 'execute')) {
            throw new RuntimeException("Action class {$pendingAction->action_class} does not have an execute method");
        }

        if ($pendingAction->operation === PendingActionOperation::Delete) {
            $model = $this->resolveBatchDeleteModel($pendingAction, $record);
            $action->execute($user, $model);

            return $model;
        }

        /** @var Model */
        return $action->execute($user, $record, CreationSource::CHAT);
    }

    private function makeBatchItemAction(PendingAction $pendingAction): object
    {
        throw_unless(
            in_array($pendingAction->action_class, self::ALLOWED_ACTION_CLASSES, true),
            RuntimeException::class,
            'Action class not allowlisted',
        );

        throw_if(
            $pendingAction->operation === PendingActionOperation::Update,
            RuntimeException::class,
            'Per-item resolution applies to create and delete proposals',
        );

        return app()->make($pendingAction->action_class);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function resolveBatchDeleteModel(PendingAction $pendingAction, array $record): Model
    {
        $modelClass = $this->resolveModelClass($record);
        $recordId = $record['_record_id'] ?? null;

        throw_if(! is_string($recordId) && ! is_int($recordId), RuntimeException::class, 'Missing or invalid _record_id in delete batch item');

        $model = $modelClass::query()
            ->with($this->deleteEagerLoads($modelClass))
            ->where('team_id', $pendingAction->team_id)
            ->find($recordId);

        // A vanished record fails only this item (RuntimeException -> resolve-failed),
        // never the sibling items — per-item resolution is independent, not atomic.
        throw_if(! $model instanceof Model, RuntimeException::class, 'Record not found');

        return $model;
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
     * @return list<array{operation: string, entity_type: string, status: string, label: string|null, record_id: string|null, record_ids: list<string>}>
     */
    public function resolvedSinceLastAssistantMessage(string $conversationId): array
    {
        $lastAssistantAt = $this->lastAssistantMessageAt($conversationId);

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
            'record_ids' => $this->resolveResultRecordIds($action),
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

    private function lastAssistantMessageAt(string $conversationId): ?string
    {
        return DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->latest('created_at')
            ->orderByDesc('id')
            ->value('created_at');
    }

    private function resolveResultRecordId(PendingAction $action): ?string
    {
        $resultData = $action->result_data;
        $recordId = is_array($resultData) ? ($resultData['id'] ?? null) : null;

        return is_string($recordId) && $recordId !== '' ? $recordId : null;
    }

    /**
     * @return list<string>
     */
    private function resolveResultRecordIds(PendingAction $action): array
    {
        $resultData = $action->result_data;

        if (! is_array($resultData) || ! isset($resultData['ids']) || ! is_array($resultData['ids'])) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $id): string => (string) $id, $resultData['ids']),
            static fn (string $id): bool => $id !== '',
        ));
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

    private function executeAction(PendingAction $pendingAction, User $user): mixed
    {
        $actionClass = $pendingAction->action_class;

        throw_unless(
            in_array($actionClass, self::ALLOWED_ACTION_CLASSES, true),
            RuntimeException::class,
            'Action class not allowlisted',
        );

        $action = app()->make($actionClass);

        return match ($pendingAction->operation) {
            PendingActionOperation::Create => $this->executeCreate($action, $user, $pendingAction),
            PendingActionOperation::Update => $this->executeUpdate($action, $user, $pendingAction),
            PendingActionOperation::Delete => $this->executeDelete($action, $user, $pendingAction),
        };
    }

    private function executeCreate(object $action, User $user, PendingAction $pendingAction): Model
    {
        if (! method_exists($action, 'execute')) {
            throw new RuntimeException("Action class {$pendingAction->action_class} does not have an execute method");
        }

        /** @var Model */
        return $action->execute($user, $pendingAction->action_data, CreationSource::CHAT);
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

        // CustomField uses tenant_id (from the custom-fields package) rather than the
        // team_id column used by all other CRM models. Scope the lookup accordingly.
        $tenantColumn = $modelClass === CustomField::class
            ? (string) config('custom-fields.database.column_names.tenant_foreign_key', 'tenant_id')
            : 'team_id';

        $query = $modelClass::query()->where($tenantColumn, $pendingAction->team_id);

        // CustomField has a global active scope that would exclude deactivated fields;
        // skip it so an update-to-deactivate proposal can find the field regardless.
        if ($modelClass === CustomField::class) {
            $query->withoutGlobalScope(CustomFieldsActivableScope::class);
        }

        return $query->findOrFail($recordId);
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
     * A same-titled create was proposed/approved moments ago — usually a model
     * regeneration after a transient failure. Approving both would write real
     * duplicate records, so the card carries an explicit warning.
     *
     * @param  array<string, mixed>  $actionData
     */
    private function duplicateCreateWarning(string $conversationId, string $actionClass, string $entityType, array $actionData): ?string
    {
        $titleMap = $this->proposedTitleMap($actionData);

        if ($titleMap === []) {
            return null;
        }

        $recent = PendingAction::query()
            ->where('conversation_id', $conversationId)
            ->where('action_class', $actionClass)
            ->where('entity_type', $entityType)
            ->where('operation', PendingActionOperation::Create)
            ->whereIn('status', [PendingActionStatus::Pending, PendingActionStatus::Approved])
            ->where('created_at', '>=', now()->subMinutes(15))
            ->get();

        $incomingLower = array_keys($titleMap);

        foreach ($recent as $existing) {
            $existingLower = $this->proposedTitles($existing->action_data);
            $overlap = array_intersect($incomingLower, $existingLower);
            if ($overlap !== []) {
                $matchedLower = array_values($overlap)[0];
                $label = $titleMap[$matchedLower];

                return "Heads up: \"{$label}\" was already proposed or created a moment ago — approving this may create a duplicate.";
            }
        }

        return null;
    }

    /**
     * Returns a map of lowercased title => original-cased title for all records
     * in the given action data (handles both single and batch shapes).
     *
     * @param  array<string, mixed>  $actionData
     * @return array<string, string>
     */
    private function proposedTitleMap(array $actionData): array
    {
        $records = ($actionData['_batch'] ?? false) === true && is_array($actionData['records'] ?? null)
            ? $actionData['records']
            : [$actionData];

        $map = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }
            foreach (['name', 'title'] as $field) {
                if (is_string($record[$field] ?? null) && $record[$field] !== '') {
                    $lower = mb_strtolower(trim($record[$field]));
                    $map[$lower] = trim($record[$field]);
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Returns lowercased titles for all records in the given action data.
     * Used when checking existing proposals against incoming ones.
     *
     * @param  array<string, mixed>  $actionData
     * @return list<string>
     */
    private function proposedTitles(array $actionData): array
    {
        return array_keys($this->proposedTitleMap($actionData));
    }
}
