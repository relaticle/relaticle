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
use App\Actions\Team\CreateTeamInvitation;
use App\Enums\CreationSource;
use App\Models\Company;
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
use Relaticle\Chat\Events\PendingActionResolved;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Support\ProposalCoreFields;
use Relaticle\Chat\Support\ProposalOwnership;
use Relaticle\Chat\Support\ProposalPayload;
use Relaticle\Chat\Support\ProposalProgress;
use Relaticle\Chat\Support\RecordReferenceResolver;
use Relaticle\CustomFields\Models\Scopes\CustomFieldsActivableScope;
use Relaticle\CustomFields\Services\TenantContextService;
use RuntimeException;
use Throwable;

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
        CreateTeamInvitation::class,
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
        ?string $turnId = null,
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

        return PendingAction::query()->create([
            'team_id' => $user->currentTeam->getKey(),
            'user_id' => $user->getKey(),
            'conversation_id' => $conversationId,
            'turn_id' => $turnId,
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

    /**
     * Remember why an approval attempt failed, on the still-pending row. The
     * model reads it back through resolvedForConversation() once the proposal
     * is decided, so a discard after a failed approval never reads as a plain
     * user rejection. Best-effort: reporting a failure must never throw over
     * the failure being reported. A later successful approval clears it.
     */
    public function recordResolveFailure(PendingAction $pendingAction, string $message): void
    {
        try {
            $pendingAction->update([
                'result_data' => [
                    ...(is_array($pendingAction->result_data) ? $pendingAction->result_data : []),
                    'last_error' => $message,
                ],
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  list<string>  $excludedFields  field codes the user unchecked on the card; stripped from the write, never persisted into action_data
     */
    public function approve(PendingAction $pendingAction, User $user, array $excludedFields = []): PendingAction
    {
        ProposalOwnership::assert($pendingAction, $user);

        $excludedFields = $this->sanitizedExclusions($pendingAction, $excludedFields);

        // The action executes the underlying CRM write, which may persist custom-field
        // values. When approve() runs there may be no resolvable custom-fields tenant
        // context (the Livewire dock sets the Filament tenant but not necessarily the
        // custom-fields one). Without it the custom-fields TenantScope no-ops and
        // saveCustomFields() iterates EVERY tenant's field definitions, writing value rows
        // across all tenants (cross-tenant leak) and, at scale, exceeding the request
        // timeout. Scope it to the action's team, and restore the prior value afterward so
        // the override never outlives this call (TenantContextService resolves its context
        // before the Filament tenant).
        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($pendingAction->team_id);

        try {
            $resolved = DB::transaction(function () use ($pendingAction, $user, $excludedFields): PendingAction {
                /** @var PendingAction $pendingAction */
                $pendingAction = PendingAction::query()
                    ->lockForUpdate()
                    ->findOrFail($pendingAction->getKey());

                $this->validateResolvable($pendingAction);

                // Batches resolve one record at a time through approveItem()/rejectItem(),
                // which is what the dock's per-record Create steps through, one record and
                // one transaction at a time. Refuse a whole-batch approve so no caller can
                // bypass that and commit every record in one atomic write with no per-item
                // outcome.
                throw_if(
                    ProposalPayload::from($pendingAction)->isBatch,
                    RuntimeException::class,
                    'Batch proposals resolve per item via approveItem()/rejectItem(), not approve().',
                );

                $result = $this->executeAction($pendingAction, $user, $excludedFields);

                $resultData = $result instanceof Model
                    ? ['id' => $result->getKey(), 'type' => $result->getMorphClass()]
                    : ['success' => true];

                // The audit truth: an excluded field was NOT written, and both the
                // transcript and the model's <resolved_actions> block must be able to
                // say so, or the assistant reports a value it never set.
                if ($excludedFields !== []) {
                    $resultData['excluded'] = $excludedFields;
                }

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

        $this->broadcastResolution($resolved, PendingActionStatus::Approved->value, null, true);

        return $resolved;
    }

    public function reject(PendingAction $pendingAction, User $user): PendingAction
    {
        ProposalOwnership::assert($pendingAction, $user);

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

        $this->broadcastResolution($resolved, PendingActionStatus::Rejected->value, null, true);

        return $resolved;
    }

    /**
     * Cancel a step because a step it depends on was rejected. Rejected, not
     * superseded: the user's decision caused it, and the card says which step it
     * followed so the outcome never reads as unexplained.
     */
    public function cancelStep(PendingAction $pendingAction, User $user, string $causedByPendingActionId): PendingAction
    {
        ProposalOwnership::assert($pendingAction, $user);

        $resolved = DB::transaction(function () use ($pendingAction, $causedByPendingActionId): PendingAction {
            /** @var PendingAction $locked */
            $locked = PendingAction::query()
                ->lockForUpdate()
                ->findOrFail($pendingAction->getKey());

            $this->validateResolvable($locked);

            $locked->update([
                'status' => PendingActionStatus::Rejected,
                'resolved_at' => now(),
                'result_data' => [
                    ...(is_array($locked->result_data) ? $locked->result_data : []),
                    'cancelled_by' => $causedByPendingActionId,
                ],
            ]);

            return $locked->refresh();
        });

        $this->broadcastResolution($resolved, PendingActionStatus::Rejected->value, null, true);

        return $resolved;
    }

    /**
     * Resolve a single item of a Create batch proposal. Each item is executed in
     * its own transaction so partial progress survives a later item's failure,
     * unlike approve(), which is atomic for the whole batch. The proposal stays
     * Pending until every item is resolved, then finalizes.
     *
     * @param  list<string>  $excludedFields  field codes the user unchecked for THIS item; stripped from the write, never persisted into action_data
     * @return array{finalized: bool, record: Model|null}
     */
    public function approveItem(PendingAction $pendingAction, User $user, int $index, array $excludedFields = []): array
    {
        ProposalOwnership::assert($pendingAction, $user);

        $excludedFields = $this->sanitizedExclusions($pendingAction, $excludedFields);

        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($pendingAction->team_id);

        try {
            [$finalized, $record, $itemStatus] = DB::transaction(function () use ($pendingAction, $user, $index, $excludedFields): array {
                /** @var PendingAction $locked */
                $locked = PendingAction::query()->lockForUpdate()->findOrFail($pendingAction->getKey());

                $this->validateResolvable($locked);
                $records = ProposalPayload::from($locked)->batchRecords();
                $this->assertItemIndex($records, $index);

                $resultData = is_array($locked->result_data) ? $locked->result_data : [];
                $items = is_array($resultData['items'] ?? null) ? $resultData['items'] : [];
                $progress = ProposalProgress::of($items, count($records));

                // Idempotent: an already-resolved item is a no-op (no re-execute). Report
                // the item's REAL stored status, not an assumed 'approved': it may have
                // been rejected by an earlier call.
                if ($progress->isResolved($index)) {
                    return [$progress->isComplete(), null, $progress->statusOf($index, 'approved')];
                }

                $model = $this->executeBatchItem($locked, $user, $this->withoutExcludedFields($records[$index], $excludedFields, $locked->entity_type));

                // A failure remembered from an earlier attempt is history the
                // moment an item commits; leaving it would report a stale error
                // on a batch that finalizes Approved.
                unset($resultData['last_error']);

                $items[$index] = ['status' => 'approved', 'id' => $model->getKey()];

                if ($excludedFields !== []) {
                    $items[$index]['excluded'] = $excludedFields;
                }
                $resultData['items'] = $items;
                $resultData['type'] ??= $model->getMorphClass();
                $ids = is_array($resultData['ids'] ?? null) ? $resultData['ids'] : [];
                $ids[] = $model->getKey();
                $resultData['ids'] = array_values($ids);

                $finalized = $this->finalizeBatchIfComplete($locked, $items, $records, $resultData);

                return [$finalized, $model, 'approved'];
            });
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }

        $this->broadcastResolution($pendingAction, $itemStatus, $index, $finalized);

        return ['finalized' => $finalized, 'record' => $record];
    }

    /**
     * Skip a single item of a Create batch proposal. Executes nothing.
     *
     * @return array{finalized: bool}
     */
    public function rejectItem(PendingAction $pendingAction, User $user, int $index): array
    {
        ProposalOwnership::assert($pendingAction, $user);

        [$finalized, $itemStatus] = DB::transaction(function () use ($pendingAction, $index): array {
            /** @var PendingAction $locked */
            $locked = PendingAction::query()->lockForUpdate()->findOrFail($pendingAction->getKey());

            $this->validateResolvable($locked);
            $records = ProposalPayload::from($locked)->batchRecords();
            $this->assertItemIndex($records, $index);

            $resultData = is_array($locked->result_data) ? $locked->result_data : [];
            $items = is_array($resultData['items'] ?? null) ? $resultData['items'] : [];
            $progress = ProposalProgress::of($items, count($records));

            // Idempotent: an already-resolved item is a no-op. Report the item's REAL
            // stored status, not an assumed 'rejected': it may have been approved.
            if ($progress->isResolved($index)) {
                return [$progress->isComplete(), $progress->statusOf($index, 'rejected')];
            }

            $items[$index] = ['status' => 'rejected'];
            $resultData['items'] = $items;

            return [$this->finalizeBatchIfComplete($locked, $items, $records, $resultData), 'rejected'];
        });

        $this->broadcastResolution($pendingAction, $itemStatus, $index, $finalized);

        return ['finalized' => $finalized];
    }

    /**
     * Best-effort broadcast: a dropped Reverb frame or connection hiccup must
     * never fail the approve/reject request itself, since the underlying CRM
     * write already committed. A proposal with no conversation_id (a
     * synthetic test fixture, or a legacy row created before conversations
     * were mandatory) has no channel to broadcast on.
     */
    private function broadcastResolution(PendingAction $pendingAction, string $status, ?int $index, bool $finalized): void
    {
        if ($pendingAction->conversation_id === null) {
            return;
        }

        try {
            broadcast(new PendingActionResolved(
                conversationId: $pendingAction->conversation_id,
                pendingActionId: $pendingAction->getKey(),
                status: $status,
                index: $index,
                finalized: $finalized,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $items
     * @param  array<int, array<string, mixed>>  $records
     * @param  array<string, mixed>  $resultData
     */
    private function finalizeBatchIfComplete(PendingAction $pendingAction, array $items, array $records, array $resultData): bool
    {
        if (! ProposalProgress::of($items, count($records))->isComplete()) {
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
     * @param  array<int, mixed>  $records
     */
    private function assertItemIndex(array $records, int $index): void
    {
        throw_if($index < 0 || $index >= count($records), RuntimeException::class, 'Item index out of range');
    }

    /**
     * Execute one batch item by the proposal's operation and return the affected model.
     * Create runs the create action on the record payload; Update and Delete resolve the
     * record's own `_record_id`/`_model_class` within the tenant and run the action on it.
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

        $record = $this->resolvePlanReferences($record, $pendingAction);

        if ($pendingAction->operation === PendingActionOperation::Update) {
            $model = $this->resolveModel($this->resolveModelClass($record), $pendingAction, ProposalPayload::recordIdOf($record));

            /** @var Model */
            return $action->execute($user, $model, ProposalPayload::withoutMarkers($record));
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

        return app()->make($pendingAction->action_class);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function resolveBatchDeleteModel(PendingAction $pendingAction, array $record): Model
    {
        $modelClass = $this->resolveModelClass($record);
        $recordId = ProposalPayload::recordIdOf($record, 'delete batch item');

        $model = $modelClass::query()
            ->with(['team'])
            ->where('team_id', $pendingAction->team_id)
            ->find($recordId);

        // A vanished record fails only this item (RuntimeException -> resolve-failed),
        // never the sibling items. Per-item resolution is independent, not atomic.
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
     * Called when a new user message arrives on the same conversation thread, where
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
     * Every proposal this conversation has decided (approved, rejected, expired):
     * newest first up to the context cap, presented oldest-first. Deliberately NOT
     * windowed to "since the last assistant turn": resolutions write nothing into
     * the replayed transcript, whose tool results keep claiming the proposal is
     * pending, so the outcome must be re-injected on every turn. Superseded
     * proposals are left out: they travel in their own block (see
     * supersededForConversation()) and were never decided by the user.
     *
     * @return list<array{operation: string, entity_type: string, status: string, label: string|null, record_id: string|null, record_ids: list<string>, records: list<array{id: string, label: string|null, url: string}>, skipped: list<string>, excluded: list<array{record: string|null, fields: list<string>}>, failure: string|null}>
     */
    public function resolvedForConversation(string $conversationId, ?string $justDecidedTurnId): array
    {
        $actions = PendingAction::query()
            ->where('conversation_id', $conversationId)
            ->whereIn('status', [
                PendingActionStatus::Approved->value,
                PendingActionStatus::Rejected->value,
                PendingActionStatus::Expired->value,
            ])
            ->whereNotNull('resolved_at')
            ->latest('resolved_at')
            ->orderByDesc('id')
            ->limit($this->contextWindowCap())
            ->get()
            ->reverse()
            ->values();

        return array_values(array_map(fn (PendingAction $action): array => [
            'operation' => $action->operation->value,
            'entity_type' => $action->entity_type,
            'status' => $action->status->value,
            'label' => $this->resolveActionLabel($action),
            'record_id' => $this->resolveResultRecordId($action),
            'record_ids' => $this->resolveResultRecordIds($action),
            'records' => $this->resolvedRecords($action),
            'skipped' => $this->skippedItemLabels($action),
            'excluded' => $this->excludedFieldEntries($action),
            'failure' => is_string($action->result_data['last_error'] ?? null) ? $action->result_data['last_error'] : null,
            // True for the proposals the user's decision JUST resolved, the one that
            // started this resumed turn. Without it every decided proposal looks the
            // same age to the model and a fresh approval gets reported as history.
            'just_decided' => $justDecidedTurnId !== null && $action->turn_id === $justDecidedTurnId,
        ], $actions->all()));
    }

    /**
     * Fields the user unchecked before approving, per record. A field listed here
     * was NOT written even though the proposal's replayed tool result still shows
     * it. Without this the model reports values it never set, the same defect
     * skippedItemLabels() exists to prevent for whole records.
     *
     * @return list<array{record: string|null, fields: list<string>}>
     */
    private function excludedFieldEntries(PendingAction $action): array
    {
        $payload = ProposalPayload::from($action);
        $resultData = is_array($action->result_data) ? $action->result_data : [];

        if (! $payload->isBatch) {
            $codes = is_array($resultData['excluded'] ?? null) ? $resultData['excluded'] : [];
            $displayData = $action->display_data;
            $fields = $this->excludedFieldLabels($codes, is_array($displayData['fields'] ?? null) ? $displayData['fields'] : []);

            return $fields === [] ? [] : [['record' => null, 'fields' => $fields]];
        }

        $items = $payload->items();
        $resultItems = is_array($resultData['items'] ?? null) ? $resultData['items'] : [];
        $entries = [];

        foreach ($resultItems as $index => $result) {
            $codes = is_array($result['excluded'] ?? null) ? $result['excluded'] : [];

            if ($codes === []) {
                continue;
            }

            $item = $items[(int) $index] ?? null;
            $display = is_array($item['display'] ?? null) ? $item['display'] : [];
            $fields = $this->excludedFieldLabels($codes, is_array($display['fields'] ?? null) ? $display['fields'] : []);

            if ($fields === []) {
                continue;
            }

            $label = $item === null ? null : $this->recordLabel($item['data'], $item['display']);
            $entries[] = [
                'record' => $label ?? __('record :position', ['position' => (int) $index + 1]),
                'fields' => $fields,
            ];
        }

        return $entries;
    }

    /**
     * Human labels for excluded field codes, read from the proposal's own display
     * rows; a code with no display row falls back to the code itself.
     *
     * @param  array<array-key, mixed>  $codes
     * @param  array<array-key, mixed>  $displayFields
     * @return list<string>
     */
    private function excludedFieldLabels(array $codes, array $displayFields): array
    {
        $labels = [];

        foreach ($displayFields as $row) {
            if (is_array($row) && is_string($row['code'] ?? null)) {
                $labels[$row['code']] = is_string($row['label'] ?? null) ? $row['label'] : $row['code'];
            }
        }

        return array_map(
            fn (string $code): string => $labels[$code] ?? $code,
            array_values(array_filter($codes, is_string(...))),
        );
    }

    /**
     * Labels of the batch records the user skipped. A partially-skipped batch
     * finalizes as Approved, so without these the model reads "approved" plus
     * the proposal's full record list and reports every record as created,
     * including the ones the user explicitly declined.
     *
     * @return list<string>
     */
    private function skippedItemLabels(PendingAction $action): array
    {
        $payload = ProposalPayload::from($action);

        if (! $payload->isBatch) {
            return [];
        }

        $resultData = is_array($action->result_data) ? $action->result_data : [];
        $resultItems = is_array($resultData['items'] ?? null) ? $resultData['items'] : [];
        $items = $payload->items();
        $labels = [];

        foreach ($resultItems as $index => $result) {
            if (! is_array($result) || ($result['status'] ?? null) !== 'rejected') {
                continue;
            }

            $item = $items[(int) $index] ?? null;
            $label = $item === null ? null : $this->recordLabel($item['data'], $item['display']);
            $labels[] = $label ?? __('record :position', ['position' => (int) $index + 1]);
        }

        return $labels;
    }

    /**
     * Every proposal superseded on this conversation (auto-cancelled because the
     * user sent a new message before it was decided), oldest first, capped like
     * resolvedForConversation().
     *
     * Persistent by design: supersedePendingForConversation() only returns the
     * rows it transitioned during THIS job invocation, so a caller that fed the
     * prompt straight off that return value lost every earlier supersession the
     * moment a later turn superseded nothing new: the model then had no context
     * for a `pending_action` tool result that was actually cancelled turns ago.
     * This queries the persisted rows instead, so the block is never emptier than
     * conversation history actually is.
     *
     * @return list<array{operation: string, entity_type: string, label: string|null}>
     */
    public function supersededForConversation(string $conversationId): array
    {
        $actions = PendingAction::query()
            ->where('conversation_id', $conversationId)
            ->where('status', PendingActionStatus::Superseded->value)
            ->whereNotNull('resolved_at')
            ->latest('resolved_at')
            ->orderByDesc('id')
            ->limit($this->contextWindowCap())
            ->get()
            ->reverse()
            ->values();

        return array_values(array_map(fn (PendingAction $action): array => [
            'operation' => $action->operation->value,
            'entity_type' => $action->entity_type,
            'label' => $this->resolveActionLabel($action),
        ], $actions->all()));
    }

    /**
     * Upper bound on how many decided/superseded proposals ride along in the
     * system prompt. Tied to chat.max_conversation_messages (the replayed
     * message-row window) rather than a standalone number: the replayed
     * transcript can contain at most that many rows, so it can reference at
     * most that many distinct proposals. A cap any smaller could let a proposal
     * age out of both context blocks while its `pending_action` tool result is
     * still being replayed to the model.
     */
    private function contextWindowCap(): int
    {
        return (int) config('chat.max_conversation_messages', 100);
    }

    /**
     * The record name(s) a proposal is about, never the card heading
     * ("Create Task", "Delete 3 Notes"), which is what the model would
     * otherwise be told the record is called.
     */
    public function resolveActionLabel(PendingAction $action): ?string
    {
        $labels = array_values(array_filter(array_map(
            fn (array $item): ?string => $this->recordLabel($item['data'], $item['display']),
            ProposalPayload::from($action)->items(),
        )));

        return $labels === [] ? null : implode(', ', $labels);
    }

    /**
     * Approved records with the label and citation url the read tools use, so a
     * later turn can name and link a record it created without a re-fetch.
     *
     * @return list<array{id: string, label: string|null, url: string}>
     */
    private function resolvedRecords(PendingAction $action): array
    {
        if ($action->status !== PendingActionStatus::Approved) {
            return [];
        }

        // A delete leaves no record to cite. The single-record path is safe by
        // accident (executeDelete returns null, so there is no result id), but a batch
        // stores the deleted models' keys, which would hand the model /r/ links to
        // rows it just removed.
        if ($action->operation === PendingActionOperation::Delete) {
            return [];
        }

        $resolver = resolve(RecordReferenceResolver::class);
        $payload = ProposalPayload::from($action);

        if (! $payload->isBatch) {
            $id = $this->resolveResultRecordId($action);

            return $id === null ? [] : [[
                'id' => $id,
                'label' => $this->resolveActionLabel($action),
                'url' => $resolver->referenceUrl($action->entity_type, $id),
            ]];
        }

        $items = $payload->items();
        $resultData = is_array($action->result_data) ? $action->result_data : [];
        $resultItems = is_array($resultData['items'] ?? null) ? $resultData['items'] : [];
        $records = [];

        foreach ($resultItems as $index => $result) {
            $id = is_array($result) ? ($result['id'] ?? null) : null;

            if (! is_string($id) && ! is_int($id)) {
                continue;
            }

            $item = $items[(int) $index] ?? null;
            $records[] = [
                'id' => (string) $id,
                'label' => $item === null ? null : $this->recordLabel($item['data'], $item['display']),
                'url' => $resolver->referenceUrl($action->entity_type, (string) $id),
            ];
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $display
     */
    private function recordLabel(array $data, array $display): ?string
    {
        foreach (['name', 'title', 'email'] as $field) {
            if (is_string($data[$field] ?? null) && $data[$field] !== '') {
                return $data[$field];
            }
        }

        $fields = is_array($display['fields'] ?? null) ? $display['fields'] : [];

        foreach ($fields as $row) {
            if (! is_array($row) || ! in_array($row['label'] ?? null, ['Name', 'Title', 'Email'], true)) {
                continue;
            }

            $value = $row['new'] ?? $row['value'] ?? $row['old'] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
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

    /**
     * Swap every `$ref:<pending_action_id>` for the id its step created. Runs inside
     * the approving transaction, so a dependency approved a moment earlier is
     * already visible here.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function resolvePlanReferences(array $data, PendingAction $pendingAction): array
    {
        return resolve(PlanReferenceResolver::class)->resolve($data, $pendingAction);
    }

    /**
     * @param  list<string>  $excludedFields
     */
    private function executeAction(PendingAction $pendingAction, User $user, array $excludedFields = []): mixed
    {
        $actionClass = $pendingAction->action_class;

        throw_unless(
            in_array($actionClass, self::ALLOWED_ACTION_CLASSES, true),
            RuntimeException::class,
            'Action class not allowlisted',
        );

        $action = app()->make($actionClass);

        return match ($pendingAction->operation) {
            PendingActionOperation::Create => $this->executeCreate($action, $user, $pendingAction, $excludedFields),
            PendingActionOperation::Update => $this->executeUpdate($action, $user, $pendingAction, $excludedFields),
            PendingActionOperation::Delete => $this->executeDelete($action, $user, $pendingAction),
        };
    }

    /**
     * @param  list<string>  $excludedFields
     */
    private function executeCreate(object $action, User $user, PendingAction $pendingAction, array $excludedFields = []): Model
    {
        if (! method_exists($action, 'execute')) {
            throw new RuntimeException("Action class {$pendingAction->action_class} does not have an execute method");
        }

        $data = $this->withoutExcludedFields(
            $this->resolvePlanReferences($pendingAction->action_data, $pendingAction),
            $excludedFields,
            $pendingAction->entity_type,
        );

        /** @var Model */
        return $action->execute($user, $data, CreationSource::CHAT);
    }

    /**
     * @param  list<string>  $excludedFields
     */
    private function executeUpdate(object $action, User $user, PendingAction $pendingAction, array $excludedFields = []): mixed
    {
        $data = $this->withoutExcludedFields(
            $this->resolvePlanReferences($pendingAction->action_data, $pendingAction),
            $excludedFields,
            $pendingAction->entity_type,
        );
        $modelClass = $this->resolveModelClass($data);

        $model = $this->resolveModel($modelClass, $pendingAction, ProposalPayload::recordIdOf($pendingAction->action_data));

        if (! method_exists($action, 'execute')) {
            throw new RuntimeException("Action class {$pendingAction->action_class} does not have an execute method");
        }

        return $action->execute($user, $model, ProposalPayload::withoutMarkers($data));
    }

    /**
     * Exclusions the card may actually apply: real field codes only. The list is
     * client-writable state, so this guard is the defense, not the checkbox UI:
     * payload markers are untouchable, the entity's title key is never optional
     * (a create without it fails validation), and a delete has no per-field
     * writes to exclude.
     *
     * @param  array<array-key, mixed>  $excludedFields
     * @return list<string>
     */
    private function sanitizedExclusions(PendingAction $pendingAction, array $excludedFields): array
    {
        if ($pendingAction->operation === PendingActionOperation::Delete) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $excludedFields,
            fn (mixed $code): bool => is_string($code)
                && $code !== ''
                && ! str_starts_with($code, '_')
                && $code !== ProposalCoreFields::titleKey($pendingAction->entity_type),
        )));
    }

    /**
     * Drop the user-unchecked fields from a record payload before execution. An
     * absent key is simply not written (create) or left unchanged (update). The
     * stored action_data itself stays untouched, per the frozen-payload contract.
     *
     * @param  array<array-key, mixed>  $record
     * @param  list<string>  $excludedFields
     * @return array<array-key, mixed>
     */
    private function withoutExcludedFields(array $record, array $excludedFields, string $entityType): array
    {
        foreach ($excludedFields as $code) {
            if (ProposalCoreFields::isCore($entityType, $code)) {
                unset($record[$code]);

                continue;
            }

            unset($record[$code]);

            if (is_array($record['custom_fields'] ?? null)) {
                unset($record['custom_fields'][$code]);

                if ($record['custom_fields'] === []) {
                    unset($record['custom_fields']);
                }
            }
        }

        return $record;
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

    private function resolveModel(string $modelClass, PendingAction $pendingAction, string $recordId): Model
    {
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
        $ids = ProposalPayload::from($pendingAction)->recordIds();

        return array_values(
            $modelClass::query()
                ->with(['team'])
                ->where('team_id', $pendingAction->team_id)
                ->findOrFail($ids)
                ->all(),
        );
    }
}
