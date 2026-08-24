<?php

declare(strict_types=1);

namespace Relaticle\Chat\Livewire\Chat;

use App\Livewire\BaseLivewireComponent;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Services\ProposalEditor;
use Relaticle\Chat\Services\ProposalPlanService;
use Relaticle\Chat\Services\Tools\ProposalDisplayBuilder;
use Relaticle\Chat\Services\Tools\ProposalFieldSchemaDescriber;
use Relaticle\Chat\Services\TurnContinuationService;
use Relaticle\Chat\Support\ProposalCoreFields;
use Relaticle\Chat\Support\ProposalPayload;
use Relaticle\Chat\Support\ProposalProgress;
use Relaticle\Chat\Support\RecordReferenceResolver;
use Relaticle\Chat\Support\TeamMembersContext;
use Relaticle\CustomFields\Facades\CustomFields;
use RuntimeException;

/**
 * The docked proposal card: the one place a chat-proposed write is decided.
 *
 * It presents a PLAN — every proposal the assistant's turn produced, in order —
 * even when that plan has a single step, which is the common case and behaves
 * exactly as it always has. A multi-step plan adds the parts a chained request
 * needs: each step's fields stay visible, a step whose dependency is still
 * pending cannot be approved alone, and one Approve resolves them in order.
 *
 * $pendingActionId anchors the plan (the id the transcript docked), while
 * $activeStepId is the step the per-record controls act on. For a one-step plan
 * they are the same id.
 */
final class ProposalCard extends BaseLivewireComponent
{
    public string $context = 'conversation';

    public ?string $pendingActionId = null;

    public ?string $activeStepId = null;

    /**
     * The composer's current model pick, mirrored from the client so a turn
     * resumed by an approval runs on the same model the user was talking to.
     * Client-writable, and deliberately so: AiModelResolver re-checks the plan
     * and availability, so the worst a forged value can do is fall back to auto.
     */
    public ?string $model = null;

    public int $cursor = 0;

    /**
     * Which field is open for inline editing, and on which step. Both are set
     * together by editField() and cleared together; neither is ever written from
     * the client, so they are locked. Unlocked, a payload could name a field
     * while nulling the step and open the same Filament schema on every step
     * that owns that field code, giving one statePath several bound inputs.
     */
    #[Locked]
    public ?string $editingFieldCode = null;

    #[Locked]
    public ?string $editingStepId = null;

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(string $context = 'conversation'): void
    {
        $this->context = $context;
    }

    public function form(Schema $schema): Schema
    {
        if ($this->editingFieldCode === null) {
            return $schema->components([])->statePath('data');
        }

        return $schema
            ->components($this->componentsForField($this->editingFieldCode))
            ->statePath('data')
            ->model($this->modelClass());
    }

    public function editField(string $code, ?string $stepId = null): void
    {
        $pendingAction = $this->loadStep($stepId ?? $this->activeStepId());

        if (! $pendingAction instanceof PendingAction || $pendingAction->operation !== PendingActionOperation::Create) {
            return;
        }

        $this->ensureTenantContext();

        $this->editingStepId = (string) $pendingAction->getKey();
        $this->editingFieldCode = $code;
        $this->form->fill($this->formStateFor($pendingAction, $code));
    }

    public function saveField(): void
    {
        $pendingAction = $this->loadStep($this->editingStepId ?? $this->activeStepId());

        if (! $pendingAction instanceof PendingAction || $this->editingFieldCode === null) {
            return;
        }

        $input = $this->flattenFormState($this->form->getState());
        $index = ProposalPayload::from($pendingAction)->isBatch ? $this->cursorFor($pendingAction) : null;

        // $cursor is a public property, so it is client-writable, and a partially
        // resolved batch is still Pending: without this an edit could rewrite the
        // action and display data of an item that was already created, leaving the
        // transcript's audit card showing values the record never had. createCurrent()
        // and discardCurrent() already snap away from a resolved index.
        if ($index !== null && ! in_array($index, $this->unresolvedIndices($pendingAction), true)) {
            return;
        }

        try {
            resolve(ProposalEditor::class)->applyEdit($pendingAction, $this->authUser(), $input, $index);
        } catch (RuntimeException) {
            $this->addError('field', __('This change could not be saved. Please review the value and try again.'));

            return;
        }

        $this->editingFieldCode = null;
        $this->editingStepId = null;
    }

    public function cancelField(): void
    {
        $this->editingFieldCode = null;
        $this->editingStepId = null;
    }

