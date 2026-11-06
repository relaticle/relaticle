<?php

declare(strict_types=1);

namespace Relaticle\Chat\Livewire\Chat;

use App\Livewire\BaseLivewireComponent;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Relaticle\Chat\Actions\SetWebSearchPreference;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Enums\ProposalEntity;
use Relaticle\Chat\Enums\WebSearchPreference;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Services\ProposalEditor;
use Relaticle\Chat\Services\ProposalPlanService;
use Relaticle\Chat\Services\Tools\ProposalDisplayBuilder;
use Relaticle\Chat\Services\Tools\ProposalFieldSchemaDescriber;
use Relaticle\Chat\Services\TurnContinuationService;
use Relaticle\Chat\Support\ApprovalFailureMessage;
use Relaticle\Chat\Support\PlanReference;
use Relaticle\Chat\Support\ProposalPayload;
use Relaticle\Chat\Support\ProposalProgress;
use Relaticle\Chat\Support\RecordNameResolver;
use Relaticle\Chat\Support\RecordReferenceResolver;
use Relaticle\Chat\Support\WorkspaceMembersContext;
use Relaticle\CustomFields\Facades\CustomFields;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * The docked proposal card: the one place a chat-proposed write is decided.
 *
 * It presents a PLAN, every proposal the assistant's turn produced, in order,
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
     * Field codes the user unchecked on the active record: excluded from the
     * write at approval time, never persisted into the frozen action_data.
     * Client-writable by nature (checkbox state); the real guard lives in
     * PendingActionService::sanitizedExclusions(), which drops payload markers
     * and the entity's title key whatever the client sends. Reset on every
     * navigation (setActive, focusItem, prev/next, each decided item) so one
     * record's exclusions can never leak onto the next.
     *
     * @var list<string>
     */
    public array $excludedFields = [];

    /**
     * Steps and batch rows the user left out. Nothing is rejected until Approve:
     * approveAll() hands both lists to the plan service, which rejects them first.
     *
     * Typed mixed on purpose: a forged non-array value must reach the sanitizers, not a TypeError.
     */
    public mixed $leftOutSteps = [];

    public mixed $leftOutRows = [];

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

    /**
     * $initialPendingActionId lets the dock render its card in the same request
     * that renders the page, instead of empty-until-set-active: the client's
     * init dispatch of proposal:set-active still runs and re-anchors moments
     * later, so a stale or wrong id self-corrects. loadStep() scopes it to the
     * authed user, so the value can only ever name a proposal of their own.
     */
    public function mount(string $context = 'conversation', ?string $initialPendingActionId = null): void
    {
        $this->context = $context;

        if ($initialPendingActionId === null) {
            return;
        }

        $pendingAction = $this->loadStep($initialPendingActionId);

        if (! $pendingAction instanceof PendingAction) {
            return;
        }

        $this->pendingActionId = (string) $pendingAction->getKey();
        $this->focusFirstPendingStep($pendingAction);
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

    public function editField(string $code, ?string $stepId = null, ?int $index = null): void
    {
        if ($index !== null && $stepId !== null) {
            $this->focusItem($stepId, $index);
        }

        $pendingAction = $this->loadStep($stepId ?? $this->activeStepId());

        if (! $pendingAction instanceof PendingAction || $pendingAction->operation !== PendingActionOperation::Create) {
            return;
        }

        $this->ensureTenantContext();

        if (! in_array($code, $this->editableCodesOf($pendingAction), true)) {
            return;
        }

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
     * top level keyed by code. Core fields are already top-level, so keep them.
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

        $entity = $pendingAction->entity_type;
        $titleKey = $entity->titleKey();

        if ($code === $titleKey) {
            return [
                TextInput::make($titleKey)
                    ->label($titleKey === 'title' ? __('Title') : __('Name'))
                    ->hiddenLabel()
                    ->required(),
            ];
        }

        if ($entity === ProposalEntity::Company && $code === 'account_owner_id') {
            return [
                Select::make('account_owner_id')
                    ->label(__('Account Owner'))
                    ->hiddenLabel()
                    ->options(collect(WorkspaceMembersContext::for($this->authUser()))
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),
            ];
        }

        return CustomFields::form()
            ->forModel($this->modelClass())
            ->withoutSections()
            ->only([$code])
            ->values()
            // The package spans each field across its own 12-column grid; this schema has one column.
            ->map(fn (Field $field): Field => $field->hiddenLabel()->columnSpan(['default' => 'full', 'lg' => 'full']))
            // Filament's date panel cannot leave the card's scroller; the browser's picker can.
            ->map(fn (Field $field): Field => $field instanceof DateTimePicker ? $field->native() : $field)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formStateFor(PendingAction $pendingAction, string $code): array
    {
        $record = $this->currentRecord($pendingAction);

        if ($pendingAction->entity_type->isCore($code)) {
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

        $entityType = $pendingAction instanceof PendingAction ? $pendingAction->entity_type->value : '';

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
        $this->excludedFields = [];
        $this->leftOutSteps = [];
        $this->leftOutRows = [];

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
     * Point the editor at one record of a batch step. Decided items are dropped
     * from the dock queue entirely, so a just-resolved record snaps to the first
     * undecided one instead of re-opening an outcome that lives in the transcript.
     */
    public function focusItem(string $stepId, int $index): void
    {
        $this->editingFieldCode = null;
        $this->editingStepId = null;
        $this->excludedFields = [];

        $pendingAction = $this->loadStep($stepId);

        if (! $pendingAction instanceof PendingAction) {
            return;
        }

        // Focusing a row of a step the dock was not anchored on focuses that
        // step too, so the expanded fields always describe the clicked record.
        $this->activeStepId = (string) $pendingAction->getKey();

        $this->cursor = in_array($index, $this->unresolvedIndices($pendingAction), true)
            ? $index
            : $this->firstUnresolvedIndex($pendingAction);
    }

    /**
     * The five *Of() helpers below take the step to read; their public callers take
     * nothing and resolve it through loadStep().
     *
     * Livewire runs every client-invoked method through implicit route-model
     * binding, so a public method typed `PendingAction` accepts a bare
     * `where(id)->first()` on whatever id the browser sends, with no workspace, no
     * user, no status and no expiry. PendingAction rows carry another tenant's
     * proposed record fields and old→new diffs, so the parameter has to stay off
     * the public surface: internal callers pass a step that loadStep() or
     * planAllSteps() already scoped, and the client can reach only the active step.
     */
    public function recordCount(): int
    {
        return $this->recordCountOf($this->loadStep($this->activeStepId()));
    }

    private function recordCountOf(?PendingAction $pendingAction): int
    {
        if (! $pendingAction instanceof PendingAction) {
            return 1;
        }

        return ProposalPayload::from($pendingAction)->count();
    }

    /**
     * How many records still await a decision: the footer button's live count.
     * Resolved items have left the queue, so this shrinks as the user decides.
     */
    public function remainingCount(): int
    {
        return $this->remainingCountOf($this->loadStep($this->activeStepId()));
    }

    private function remainingCountOf(?PendingAction $pendingAction): int
    {
        if (! $pendingAction instanceof PendingAction) {
            return 0;
        }

        return max(1, count($this->unresolvedIndices($pendingAction)));
    }

    /**
     * The cursor belongs to the active step. Any other step of the plan renders at
     * its own first undecided record, so a card never expands a record the user
     * cannot decide.
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
        return $this->dockedOwnApprovalStep()?->getKey() ?? $this->activeStepId ?? $this->pendingActionId;
    }

    /**
     * When nothing but own-approval steps is left, the dock shows the first one as its
     * own card and the rest wait their turn.
     */
    private function dockedOwnApprovalStep(): ?PendingAction
    {
        $steps = $this->planSteps();

        if (count($steps) < 2) {
            return null;
        }

        foreach ($steps as $step) {
            if (! $step->needsOwnApproval()) {
                return null;
            }
        }

        return $steps[0];
    }

    private function loadStep(?string $id): ?PendingAction
    {
        if ($id === null || $id === '') {
            return null;
        }

        $user = $this->authUser();

        return PendingAction::query()
            ->whereKey($id)
            ->where('workspace_id', $user->currentWorkspace->getKey())
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
    private function planAllSteps(): array
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
     * Drop the memo so a read after a resolve sees the decision rather than the
     * list as it was on mount. render() is the only caller it needs: every read
     * that happens after a mutation (focusFirstPendingStep, loadStep, refresh)
     * goes to the database directly, so nothing else can observe a stale memo.
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
        return resolve(ProposalPlanService::class)->pendingAmong($this->planAllSteps());
    }

    /**
     * Everything the card needs to render one step, so the view stays a template
     * rather than a second place where proposal semantics are decided.
     *
     * @return list<array<string, mixed>>
     */
    public function stepViews(): array
    {
        $docked = $this->dockedOwnApprovalStep();
        $steps = $docked instanceof PendingAction ? [$docked] : $this->planSteps();
        $pendingIds = array_map(static fn (PendingAction $pending): string => (string) $pending->getKey(), $this->planSteps());

        return array_map(
            fn (PendingAction $step, int $position): array => $this->stepView($step, $position + 1, $pendingIds),
            $steps,
            array_keys($steps),
        );
    }

    /**
     * @param  list<string>  $pendingIds
     * @return array<string, mixed>
     */
    private function stepView(PendingAction $step, int $position, array $pendingIds): array
    {
        $entity = $step->entity_type;
        $blockedBy = resolve(ProposalPlanService::class)->unmetDependencies($step, $this->planAllSteps());

        return [
            'id' => (string) $step->getKey(),
            'position' => $position,
            'operation' => $step->operation->value,
            'entity_type' => $entity->value,
            'summary' => $this->stepSummary($step),
            'title' => is_string($step->display_data['title'] ?? null) ? $step->display_data['title'] : '',
            'recordLabel' => $this->stepRecordLabel($step),
            'fields' => $this->visibleRows($step, $this->recordFieldsOf($step)),
            'editableCodes' => $this->editableCodesOf($step),
            'isActive' => (string) $step->getKey() === $this->activeStepId(),
            'needsOwnApproval' => $step->needsOwnApproval(),
            'isBatch' => ProposalPayload::from($step)->isBatch,
            'recordCount' => $this->recordCountOf($step),
            'remainingCount' => $this->remainingCountOf($step),
            'items' => $this->batchItemViews($step),
            'blockedBy' => $this->sortedPositions($blockedBy),
            'excludableCodes' => $this->excludableCodesOf($step),
            'titleKey' => $entity->titleKey(),
            'titleLabel' => $entity->titleLabel(),
            'entityNoun' => Str::lower($entity->singularName()),
            'deletes' => $entity->deletes($step->operation),
            'confirmLabel' => $entity->confirmLabel($step->operation, $this->remainingCountOf($step)),
            'declineLabel' => $entity->declineLabel(),
            'followUpLabel' => $entity->followUpLabel(),
            'refLinks' => $this->refLinksOf($step, $pendingIds),
        ];
    }

    /**
     * The earlier steps of the plan this step links to, as the exact text its display rows
     * carry for them. A link to a step still pending is drawn as a "new" chip.
     *
     * @param  list<string>  $pendingIds
     * @return list<array{display: string, name: string, isNew: bool}>
     */
    private function refLinksOf(PendingAction $step, array $pendingIds): array
    {
        $plan = $this->planAllSteps();
        $links = [];

        foreach (PlanReference::targetsIn($step->action_data) as $target) {
            foreach ($plan as $position => $candidate) {
                if ((string) $candidate->getKey() !== PlanReference::actionId($target)) {
                    continue;
                }

                $record = ProposalPayload::from($candidate)->recordAtOrEmpty(PlanReference::index($target) ?? 0);
                $name = $record[$candidate->entity_type->titleKey()] ?? null;

                if (is_string($name) && $name !== '') {
                    $links[] = [
                        'display' => RecordNameResolver::planLabel($name, $position + 1),
                        'name' => $name,
                        'isNew' => in_array((string) $candidate->getKey(), $pendingIds, true),
                    ];
                }
            }
        }

        return $links;
    }

    /**
     * A create lists only what it sets: a value left empty is not a change, so its row stays out.
     * An update keeps "(none)", because there it means a value is being cleared.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function visibleRows(PendingAction $step, array $rows): array
    {
        if ($step->operation !== PendingActionOperation::Create) {
            return $rows;
        }

        return array_values(array_filter($rows, static function (array $row): bool {
            $value = $row['new'] ?? $row['value'] ?? null;

            if (is_array($row['values'] ?? null) && $row['values'] !== []) {
                return true;
            }

            $text = is_scalar($value) ? trim((string) $value) : '';

            return $text !== '' && $text !== '(none)' && $text !== __('(none)');
        }));
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
        // A batch step lists every record as its own row, so its header carries
        // the batch-level summary ("Create 3 people"), not one record's.
        $display = ProposalPayload::from($step)->isBatch
            ? $step->display_data
            : $this->currentRecordDisplay($step);

        $summary = $display['summary']
            ?? $step->display_data['summary']
            ?? $step->display_data['title']
            ?? '';

        return is_string($summary) ? $summary : '';
    }

    /**
     * The record identity shown in the same compact pill used by read results.
     * Create payloads hold the exact title. Other operations keep the current
     * title in their display summary, outside the fields that are changing.
     */
    private function stepRecordLabel(PendingAction $step): string
    {
        // A batch names every record on its own row below, so its header stays
        // the batch summary ("Create 3 people"). A chip here would show only the
        // first record and read as the whole card.
        if (ProposalPayload::from($step)->isBatch) {
            return '';
        }

        if ($step->operation === PendingActionOperation::Create) {
            $record = $this->currentRecord($step);
            $title = $record[$step->entity_type->titleKey()] ?? null;

            if (is_string($title) && $title !== '') {
                return $title;
            }
        }

        $summary = $this->stepSummary($step);

        if (preg_match('/"(.*)"/u', $summary, $matches) === 1 && $matches[1] !== '') {
            return $matches[1];
        }

        return '';
    }

    /**
     * One row per still-undecided record of a batch step. Every record is its
     * own visible row, so nothing can be approved or left out unseen.
     *
     * @return list<array{index: int, summary: string, label: string, isActive: bool, fields: list<array<string, mixed>>}>
     */
    private function batchItemViews(PendingAction $step): array
    {
        $payload = ProposalPayload::from($step);

        if (! $payload->isBatch) {
            return [];
        }

        $cursor = $this->cursorFor($step);

        return array_map(function (int $index) use ($payload, $cursor, $step): array {
            $display = $payload->displayAt($index);
            $summary = $display['summary'] ?? $display['title'] ?? __('Record :position', ['position' => $index + 1]);
            $fields = $display['fields'] ?? [];

            return [
                'index' => $index,
                'summary' => is_string($summary) ? $summary : '',
                'label' => $this->itemLabel($step->entity_type, $display),
                'isActive' => $index === $cursor,
                'fields' => $this->visibleRows($step, is_array($fields) ? array_values($fields) : []),
            ];
        }, $this->unresolvedIndices($step));
    }

    /**
     * The identity a batch row leads with: the entity's title value when the row carries one
     * (an invitation's address), otherwise the name quoted in its summary.
     *
     * @param  array<string, mixed>  $display
     */
    private function itemLabel(ProposalEntity $entity, array $display): string
    {
        $fields = is_array($display['fields'] ?? null) ? $display['fields'] : [];

        foreach ($fields as $row) {
            if (! is_array($row)) {
                continue;
            }

            $isTitleRow = ($row['code'] ?? null) === null
                ? ($row['label'] ?? null) === $entity->titleLabel()
                : $row['code'] === $entity->titleKey();
            $value = $row['value'] ?? null;

            if ($isTitleRow && is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $this->labelFromDisplay($display);
    }

    /**
     * @param  array<string, mixed>  $display
     */
    private function labelFromDisplay(array $display): string
    {
        $summary = $display['summary'] ?? null;

        // The quoted record name from the summary ('Create person "Alice"')
        // comes first: the real tools set each item's `title` to the generic
        // entity title ("Create Person"), which is the eyebrow, not an identity.
        if (is_string($summary) && preg_match('/"(.*)"/u', $summary, $matches) === 1 && $matches[1] !== '') {
            return $matches[1];
        }

        $title = $display['title'] ?? null;

        if (is_string($title) && $title !== '') {
            return $title;
        }

        return is_string($summary) ? $summary : '';
    }

    private function firstUnresolvedIndex(PendingAction $pendingAction): int
    {
        return ProposalProgress::for($pendingAction)->firstUnresolvedIndex();
    }

    /**
     * Record indices not yet resolved, the only items the dock presents. A decided
     * item leaves this list, so focus can never land back on it.
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
        $this->excludedFields = [];
    }

    /**
     * Toggle one attribute checkbox of the active record. Unchecked codes are
     * dropped from the write at approval; the service re-guards the list, so a
     * forged code can at worst be ignored.
     */
    public function toggleField(string $code): void
    {
        $step = $this->loadStep($this->activeStepId());

        if (! $step instanceof PendingAction) {
            return;
        }

        if (! in_array($code, $this->excludableCodesOf($step), true)) {
            return;
        }

        $this->excludedFields = in_array($code, $this->excludedFields, true)
            ? array_values(array_diff($this->excludedFields, [$code]))
            : [...$this->excludedFields, $code];
    }

    /**
     * Field codes the user may uncheck: coded rows minus the entity's title key
     * (a create without it fails validation), and for an update only the rows
     * that actually change something. A delete writes no fields at all, and a
     * batch leaves out whole rows instead.
     *
     * @return list<string>
     */
    private function excludableCodesOf(?PendingAction $pendingAction): array
    {
        if (! $pendingAction instanceof PendingAction) {
            return [];
        }

        if ($pendingAction->operation === PendingActionOperation::Delete || $pendingAction->entity_type->isIndivisible() || ProposalPayload::from($pendingAction)->isBatch) {
            return [];
        }

        $titleKey = $pendingAction->entity_type->titleKey();
        $codes = [];

        foreach ($this->recordFieldsOf($pendingAction) as $row) {
            $code = $row['code'] ?? null;

            if (! is_string($code) || $code === '' || $code === $titleKey) {
                continue;
            }

            if ($pendingAction->operation === PendingActionOperation::Update && ! array_key_exists('new', $row)) {
                continue;
            }

            $codes[] = $code;
        }

        return array_values(array_unique($codes));
    }

    #[On('proposal:create-current')]
    public function createCurrentFromShortcut(string $context = 'conversation'): void
    {
        if ($context !== $this->context) {
            return;
        }

        $step = $this->loadStep($this->activeStepId());

        if (count($this->planSteps()) > 1 || ($step instanceof PendingAction && ProposalPayload::from($step)->isBatch && ! $step->needsOwnApproval())) {
            if ($this->approvableRecordCount() > 0 && ! $this->primaryIsRisky()) {
                $this->approveAll(resolve(ProposalPlanService::class));
            }

            return;
        }

        if ($step?->entity_type->approvesFromKeyboard() !== true) {
            return;
        }

        if ($step->needsOwnApproval()) {
            $this->approveStep((string) $step->getKey(), resolve(ProposalPlanService::class));

            return;
        }

        $this->createCurrent(resolve(PendingActionService::class));
    }

    /**
     * Approve every remaining step of the plan, in order, after rejecting what the
     * user left out.
     *
     * Steps commit one at a time and execution stops at the first failure, so the
     * card reports what did happen rather than pretending the plan was atomic.
     */
    public function approveAll(ProposalPlanService $plan): void
    {
        if ($this->editingFieldCode !== null) {
            return;
        }

        $anchor = $this->loadStep($this->pendingActionId);

        if (! $anchor instanceof PendingAction) {
            return;
        }

        $this->ensureTenantContext();

        $leftOutRows = $this->leftOutRowMap();
        $affectedIds = $this->leftOutAffectedIds();

        try {
            $result = $plan->approveAll($anchor, $this->authUser(), $this->leftOutStepIds(), $leftOutRows);
        } catch (QueryException $exception) {
            $this->reportDatabaseFailure($anchor, $exception);

            return;
        } catch (TransportExceptionInterface $exception) {
            $this->reportDeliveryFailure($anchor, $exception);

            return;
        } catch (RuntimeException|ValidationException $exception) {
            $this->reportResolveFailure($anchor, ApprovalFailureMessage::for($exception));

            return;
        }

        $this->leftOutSteps = [];
        $this->leftOutRows = [];

        $this->announceLeftOut($affectedIds, $leftOutRows);

        foreach ($result['approved'] as $step) {
            $this->announceResolution($step, 'approved');
        }

        if ($result['failed'] !== null) {
            $this->reportResolveFailure($result['failed']['step'], __(':step could not be completed: :message', [
                'step' => $this->failingRowSummary($result['failed']['step']) ?? $this->stepSummary($result['failed']['step']),
                'message' => $result['failed']['message'],
            ]));

            $remaining = $this->loadStep($this->pendingActionId) ?? $this->nextPendingStepOfPlan();

            if ($remaining instanceof PendingAction) {
                $this->pendingActionId = (string) $remaining->getKey();
                $this->focusFirstPendingStep($remaining);
            }

            return;
        }

        $this->settleAfterResolution($anchor);
    }

    /**
     * Leave one step out of the approval, or keep it again. A step that links to it
     * goes with it, which leftOutDependents() reports.
     */
    public function toggleLeftOut(string $stepId): void
    {
        $step = $this->planStepById($stepId);

        if (! $step instanceof PendingAction) {
            return;
        }

        $leftOut = $this->leftOutStepIds();
        $rows = $this->leftOutRowMap();

        unset($rows[$stepId]);

        $this->leftOutRows = $rows;
        $this->leftOutSteps = in_array($stepId, $leftOut, true)
            ? array_values(array_diff($leftOut, [$stepId]))
            : [...$leftOut, $stepId];
    }

    /**
     * Leave one row of a batch step out of the approval, or keep it again. A row that a
     * later step links to, and any row of a step with its own button inside a plan,
     * stays in.
     */
    public function toggleLeftOutRow(string $stepId, int $index): void
    {
        $step = $this->planStepById($stepId);

        if (! $step instanceof PendingAction || ! ProposalPayload::from($step)->isBatch) {
            return;
        }

        if ($step->needsOwnApproval() && count($this->planSteps()) > 1) {
            return;
        }

        if (in_array($stepId, $this->leftOutStepIds(), true) || ! in_array($index, $this->unresolvedIndices($step), true)) {
            return;
        }

        if (isset($this->linkedRowIndexes()[$stepId][$index])) {
            return;
        }

        $map = $this->leftOutRowMap();
        $rows = $map[$stepId] ?? [];

        $map[$stepId] = in_array($index, $rows, true)
            ? array_values(array_diff($rows, [$index]))
            : [...$rows, $index];

        $this->leftOutRows = $map;
    }

    /**
     * Each step that goes down with a left-out step, mapped to the left-out step.
     *
     * @return array<string, string>
     */
    public function leftOutDependents(): array
    {
        return resolve(ProposalPlanService::class)->dependentsOfLeftOut($this->planAllSteps(), $this->leftOutStepIds());
    }

    /**
     * Rows that a later step links to, by batch step id and row index, named after the
     * step that links to them. A step that is left out no longer holds a row in.
     *
     * @return array<string, array<int, string>>
     */
    public function linkedRowIndexes(): array
    {
        $leftOut = [...$this->leftOutStepIds(), ...array_keys($this->leftOutDependents())];

        return array_map(
            fn (array $rows): array => array_map($this->stepSummary(...), $rows),
            resolve(ProposalPlanService::class)->linkedRows($this->planAllSteps(), $leftOut),
        );
    }

    /**
     * Each step a left-out step takes with it, mapped to the step it links to directly, which is
     * left out itself or taken in turn.
     *
     * @return array<string, string>
     */
    private function leftOutLinks(): array
    {
        $taken = $this->leftOutDependents();
        $out = [...$this->leftOutStepIds(), ...array_keys($taken)];
        $links = [];

        foreach ($this->planSteps() as $step) {
            $stepId = (string) $step->getKey();

            if (! isset($taken[$stepId])) {
                continue;
            }

            $direct = array_values(array_intersect(resolve(ProposalPlanService::class)->dependencyIds($step), $out));
            $links[$stepId] = $direct[0] ?? $taken[$stepId];
        }

        return $links;
    }

    /**
     * Records that Approve will write: steps and rows still in, counted per record.
     * A step with its own button is never part of it.
     */
    public function approvableRecordCount(): int
    {
        $count = 0;

        foreach ($this->stepsStillIn() as $step) {
            if ($step->needsOwnApproval()) {
                continue;
            }

            $stepId = (string) $step->getKey();

            $count += ProposalPayload::from($step)->isBatch
                ? count(array_diff($this->unresolvedIndices($step), $this->leftOutRowMap()[$stepId] ?? []))
                : 1;
        }

        return $count;
    }

    /**
     * Whether the primary must lose its shortcut and turn danger: it would run a
     * delete, or the one thing left in is approved on its own and never from the keyboard.
     */
    public function primaryIsRisky(): bool
    {
        $docked = $this->dockedOwnApprovalStep();

        if ($docked instanceof PendingAction) {
            return ! $docked->entity_type->approvesFromKeyboard();
        }

        $stillIn = $this->stepsStillIn();

        foreach ($stillIn as $step) {
            if ($step->entity_type->deletes($step->operation)) {
                return true;
            }
        }

        return count($stillIn) === 1 && $stillIn[0]->needsOwnApproval() && ! $stillIn[0]->entity_type->approvesFromKeyboard();
    }

    /**
     * Pending steps neither left out nor taken by a left-out step.
     *
     * @return list<PendingAction>
     */
    private function stepsStillIn(): array
    {
        $taken = $this->leftOutDependents();
        $leftOut = $this->leftOutStepIds();

        return array_values(array_filter(
            $this->planSteps(),
            fn (PendingAction $step): bool => ! in_array((string) $step->getKey(), $leftOut, true)
                && ! isset($taken[(string) $step->getKey()]),
        ));
    }

    /**
     * @return list<string>
     */
    private function leftOutStepIds(): array
    {
        return ProposalPlanService::cleanLeftOutSteps($this->leftOutSteps);
    }

    /**
     * @return array<string, list<int>>
     */
    private function leftOutRowMap(): array
    {
        return ProposalPlanService::cleanLeftOutRows($this->leftOutRows);
    }

    private function planStepById(string $stepId): ?PendingAction
    {
        foreach ($this->planSteps() as $step) {
            if ((string) $step->getKey() === $stepId) {
                return $step;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function leftOutAffectedIds(): array
    {
        $planIds = array_map(static fn (PendingAction $step): string => (string) $step->getKey(), $this->planSteps());

        return array_values(array_intersect($planIds, [
            ...$this->leftOutStepIds(),
            ...array_keys($this->leftOutDependents()),
            ...array_keys($this->leftOutRowMap()),
        ]));
    }

    /**
     * Tell the transcript what Approve rejected on the way: the left-out rows, then
     * every step that is now rejected (left out, or taken by one that was).
     *
     * @param  list<string>  $affectedIds
     * @param  array<string, list<int>>  $leftOutRows
     */
    private function announceLeftOut(array $affectedIds, array $leftOutRows): void
    {
        foreach (PendingAction::query()->whereKey($affectedIds)->orderBy('id')->get() as $step) {
            $stepId = (string) $step->getKey();
            $items = is_array($step->result_data['items'] ?? null) ? $step->result_data['items'] : [];

            foreach ($leftOutRows[$stepId] ?? [] as $index) {
                if (($items[$index]['status'] ?? null) !== 'rejected') {
                    continue;
                }

                $this->dispatch(
                    'proposal:resolved',
                    pendingActionId: $stepId,
                    index: $index,
                    decision: 'rejected',
                    finalized: false,
                    record: null,
                    context: $this->context,
                );
            }

            if ($step->status === PendingActionStatus::Rejected) {
                $this->announceResolution($step, 'rejected');
            }
        }
    }

    /**
     * Approve one step of the plan, leaving the rest pending.
     */
    public function approveStep(string $stepId, ProposalPlanService $plan): void
    {
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
        } catch (TransportExceptionInterface $exception) {
            $this->reportDeliveryFailure($step, $exception);

            return;
        } catch (RuntimeException|ValidationException $exception) {
            $this->reportResolveFailure($step, $this->labelledFailure($step, ApprovalFailureMessage::for($exception)));

            return;
        }

        $this->announceResolution($step, 'approved');
        $this->settleAfterResolution($step);
    }

    public function approveAlways(string $stepId, ProposalPlanService $plan, SetWebSearchPreference $preference): void
    {
        $step = $this->loadStep($stepId);

        if (! $step instanceof PendingAction || $step->entity_type !== ProposalEntity::WebAccess) {
            return;
        }

        $this->approveStep($stepId, $plan);

        if ($step->refresh()->status === PendingActionStatus::Approved) {
            $preference->execute($this->authUser(), WebSearchPreference::Allow);
        }
    }

    /**
     * Reject one step and cancel whatever depended on it.
     */
    public function rejectStep(string $stepId, ProposalPlanService $plan): void
    {
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
            $this->reportLapsedStep();

            return;
        }

        $this->ensureTenantContext();

        if (ProposalPayload::from($pendingAction)->isBatch) {
            $this->approveBatch($pendingAction);

            return;
        }

        // Every change unchecked on an update: approving would write nothing and
        // still stamp the row Approved. The footer disables itself for this, but
        // the ⌘⏎ shortcut lands here directly.
        $excludable = $this->excludableCodesOf($pendingAction);

        if ($pendingAction->operation === PendingActionOperation::Update
            && $excludable !== []
            && array_diff($excludable, $this->excludedFields) === []) {
            return;
        }

        try {
            $resolved = $service->approve($pendingAction, $this->authUser(), $this->excludedFields);
            $record = $this->recordReferenceFor($resolved);
        } catch (QueryException $exception) {
            // Must precede the RuntimeException arm. QueryException extends
            // PDOException extends RuntimeException, so it would otherwise be caught
            // there and its getMessage() put the failing SQL, the row's values and
            // the connection host straight onto the card and into the transcript.
            $this->reportDatabaseFailure($pendingAction, $exception);

            return;
        } catch (TransportExceptionInterface $exception) {
            // Same reasoning as the QueryException arm: TransportException extends
            // \RuntimeException, so an SMTP failure would otherwise render its own
            // message, which names the mail host and port and, on an auth failure,
            // the SMTP username. Inviting a teammate is the first proposal action
            // that sends mail inside approve().
            $this->reportDeliveryFailure($pendingAction, $exception);

            return;
        } catch (RuntimeException|ValidationException $exception) {
            // ValidationException is thrown by the action's tenant guards when a
            // referenced record or assignee stopped being reachable between the
            // proposal and the approval. HttpException (from abort_*() inside an
            // action, e.g. a capability guard) is a RuntimeException too. Livewire
            // would otherwise absorb these into an error bag nothing renders,
            // leaving the button a permanent no-op.
            $this->reportResolveFailure($pendingAction, ApprovalFailureMessage::for($exception));

            return;
        }

        $this->dispatch(
            'proposal:resolved',
            pendingActionId: $pendingAction->getKey(),
            index: null,
            decision: 'approved',
            finalized: true,
            record: $record,
            context: $this->context,
        );

        $this->settleAfterResolution($pendingAction);
    }

    private function approveBatch(PendingAction $batch): void
    {
        $plan = resolve(ProposalPlanService::class);

        if (! $batch->needsOwnApproval()) {
            $this->approveAll($plan);

            return;
        }

        $stepId = (string) $batch->getKey();
        $leftOutRows = $this->leftOutRowMap();

        try {
            $plan->rejectRows($batch, $this->authUser(), $leftOutRows[$stepId] ?? []);
        } catch (QueryException $exception) {
            $this->reportDatabaseFailure($batch, $exception);

            return;
        } catch (RuntimeException $exception) {
            $this->reportResolveFailure($batch, $exception->getMessage());

            return;
        }

        $this->leftOutRows = [];
        $this->announceLeftOut([$stepId], $leftOutRows);

        if ($batch->refresh()->isPending()) {
            $this->approveStep($stepId, $plan);

            return;
        }

        $this->settleAfterResolution($batch);
    }

    public function discardCurrent(PendingActionService $service): void
    {
        if ($this->editingFieldCode !== null) {
            return;
        }

        $pendingAction = $this->loadStep($this->activeStepId());

        if (! $pendingAction instanceof PendingAction) {
            $this->reportLapsedStep();

            return;
        }

        try {
            resolve(ProposalPlanService::class)->reject($pendingAction, $this->authUser());
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
            index: null,
            decision: 'rejected',
            finalized: true,
            record: null,
            context: $this->context,
        );

        // Rejecting a step cancels its dependents, so the transcript is told about
        // each of them too, a card that silently vanished would read as a bug.
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
            ->where('workspace_id', $step->workspace_id)
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
        $record = null;

        // Only an approval needs the reload: it is the executed write that stamps
        // the created record onto result_data. A cancelled step arrives here
        // already carrying its outcome, from cancelStep()'s saved model.
        if ($decision === 'approved') {
            $step = $step->refresh();
            $record = $this->recordReferenceFor($step);
        }

        // A step cancelled because the step it depended on was rejected has to say
        // so live, not only after a reload. ConversationMessagesQuery sets this on
        // the way back in; without it here the transcript shows a bare "Rejected"
        // in the very session where the cascade happened, which is the outcome
        // PendingActionService::cancelStep() records it to prevent.
        $cancelledBy = $step->result_data['cancelled_by'] ?? null;

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
     * step or closes when there is none left, and when there is none left, the
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
            ->where('workspace_id', $user->currentWorkspace->getKey())
            ->first();

        if (! $resolved instanceof PendingAction || $resolved->turn_id === null) {
            return null;
        }

        return PendingAction::query()
            ->where('workspace_id', $user->currentWorkspace->getKey())
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
     * the `resolve` error bag which the dock renders. A proposal that cannot be
     * resolved must say so rather than leave the button dead.
     */
    /**
     * A database error is never shown verbatim: the driver's message carries the
     * statement, its bindings and the connection details. The operator still needs
     * the real thing, so it goes to the log instead.
     *
     * A unique violation here means something got past the actions' re-validation,
     * in practice a second approval landing in the same instant, so it is reported
     * as a race the user can retry, not as a generic failure.
     */
    private function reportDatabaseFailure(PendingAction $pendingAction, QueryException $exception): void
    {
        report($exception);

        $this->reportResolveFailure($pendingAction, $exception->getCode() === '23505'
            ? __('Someone else just made a conflicting change. Reload the page and try again.')
            : __('This change could not be saved. Please try again.'));
    }

    /**
     * The approval transaction rolls back when the mail send throws, so nothing was
     * written and retrying is safe: say exactly that, and keep the transport's own
     * message (mail host, port, SMTP username) out of the card and the transcript.
     */
    private function reportDeliveryFailure(PendingAction $pendingAction, TransportExceptionInterface $exception): void
    {
        report($exception);

        $this->reportResolveFailure($pendingAction, $this->labelledFailure($pendingAction, ApprovalFailureMessage::forDelivery()));
    }

    /**
     * On a batch step the earlier rows have committed, so the message names the row
     * that stopped the run: the first one still undecided.
     */
    private function labelledFailure(PendingAction $step, string $message): string
    {
        $record = $this->failingRowSummary($step);

        return $record === null ? $message : __(':record: :message', ['record' => $record, 'message' => $message]);
    }

    private function failingRowSummary(PendingAction $step): ?string
    {
        if (! ProposalPayload::from($step)->isBatch || $step->isEmailSend()) {
            return null;
        }

        $fresh = $step->fresh() ?? $step;
        $summary = ProposalPayload::from($fresh)->displayAt($this->firstUnresolvedIndex($fresh))['summary'] ?? null;

        return is_string($summary) && $summary !== '' ? $summary : null;
    }

    // The page expires proposals on its own clock, which can run behind the server's.
    private function reportLapsedStep(): void
    {
        $user = $this->authUser();

        $lapsed = PendingAction::query()
            ->whereKey($this->activeStepId())
            ->where('workspace_id', $user->currentWorkspace->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        if (! $lapsed instanceof PendingAction || ! $lapsed->isPending() || ! $lapsed->isExpired()) {
            return;
        }

        $this->dispatch('proposal:lapsed', pendingActionId: $lapsed->getKey(), context: $this->context);
    }

    private function reportResolveFailure(PendingAction $pendingAction, string $message): void
    {
        $this->addError('resolve', $message);

        // Persisted so the model can explain the failure later: without this
        // the card is the only witness, and a proposal discarded after a failed
        // approval reads to the assistant as a plain user rejection.
        resolve(PendingActionService::class)->recordResolveFailure($pendingAction, $message);

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

        $workspace = $this->authUser()->currentWorkspace;

        if ($workspace === null) {
            return;
        }

        Filament::setTenant($workspace, isQuiet: true);
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

        return resolve(RecordReferenceResolver::class)->resolve($pendingAction->entity_type->value, (string) $recordId);
    }

    /**
     * @return array<string, mixed>
     */
    private function currentRecordDisplay(PendingAction $pendingAction): array
    {
        return ProposalPayload::from($pendingAction)->displayAt($this->cursorFor($pendingAction));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function currentRecordFields(): array
    {
        return $this->recordFieldsOf($this->loadStep($this->activeStepId()));
    }

    /**
     * The current record's display rows, rebuilt through ProposalDisplayBuilder
     * from the record's clean action_data so each owned/editable row carries a
     * `code`. Carried-forward relationship rows stay code-less (read-only). The
     * rebuild is byte-for-byte the stored display because applyEdit re-renders
     * with the same builder. See ProposalCardEditingTest's no-divergence test.
     *
     * @return list<array<string, mixed>>
     */
    private function recordFieldsOf(?PendingAction $pendingAction): array
    {
        if (! $pendingAction instanceof PendingAction) {
            return [];
        }

        $this->ensureTenantContext();

        $existingFields = $this->currentDisplayFields($pendingAction);

        // Only a Create proposal is rebuilt, to attach editable codes. Update and delete rows hold
        // diffs, and an email is never edited, so their stored rows stand.
        if ($pendingAction->operation !== PendingActionOperation::Create || $pendingAction->entity_type->isIndivisible()) {
            return $existingFields;
        }

        $record = $this->currentRecord($pendingAction);

        return resolve(ProposalDisplayBuilder::class)
            ->build($this->authUser(), $pendingAction->entity_type, $record, $existingFields)['fields'];
    }

    /**
     * @return list<string>
     */
    public function editableCodes(): array
    {
        return $this->editableCodesOf($this->loadStep($this->activeStepId()));
    }

    /**
     * The set of field codes the dock allows inline editing for the current
     * entity: core keys plus the active, non-deferred custom field codes. Derived
     * from ProposalFieldSchemaDescriber so the deferred-field exclusion (FILE_UPLOAD,
     * RECORD/lookup, unsupported kinds) stays single-sourced with the editor.
     *
     * @return list<string>
     */
    private function editableCodesOf(?PendingAction $pendingAction): array
    {
        if (! $pendingAction instanceof PendingAction) {
            return [];
        }

        // Only Create proposals are editable. Never offer edit pencils on delete/update.
        if ($pendingAction->operation !== PendingActionOperation::Create) {
            return [];
        }

        $entity = $pendingAction->entity_type;
        $entityType = $entity->value;

        if ($entity->isIndivisible() || ! is_string(Relation::getMorphedModel($entityType))) {
            return [];
        }

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
            ->describe($this->authUser(), $entity, $this->currentRecord($pendingAction));

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
        // ran its own CustomField query (editableCodes two more for workspace members) on
        // every dock round trip, only to be discarded. stepViews() already carries
        // both per step. The counters below stay: they are cheap array reads and the
        // batch footer's behaviour is asserted through them.
        return view('chat::livewire.chat.proposal-card', [
            'proposal' => $proposal,
            'steps' => $steps,
            'isPlan' => count($steps) > 1,
            'approvableRecordCount' => $this->approvableRecordCount(),
            'primaryIsRisky' => $this->primaryIsRisky(),
            'leftOutStepIds' => $this->leftOutStepIds(),
            'leftOutRowIndexes' => $this->leftOutRowMap(),
            'takenBy' => $this->leftOutDependents(),
            'takenVia' => $this->leftOutLinks(),
            'linkedRows' => $this->linkedRowIndexes(),
            'hasOwnApprovalSteps' => in_array(true, array_column($steps, 'needsOwnApproval'), true),
            'recordCount' => $proposal instanceof PendingAction ? $this->recordCountOf($proposal) : 0,
            'remainingCount' => $proposal instanceof PendingAction ? $this->remainingCountOf($proposal) : 0,
        ]);
    }
}