    /**
     * Flatten the scoped edit form state to `{code => value}` for ProposalEditor.
     * A custom field is nested under `custom_fields.<code>`; lift those to the
     * top level keyed by code. Core fields are already top-level — keep them.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function flattenFormState(array $state): array
    {
        $flattened = [];

        foreach ($state as $key => $value) {
            if ($key === 'custom_fields' && is_array($value)) {
                foreach ($value as $code => $customValue) {
                    $flattened[$code] = $customValue;
                }

                continue;
            }

            $flattened[$key] = $value;
        }

        return $flattened;
    }

    /**
     * @return array<int, Component>
     */
    private function componentsForField(string $code): array
    {
        $pendingAction = $this->loadStep($this->editingStepId ?? $this->activeStepId());

        if (! $pendingAction instanceof PendingAction) {
            return [];
        }

        $entityType = $pendingAction->entity_type;
        $titleKey = ProposalCoreFields::titleKey($entityType);

        if ($code === $titleKey) {
            return [
                TextInput::make($titleKey)
                    ->label($titleKey === 'title' ? __('Title') : __('Name'))
                    ->required(),
            ];
        }

        if ($entityType === 'company' && $code === 'account_owner_id') {
            return [
                Select::make('account_owner_id')
                    ->label(__('Account Owner'))
                    ->options(collect(TeamMembersContext::for($this->authUser()))
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),
            ];
        }

        return [
            CustomFields::form()
                ->forModel($this->modelClass())
                ->only([$code])
                ->build(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formStateFor(PendingAction $pendingAction, string $code): array
    {
        $record = $this->currentRecord($pendingAction);

        if (ProposalCoreFields::isCore($pendingAction->entity_type, $code)) {
            return [$code => $record[$code] ?? null];
        }

        $customFields = is_array($record['custom_fields'] ?? null) ? $record['custom_fields'] : [];

        return ['custom_fields' => [$code => $customFields[$code] ?? null]];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentRecord(PendingAction $pendingAction): array
    {
        return ProposalPayload::from($pendingAction)->recordAtOrEmpty($this->cursorFor($pendingAction));
    }

    /**
     * @return class-string<Model>
     */
    private function modelClass(): string
    {
        $pendingAction = $this->loadStep($this->editingStepId ?? $this->activeStepId());

        $entityType = $pendingAction instanceof PendingAction ? $pendingAction->entity_type : '';

        $modelClass = Relation::getMorphedModel($entityType);

        throw_unless(
            is_string($modelClass),
            RuntimeException::class,
            "Unresolvable entity type for proposal editing: {$entityType}",
        );

        return $modelClass;
    }

    #[On('proposal:set-active')]
    public function setActive(?string $id = null, string $context = 'conversation', ?string $model = null): void
    {
        if ($context !== $this->context) {
            return;
        }

        $this->model = $model;

        $this->editingFieldCode = null;
        $this->editingStepId = null;

        if ($id === null) {
            $this->pendingActionId = null;
            $this->activeStepId = null;

            return;
        }

        $pendingAction = $this->loadStep($id);

        if (! $pendingAction instanceof PendingAction) {
            $this->pendingActionId = null;
            $this->activeStepId = null;

            return;
        }

        $this->pendingActionId = $pendingAction->getKey();
        $this->focusFirstPendingStep($pendingAction);
    }

    /**
     * Move the per-record controls to another step of the plan. The cursor is
     * per-step, so it re-anchors to that step's first undecided record.
     */
    public function stepNext(string $stepId): void
    {
        $this->stepWithin($stepId, 1);
    }

    public function stepPrev(string $stepId): void
    {
        $this->stepWithin($stepId, -1);
    }

    /**
     * Move the cursor to the adjacent UNRESOLVED record in the given direction.
     * Decided items are dropped from the dock queue entirely (Attio behavior), so
     * they can never be navigated back to and re-decided — their outcome lives in
     * the transcript audit card above.
     */
    private function stepWithin(string $stepId, int $direction): void
    {
        $this->editingFieldCode = null;
        $this->editingStepId = null;

        $pendingAction = $this->loadStep($stepId);

        if (! $pendingAction instanceof PendingAction) {
            return;
        }

        // Paging a step the dock was not focused on focuses it first, so the
        // pager and the fields under it always describe the same record. Without
        // this the pager could only be offered for the active step, and every
        // record after the first of every other step was approved unseen.
        if ((string) $pendingAction->getKey() !== $this->activeStepId()) {
            $this->activeStepId = (string) $pendingAction->getKey();
            $this->cursor = $this->firstUnresolvedIndex($pendingAction);
        }

        $unresolved = $this->unresolvedIndices($pendingAction);

        if ($unresolved === []) {
            return;
        }

        $position = array_search($this->cursor, $unresolved, true);

        if ($position === false) {
            $this->cursor = $unresolved[0];

            return;
        }

        $target = $position + $direction;

        if ($target < 0 || $target >= count($unresolved)) {
            return;
        }

        $this->cursor = $unresolved[$target];
    }

    public function recordCount(?PendingAction $pendingAction = null): int
    {
        $pendingAction ??= $this->loadStep($this->activeStepId());

        if (! $pendingAction instanceof PendingAction) {
            return 1;
        }

        return ProposalPayload::from($pendingAction)->count();
    }

    /**
     * How many records still await a decision — the dock stepper's denominator.
     * Resolved items have left the queue, so this shrinks as the user decides.
     */
    public function remainingCount(?PendingAction $pendingAction = null): int
    {
        $pendingAction ??= $this->loadStep($this->activeStepId());

        if (! $pendingAction instanceof PendingAction) {
            return 0;
        }

        return max(1, count($this->unresolvedIndices($pendingAction)));
    }

    /**
     * 1-based position of the current record within the unresolved queue.
     */
    public function currentPosition(?PendingAction $pendingAction = null): int
    {
        $pendingAction ??= $this->loadStep($this->activeStepId());

        if (! $pendingAction instanceof PendingAction) {
            return 1;
        }

        $position = array_search($this->cursorFor($pendingAction), $this->unresolvedIndices($pendingAction), true);

        return $position === false ? 1 : $position + 1;
    }

    /**
     * The cursor belongs to the active step. Any other step of the plan renders at
     * its own first undecided record, so a card never shows a record the stepper
     * cannot reach.
     *
     * Only a multi-record step has a cursor at all. A single-record proposal always
     * renders its one record, whatever the client-writable $cursor holds: a plan
     * whose earlier batch step left the cursor past zero must not blank the next
     * step's fields.
     */
    private function cursorFor(PendingAction $pendingAction): int
    {
        if (! ProposalPayload::from($pendingAction)->isBatch) {
            return 0;
        }

        return (string) $pendingAction->getKey() === $this->activeStepId()
            ? $this->cursor
            : $this->firstUnresolvedIndex($pendingAction);
    }

    private function activeStepId(): ?string
    {
        return $this->activeStepId ?? $this->pendingActionId;
    }

    private function loadStep(?string $id): ?PendingAction
    {
        if ($id === null || $id === '') {
            return null;
        }

        $user = $this->authUser();

        return PendingAction::query()
            ->whereKey($id)
            ->where('team_id', $user->currentTeam->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', PendingActionStatus::Pending)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Per-request memo for the plan's steps. stepViews() walks every step and each
     * walk re-read the whole plan (once for unmetDependencies, once more per
     * dependency through stepPosition), so a three-step plan issued the plan query
     * roughly a dozen times per dock render. Livewire builds a fresh component per
     * request, so this never outlives one round trip, but it does have to be
     * dropped after a resolve inside the same request: render() runs after
     * approveAll()/discardAll() and would otherwise paint the pre-decision list.
     *
     * @var list<PendingAction>|null
     */
    private ?array $planAllStepsCache = null;

    /**
     * Per-request memo for editableCodes(), keyed by entity type.
     *
     * @var array<string, list<string>>
     */
    private array $editableCodesCache = [];

    /**
     * Every step of the plan, decided or not, in order. Approved steps have to
     * stay visible here: unmetDependencies() reads this list to tell an approved
     * dependency from a missing one.
     *
     * @return list<PendingAction>
     */
    public function planAllSteps(): array
    {
        if ($this->planAllStepsCache !== null) {
            return $this->planAllStepsCache;
        }

        $anchor = $this->loadStep($this->pendingActionId);

        if (! $anchor instanceof PendingAction) {
            return $this->planAllStepsCache = [];
        }

        return $this->planAllStepsCache = resolve(ProposalPlanService::class)->steps($anchor);
    }

    /**
     * Drop the memo after anything resolves a step, so a later read in the same
     * request sees the decision rather than the list as it was on mount.
     */
    private function forgetPlanSteps(): void
    {
        $this->planAllStepsCache = null;
    }

    /**
     * The plan's steps that still need a decision, in order. The dock only ever
     * presents undecided work: a step resolved a moment ago lives in the
     * transcript audit card above.
     *
     * @return list<PendingAction>
     */
    public function planSteps(): array
    {
        return array_values(array_filter(
            $this->planAllSteps(),
            static fn (PendingAction $step): bool => $step->status === PendingActionStatus::Pending && ! $step->isExpired(),
        ));
    }

    /**
     * Everything the card needs to render one step, so the view stays a template
     * rather than a second place where proposal semantics are decided.
     *
     * @return list<array<string, mixed>>
     */
    public function stepViews(): array
    {
        $plan = resolve(ProposalPlanService::class);
        $activeStepId = $this->activeStepId();
        $siblings = $this->planAllSteps();
        $views = [];

        foreach ($this->planSteps() as $position => $step) {
            $blockedBy = $plan->unmetDependencies($step, $siblings);

            $views[] = [
                'id' => (string) $step->getKey(),
                'position' => $position + 1,
                'operation' => $step->operation->value,
                'entity_type' => $step->entity_type,
                'summary' => $this->stepSummary($step),
                'fields' => $this->currentRecordFields($step),
                'editableCodes' => $this->editableCodes($step),
                'duplicateWarning' => $step->display_data['duplicate_warning'] ?? null,
                'isActive' => (string) $step->getKey() === $activeStepId,
                'isBatch' => ProposalPayload::from($step)->isBatch,
                'recordCount' => $this->recordCount($step),
                'remainingCount' => $this->remainingCount($step),
                'position_in_batch' => $this->currentPosition($step),
                'blockedBy' => $this->sortedPositions($blockedBy),
            ];
        }

        return $views;
    }

    /**
     * Step positions in plan order, so a hint reads "after steps 1, 2".
     *
     * @param  list<PendingAction>  $steps
     * @return list<int>
     */
    private function sortedPositions(array $steps): array
    {
        $positions = array_map($this->stepPosition(...), $steps);

        sort($positions);

        return $positions;
    }

    /**
     * 1-based position of a step within the plan's undecided steps.
     */
    private function stepPosition(PendingAction $step): int
    {
        foreach ($this->planSteps() as $index => $candidate) {
            if ((string) $candidate->getKey() === (string) $step->getKey()) {
                return $index + 1;
            }
        }

        return 1;
    }

    private function stepSummary(PendingAction $step): string
    {
        $display = $this->currentRecordDisplay($step);

        $summary = $display['summary']
            ?? $step->display_data['summary']
            ?? $step->display_data['title']
            ?? '';

        return is_string($summary) ? $summary : '';
    }

    private function firstUnresolvedIndex(PendingAction $pendingAction): int
    {
        return ProposalProgress::for($pendingAction)->firstUnresolvedIndex();
    }

    /**
     * Record indices not yet resolved — the only items the dock presents. A decided
     * item leaves this list, so the stepper can never land back on it.
     *
     * @return list<int>
     */
    private function unresolvedIndices(PendingAction $pendingAction): array
    {
        return ProposalProgress::for($pendingAction)->unresolvedIndices();
    }

    /**
     * Re-anchor the card on the plan's first step still awaiting a decision.
     */
    private function focusFirstPendingStep(PendingAction $anchor): void
    {
        $steps = resolve(ProposalPlanService::class)->pendingSteps($anchor);
        $first = $steps[0] ?? $anchor;

        $this->activeStepId = (string) $first->getKey();
        $this->cursor = $this->firstUnresolvedIndex($first);
    }

    #[On('proposal:create-current')]
    public function createCurrentFromShortcut(string $context = 'conversation'): void
    {
        if ($context !== $this->context) {
            return;
        }

        if (count($this->stepViews()) > 1) {
            $this->approveAll(resolve(ProposalPlanService::class));

            return;
        }

        $this->createCurrent(resolve(PendingActionService::class));
    }

    /**
     * Approve every remaining step of the plan, in order.
     *
     * Steps commit one at a time and execution stops at the first failure, so the
     * card reports what did happen rather than pretending the plan was atomic.
     */
    public function approveAll(ProposalPlanService $plan): void
    {
        $this->forgetPlanSteps();

        if ($this->editingFieldCode !== null) {
            return;
        }

        $anchor = $this->loadStep($this->pendingActionId);

        if (! $anchor instanceof PendingAction) {
            return;
        }

        $this->ensureTenantContext();

        $steps = $this->planSteps();

        try {
            $result = $plan->approveAll($anchor, $this->authUser());
        } catch (QueryException $exception) {
            $this->reportDatabaseFailure($anchor, $exception);

            return;
        } catch (RuntimeException|ValidationException $exception) {
            $this->reportResolveFailure($anchor, $exception->getMessage());

            return;
        }

        foreach (array_slice($steps, 0, $result['approved']) as $step) {
            $this->announceResolution($step, 'approved');
        }

        if ($result['failed'] !== null) {
            $this->reportResolveFailure($anchor, __('Step :step could not be completed: :message', [
                'step' => $result['failed']['step'],
                'message' => $result['failed']['message'],
            ]));

            $remaining = $this->loadStep($this->pendingActionId);

            if ($remaining instanceof PendingAction) {
                $this->focusFirstPendingStep($remaining);
            }

            return;
        }

        $this->settleAfterResolution($anchor);
    }

    /**
     * Approve one step of the plan, leaving the rest pending.
     */
    public function approveStep(string $stepId, ProposalPlanService $plan): void
    {
        $this->forgetPlanSteps();

        if ($this->editingFieldCode !== null) {
            return;
        }

        $step = $this->loadStep($stepId);

        if (! $step instanceof PendingAction) {
            return;
        }

        if ($plan->unmetDependencies($step, $plan->steps($step)) !== []) {
            $this->reportResolveFailure($step, __('Approve the earlier step this one links to first.'));

            return;
        }

        $this->ensureTenantContext();

        try {
            $plan->approveStep($step, $this->authUser());
        } catch (QueryException $exception) {
            $this->reportDatabaseFailure($step, $exception);

            return;
        } catch (RuntimeException|ValidationException $exception) {
            $this->reportResolveFailure($step, $exception->getMessage());

            return;
        }

        $this->announceResolution($step, 'approved');
        $this->settleAfterResolution($step);
    }

    /**
     * Reject one step and cancel whatever depended on it.
     */
    public function rejectStep(string $stepId, ProposalPlanService $plan): void
    {
        $this->forgetPlanSteps();

        if ($this->editingFieldCode !== null) {
            return;
        }

        $step = $this->loadStep($stepId);

        if (! $step instanceof PendingAction) {
            return;
        }

        try {
            $cancelled = $plan->reject($step, $this->authUser());
        } catch (QueryException $exception) {
            $this->reportDatabaseFailure($step, $exception);

            return;
        } catch (RuntimeException $exception) {
            $this->reportResolveFailure($step, $exception->getMessage());

            return;
        }

        $this->announceResolution($step, 'rejected');

        foreach ($cancelled as $cancelledStep) {
            $this->announceResolution($cancelledStep, 'rejected');
        }

        $this->settleAfterResolution($step);
    }

    /**
     * Discard the whole plan: every remaining step, none of them executed.
     */
    public function discardAll(ProposalPlanService $plan): void
    {
        $this->forgetPlanSteps();

        if ($this->editingFieldCode !== null) {
            return;
        }

        $steps = $this->planSteps();

        if ($steps === []) {
            return;
        }

        foreach ($steps as $step) {
            $fresh = $this->loadStep((string) $step->getKey());

            if (! $fresh instanceof PendingAction) {
                continue;
            }

            try {
                $plan->reject($fresh, $this->authUser());
            } catch (QueryException $exception) {
                $this->reportDatabaseFailure($fresh, $exception);

                return;
            } catch (RuntimeException $exception) {
                $this->reportResolveFailure($fresh, $exception->getMessage());

                return;
            }

            $this->announceResolution($fresh, 'rejected');
        }

        $this->settleAfterResolution($steps[0]);
    }

    public function createCurrent(PendingActionService $service): void
    {
        if ($this->editingFieldCode !== null) {
            return;
        }

        $pendingAction = $this->loadStep($this->activeStepId());

        if (! $pendingAction instanceof PendingAction) {
            return;
        }

        $isBatch = ProposalPayload::from($pendingAction)->isBatch;

        // A decided item is no longer in the dock queue — snap to the next undecided
        // one rather than re-running an already-resolved index.
        if ($isBatch && ! in_array($this->cursor, $this->unresolvedIndices($pendingAction), true)) {
            $this->cursor = $this->firstUnresolvedIndex($pendingAction);

            return;
        }

        $this->ensureTenantContext();

        try {
            if ($isBatch) {
                $result = $service->approveItem($pendingAction, $this->authUser(), $this->cursor);
                $finalized = $result['finalized'];
                // A deleted record has no page to link to, so only Create items carry a ref.
                $record = ($pendingAction->operation === PendingActionOperation::Create && $result['record'] instanceof Model)
                    ? resolve(RecordReferenceResolver::class)->resolve($pendingAction->entity_type, (string) $result['record']->getKey())
                    : null;
            } else {
                $resolved = $service->approve($pendingAction, $this->authUser());
                $finalized = true;
                $record = $this->recordReferenceFor($resolved);
            }
        } catch (QueryException $exception) {
            // Must precede the RuntimeException arm — QueryException extends
            // PDOException extends RuntimeException, so it would otherwise be caught
            // there and its getMessage() put the failing SQL, the row's values and
            // the connection host straight onto the card and into the transcript.
            $this->reportDatabaseFailure($pendingAction, $exception);

            return;
        } catch (RuntimeException|ValidationException $exception) {
            // ValidationException is thrown by the action's tenant guards when a
            // referenced record or assignee stopped being reachable between the
            // proposal and the approval. HttpException (from abort_*() inside an
            // action, e.g. the owner-only guard) is a RuntimeException too, and its
            // message is written for the user, so it renders as-is. Livewire would
            // otherwise absorb these into an error bag nothing renders, leaving the
            // button a permanent no-op.
            $this->reportResolveFailure($pendingAction, $exception->getMessage());

            return;
        }

        $this->dispatch(
            'proposal:resolved',
            pendingActionId: $pendingAction->getKey(),
            index: $isBatch ? $this->cursor : null,
            decision: 'approved',
            finalized: $finalized,
            record: $record,
            context: $this->context,
        );

        if (! $finalized) {
            $this->cursor = $this->firstUnresolvedIndex($pendingAction->fresh() ?? $pendingAction);

            return;
        }

        $this->settleAfterResolution($pendingAction);
    }

    public function discardCurrent(PendingActionService $service): void
    {
        if ($this->editingFieldCode !== null) {
            return;
        }

        $pendingAction = $this->loadStep($this->activeStepId());

        if (! $pendingAction instanceof PendingAction) {
            return;
        }

        $isBatch = ProposalPayload::from($pendingAction)->isBatch;

        // A decided item is no longer in the dock queue — snap to the next undecided
        // one rather than re-running an already-resolved index.
        if ($isBatch && ! in_array($this->cursor, $this->unresolvedIndices($pendingAction), true)) {
            $this->cursor = $this->firstUnresolvedIndex($pendingAction);

            return;
        }

        try {
            if ($isBatch) {
                $result = $service->rejectItem($pendingAction, $this->authUser(), $this->cursor);
                $finalized = $result['finalized'];
            } else {
                resolve(ProposalPlanService::class)->reject($pendingAction, $this->authUser());
                $finalized = true;
            }
        } catch (QueryException $exception) {
            $this->reportDatabaseFailure($pendingAction, $exception);

            return;
        } catch (RuntimeException $exception) {
            $this->reportResolveFailure($pendingAction, $exception->getMessage());

            return;
        }

        $this->dispatch(
            'proposal:resolved',
            pendingActionId: $pendingAction->getKey(),
            index: $isBatch ? $this->cursor : null,
            decision: 'rejected',
            finalized: $finalized,
            record: null,
            context: $this->context,
        );

        if (! $finalized) {
            $this->cursor = $this->firstUnresolvedIndex($pendingAction->fresh() ?? $pendingAction);

            return;
        }

        // Rejecting a step cancels its dependents, so the transcript is told about
        // each of them too — a card that silently vanished would read as a bug.
        foreach ($this->cancelledDependentsOf($pendingAction) as $cancelled) {
            $this->announceResolution($cancelled, 'rejected');
        }

        $this->settleAfterResolution($pendingAction);
    }

    /**
     * Steps this rejection cancelled: pending a moment ago, rejected now, and
     * marked with the step that caused it.
     *
     * @return list<PendingAction>
     */
    private function cancelledDependentsOf(PendingAction $step): array
    {
        $candidates = PendingAction::query()
            ->where('team_id', $step->team_id)
            ->where('conversation_id', $step->conversation_id)
            ->where('turn_id', $step->turn_id)
            ->whereKeyNot($step->getKey())
            ->where('status', PendingActionStatus::Rejected)
            ->get();

        $cancelled = [];

        foreach ($candidates as $candidate) {
            if (($candidate->result_data['cancelled_by'] ?? null) === (string) $step->getKey()) {
                $cancelled[] = $candidate;
            }
        }

        return $cancelled;
    }

    private function announceResolution(PendingAction $step, string $decision): void
    {
        $fresh = $step->refresh();
        $record = $decision === 'approved' ? $this->recordReferenceFor($fresh) : null;

        // A step cancelled because the step it depended on was rejected has to say
        // so live, not only after a reload. ListConversationMessages sets this on
        // the way back in; without it here the transcript shows a bare "Rejected"
        // in the very session where the cascade happened, which is the outcome
        // PendingActionService::cancelStep() records it to prevent.
        $cancelledBy = is_array($fresh->result_data)
            ? ($fresh->result_data['cancelled_by'] ?? null)
            : null;

        $this->dispatch(
            'proposal:resolved',
            pendingActionId: $step->getKey(),
            index: null,
            decision: $decision,
            finalized: true,
            record: $record,
            cancelledBy: is_string($cancelledBy) ? $cancelledBy : null,
            context: $this->context,
        );
    }

    /**
     * After a step resolves, the dock either moves to the plan's next undecided
     * step or closes when there is none left — and when there is none left, the
     * decision itself becomes the next turn.
     */
    private function settleAfterResolution(PendingAction $resolved): void
    {
        $anchor = $this->loadStep($this->pendingActionId);

        if ($anchor instanceof PendingAction) {
            $this->focusFirstPendingStep($anchor);

            return;
        }

        $next = $this->nextPendingStepOfPlan();

        if ($next instanceof PendingAction) {
            $this->pendingActionId = (string) $next->getKey();
            $this->focusFirstPendingStep($next);

            return;
        }

        $this->pendingActionId = null;
        $this->activeStepId = null;

        $this->resumeAssistant($resolved);
    }

    /**
     * Hand the decision back to the assistant so the user never has to type
     * "next" to hear what happened or to get the rest of a chained request.
     * The service owns every gate (nothing else pending, once per turn, one
     * credit); this only supplies the turn that was decided.
     */
    private function resumeAssistant(PendingAction $resolved): void
    {
        $conversationId = $resolved->conversation_id;
        $turnId = $resolved->turn_id;

        if ($conversationId === null || $turnId === null) {
            return;
        }

        $queued = resolve(TurnContinuationService::class)->resume(
            $this->authUser(),
            $conversationId,
            $turnId,
            $this->model,
        );

        if ($queued) {
            $this->dispatch('chat:resuming', context: $this->context);
        }
    }

    /**
     * The anchor itself can be the step that just resolved, so the plan is
     * re-entered through any sibling that is still pending.
     */
    private function nextPendingStepOfPlan(): ?PendingAction
    {
        $user = $this->authUser();

        $resolved = PendingAction::query()
            ->whereKey($this->pendingActionId ?? '')
            ->where('team_id', $user->currentTeam->getKey())
            ->first();

        if (! $resolved instanceof PendingAction || $resolved->turn_id === null) {
            return null;
        }

        return PendingAction::query()
            ->where('team_id', $user->currentTeam->getKey())
            ->where('user_id', $user->getKey())
            ->where('conversation_id', $resolved->conversation_id)
            ->where('turn_id', $resolved->turn_id)
            ->where('status', PendingActionStatus::Pending)
            ->where('expires_at', '>', now())
            ->orderBy('id')
            ->first();
    }

    /**
     * Report a failed resolve on the card itself AND to the transcript. The
     * dispatched event alone is not user-visible, so the message is also bound to
     * the `resolve` error bag which the dock renders — a proposal that cannot be
     * resolved must say so rather than leave the button dead.
     */
    /**
     * A database error is never shown verbatim: the driver's message carries the
     * statement, its bindings and the connection details. The operator still needs
     * the real thing, so it goes to the log instead.
     *
     * A unique violation here means something got past the actions' re-validation —
     * in practice a second approval landing in the same instant — so it is reported
     * as a race the user can retry, not as a generic failure.
     */
    private function reportDatabaseFailure(PendingAction $pendingAction, QueryException $exception): void
    {
        report($exception);

        $this->reportResolveFailure($pendingAction, $exception->getCode() === '23505'
            ? __('Someone else just made a conflicting change. Reload the page and try again.')
            : __('This change could not be saved. Please try again.'));
    }

    private function reportResolveFailure(PendingAction $pendingAction, string $message): void
    {
        $this->addError('resolve', $message);

        $this->dispatch(
            'proposal:resolve-failed',
            pendingActionId: $pendingAction->getKey(),
            message: $message,
            context: $this->context,
        );
    }

    private function ensureTenantContext(): void
    {
        if (Filament::getTenant() !== null) {
            return;
        }

        $team = $this->authUser()->currentTeam;

        if ($team === null) {
            return;
        }

        Filament::setTenant($team, isQuiet: true);
    }

    /**
     * @return array{id: string, type: string, url: string, label: string|null}|null
     */
    private function recordReferenceFor(PendingAction $pendingAction): ?array
    {
        $resultData = $pendingAction->result_data;
        $recordId = is_array($resultData) ? ($resultData['id'] ?? null) : null;

        if (! is_string($recordId) && ! is_int($recordId)) {
            return null;
        }

        return resolve(RecordReferenceResolver::class)->resolve($pendingAction->entity_type, (string) $recordId);
    }

    /**
     * @return array<string, mixed>
     */
    private function currentRecordDisplay(PendingAction $pendingAction): array
    {
        return ProposalPayload::from($pendingAction)->displayAt($this->cursorFor($pendingAction));
    }

    /**
     * The current record's display rows, rebuilt through ProposalDisplayBuilder
     * from the record's clean action_data so each owned/editable row carries a
     * `code`. Carried-forward relationship rows stay code-less (read-only). The
     * rebuild is byte-for-byte the stored display because applyEdit re-renders
     * with the same builder — see ProposalCardComponentTest's no-divergence test.
     *
     * @return list<array<string, mixed>>
     */
    public function currentRecordFields(?PendingAction $pendingAction = null): array
    {
        $pendingAction ??= $this->loadStep($this->activeStepId());

        if (! $pendingAction instanceof PendingAction) {
            return [];
        }

        $this->ensureTenantContext();

        $existingFields = $this->currentDisplayFields($pendingAction);

        // Only Create proposals are inline-editable, so only they need the rebuild that
        // re-derives each owned row from action_data to attach an editable `code`. For
        // update/delete, action_data holds diffs/record ids — not display values — so the
        // stored display rows are authoritative; rebuilding would blank them out.
        if ($pendingAction->operation !== PendingActionOperation::Create) {
            return $existingFields;
        }

        $record = $this->currentRecord($pendingAction);

        return resolve(ProposalDisplayBuilder::class)
            ->build($this->authUser(), $pendingAction->entity_type, $record, $existingFields)['fields'];
    }

    /**
     * The set of field codes the dock allows inline editing for the current
     * entity: core keys plus the active, non-deferred custom field codes. Derived
     * from ProposalFieldSchemaDescriber so the deferred-field exclusion (FILE_UPLOAD,
     * RECORD/lookup, unsupported kinds) stays single-sourced with the editor.
     *
     * @return list<string>
     */
    public function editableCodes(?PendingAction $pendingAction = null): array
    {
        $pendingAction ??= $this->loadStep($this->activeStepId());

        if (! $pendingAction instanceof PendingAction) {
            return [];
        }

        // Only Create proposals are editable — never offer edit pencils on delete/update.
        if ($pendingAction->operation !== PendingActionOperation::Create) {
            return [];
        }

        $entityType = $pendingAction->entity_type;

        // Which codes are editable is a property of the entity, not of the record:
        // the describer lists the entity's core keys plus its active, non-deferred
        // custom fields, and defers by field type rather than by value. stepViews()
        // asks once per step, so without this memo a plan whose steps share an
        // entity type (the usual shape: one company, then its four contacts) re-ran
        // the same CustomField query for every step on every dock round trip.
        if (array_key_exists($entityType, $this->editableCodesCache)) {
            return $this->editableCodesCache[$entityType];
        }

        $this->ensureTenantContext();

        $schema = resolve(ProposalFieldSchemaDescriber::class)
            ->describe($this->authUser(), $entityType, $this->currentRecord($pendingAction));

        return $this->editableCodesCache[$entityType] = array_map(
            static fn (array $field): string => (string) $field['code'],
            $schema,
        );
    }

    /**
     * The stored display fields for the current record, used to carry forward
     * read-only relationship rows when rebuilding via ProposalDisplayBuilder.
     *
     * @return list<array<string, mixed>>
     */
    private function currentDisplayFields(PendingAction $pendingAction): array
    {
        $display = $this->currentRecordDisplay($pendingAction);

        return is_array($display['fields'] ?? null) ? array_values($display['fields']) : [];
    }

    public function render(): View
    {
        // An action ran earlier in this same request may have resolved a step, so
        // the memo is dropped here rather than trusted: stepViews() repopulates it
        // once and the rest of the render reads that.
        $this->forgetPlanSteps();

        $proposal = $this->loadStep($this->activeStepId());
        $steps = $this->stepViews();

        // recordFields and editableCodes are dropped: no partial reads them, and each
        // ran its own CustomField query (editableCodes two more for team members) on
        // every dock round trip, only to be discarded. stepViews() already carries
        // both per step. The counters below stay: they are cheap array reads and the
        // batch stepper's behaviour is asserted through them.
        return view('chat::livewire.chat.proposal-card', [
            'proposal' => $proposal,
            'steps' => $steps,
            'isPlan' => count($steps) > 1,
            'recordCount' => $proposal instanceof PendingAction ? $this->recordCount($proposal) : 0,
            'remainingCount' => $proposal instanceof PendingAction ? $this->remainingCount($proposal) : 0,
            'position' => $proposal instanceof PendingAction ? $this->currentPosition($proposal) : 1,
        ]);
    }
}
