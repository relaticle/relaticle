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
use Livewire\Attributes\On;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Services\ProposalEditor;
use Relaticle\Chat\Services\Tools\ProposalDisplayBuilder;
use Relaticle\Chat\Services\Tools\ProposalFieldSchemaDescriber;
use Relaticle\Chat\Support\ProposalCoreFields;
use Relaticle\Chat\Support\RecordReferenceResolver;
use Relaticle\Chat\Support\TeamMembersContext;
use Relaticle\CustomFields\Facades\CustomFields;
use RuntimeException;

final class ProposalCard extends BaseLivewireComponent
{
    public string $context = 'conversation';

    public ?string $pendingActionId = null;

    public int $cursor = 0;

    public ?string $editingFieldCode = null;

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

    public function editField(string $code): void
    {
        $pendingAction = $this->loadPending($this->pendingActionId ?? '');

        if (! $pendingAction instanceof PendingAction || $pendingAction->operation !== PendingActionOperation::Create) {
            return;
        }

        $this->ensureTenantContext();

        $this->editingFieldCode = $code;
        $this->form->fill($this->formStateFor($pendingAction, $code));
    }

    public function saveField(): void
    {
        $pendingAction = $this->loadPending($this->pendingActionId ?? '');

        if (! $pendingAction instanceof PendingAction || $this->editingFieldCode === null) {
            return;
        }

        $input = $this->flattenFormState($this->form->getState());
        $index = ($pendingAction->action_data['_batch'] ?? false) === true ? $this->cursor : null;

        try {
            resolve(ProposalEditor::class)->applyEdit($pendingAction, $this->authUser(), $input, $index);
        } catch (RuntimeException) {
            $this->addError('field', __('This change could not be saved. Please review the value and try again.'));

            return;
        }

        $this->editingFieldCode = null;
    }

    public function cancelField(): void
    {
        $this->editingFieldCode = null;
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
        $pendingAction = $this->loadPending($this->pendingActionId ?? '');

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
        $data = $pendingAction->action_data;

        if (($data['_batch'] ?? false) !== true) {
            return $data;
        }

        $records = is_array($data['records'] ?? null) ? array_values($data['records']) : [];
        $record = $records[$this->cursor] ?? [];

        return is_array($record) ? $record : [];
    }

    /**
     * @return class-string<Model>
     */
    private function modelClass(): string
    {
        $pendingAction = $this->loadPending($this->pendingActionId ?? '');

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
    public function setActive(?string $id = null, string $context = 'conversation'): void
    {
        if ($context !== $this->context) {
            return;
        }

        $this->editingFieldCode = null;

        if ($id === null) {
            $this->pendingActionId = null;

            return;
        }

        $pendingAction = $this->loadPending($id);

        if (! $pendingAction instanceof PendingAction) {
            $this->pendingActionId = null;

            return;
        }

        $this->pendingActionId = $pendingAction->getKey();
        $this->cursor = $this->firstUnresolvedIndex($pendingAction);
    }

    public function stepNext(): void
    {
        $this->stepWithin(1);
    }

    public function stepPrev(): void
    {
        $this->stepWithin(-1);
    }

    /**
     * Move the cursor to the adjacent UNRESOLVED record in the given direction.
     * Decided items are dropped from the dock queue entirely (Attio behavior), so
     * they can never be navigated back to and re-decided — their outcome lives in
     * the transcript audit card above.
     */
    private function stepWithin(int $direction): void
    {
        $this->editingFieldCode = null;

        if ($this->pendingActionId === null) {
            return;
        }

        $pendingAction = $this->loadPending($this->pendingActionId);

        if (! $pendingAction instanceof PendingAction) {
            return;
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
        if ($this->pendingActionId === null) {
            return 1;
        }

        $pendingAction ??= $this->loadPending($this->pendingActionId);

        if (! $pendingAction instanceof PendingAction) {
            return 1;
        }

        return $this->resolveRecordCount($pendingAction);
    }

    /**
     * How many records still await a decision — the dock stepper's denominator.
     * Resolved items have left the queue, so this shrinks as the user decides.
     */
    public function remainingCount(?PendingAction $pendingAction = null): int
    {
        if ($this->pendingActionId === null) {
            return 0;
        }

        $pendingAction ??= $this->loadPending($this->pendingActionId);

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
        $pendingAction ??= ($this->pendingActionId !== null ? $this->loadPending($this->pendingActionId) : null);

        if (! $pendingAction instanceof PendingAction) {
            return 1;
        }

        $position = array_search($this->cursor, $this->unresolvedIndices($pendingAction), true);

        return $position === false ? 1 : $position + 1;
    }

    private function resolveRecordCount(PendingAction $pendingAction): int
    {
        $data = $pendingAction->action_data;

        if (($data['_batch'] ?? false) !== true || ! is_array($data['records'] ?? null)) {
            return 1;
        }

        return max(1, count($data['records']));
    }

    private function loadPending(string $id): ?PendingAction
    {
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
     * @return array<array-key, mixed>
     */
    private function resolvedItems(PendingAction $pendingAction): array
    {
        $resultData = $pendingAction->result_data;

        return is_array($resultData) && is_array($resultData['items'] ?? null) ? $resultData['items'] : [];
    }

    private function firstUnresolvedIndex(PendingAction $pendingAction): int
    {
        $count = $this->resolveRecordCount($pendingAction);

        $items = $this->resolvedItems($pendingAction);

        for ($index = 0; $index < $count; $index++) {
            if (! isset($items[(string) $index])) {
                return $index;
            }
        }

        return $count - 1;
    }

    /**
     * Record indices not yet resolved — the only items the dock presents. A decided
     * item leaves this list, so the stepper can never land back on it.
     *
     * @return list<int>
     */
    private function unresolvedIndices(PendingAction $pendingAction): array
    {
        $count = $this->resolveRecordCount($pendingAction);
        $items = $this->resolvedItems($pendingAction);

        $indices = [];

        for ($index = 0; $index < $count; $index++) {
            if (! isset($items[(string) $index])) {
                $indices[] = $index;
            }
        }

        return $indices;
    }

    #[On('proposal:create-current')]
    public function createCurrentFromShortcut(string $context = 'conversation'): void
    {
        if ($context !== $this->context) {
            return;
        }

        $this->createCurrent(resolve(PendingActionService::class));
    }

    public function createCurrent(PendingActionService $service): void
    {
        if ($this->editingFieldCode !== null) {
            return;
        }

        $pendingAction = $this->loadPending($this->pendingActionId ?? '');

        if (! $pendingAction instanceof PendingAction) {
            return;
        }

        $isBatch = ($pendingAction->action_data['_batch'] ?? false) === true;

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
        } catch (RuntimeException $exception) {
            $this->dispatch(
                'proposal:resolve-failed',
                pendingActionId: $pendingAction->getKey(),
                message: $exception->getMessage(),
                context: $this->context,
            );

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

        if ($finalized) {
            $this->pendingActionId = null;

            return;
        }

        $this->cursor = $this->firstUnresolvedIndex($pendingAction->fresh() ?? $pendingAction);
    }

    public function discardCurrent(PendingActionService $service): void
    {
        if ($this->editingFieldCode !== null) {
            return;
        }

        $pendingAction = $this->loadPending($this->pendingActionId ?? '');

        if (! $pendingAction instanceof PendingAction) {
            return;
        }

        $isBatch = ($pendingAction->action_data['_batch'] ?? false) === true;

        // A decided item is no longer in the dock queue — snap to the next undecided
        // one rather than re-running an already-resolved index.
        if ($isBatch && ! in_array($this->cursor, $this->unresolvedIndices($pendingAction), true)) {
            $this->cursor = $this->firstUnresolvedIndex($pendingAction);

            return;
        }

        try {
            if ($isBatch) {
                $result = $service->rejectItem($pendingAction, $this->cursor);
                $finalized = $result['finalized'];
            } else {
                $service->reject($pendingAction);
                $finalized = true;
            }
        } catch (RuntimeException $exception) {
            $this->dispatch(
                'proposal:resolve-failed',
                pendingActionId: $pendingAction->getKey(),
                message: $exception->getMessage(),
                context: $this->context,
            );

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

        if ($finalized) {
            $this->pendingActionId = null;

            return;
        }

        $this->cursor = $this->firstUnresolvedIndex($pendingAction->fresh() ?? $pendingAction);
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
        $display = $pendingAction->display_data;

        if (($pendingAction->action_data['_batch'] ?? false) !== true) {
            return $display;
        }

        $items = is_array($display['items'] ?? null) ? $display['items'] : [];

        if ($items === []) {
            return [];
        }

        $current = $items[$this->cursor] ?? reset($items);

        return is_array($current) ? $current : [];
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
        $pendingAction ??= $this->loadPending($this->pendingActionId ?? '');

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
        $pendingAction ??= $this->loadPending($this->pendingActionId ?? '');

        if (! $pendingAction instanceof PendingAction) {
            return [];
        }

        // Only Create proposals are editable — never offer edit pencils on delete/update.
        if ($pendingAction->operation !== PendingActionOperation::Create) {
            return [];
        }

        $this->ensureTenantContext();

        $schema = resolve(ProposalFieldSchemaDescriber::class)
            ->describe($this->authUser(), $pendingAction->entity_type, $this->currentRecord($pendingAction));

        return array_map(static fn (array $field): string => (string) $field['code'], $schema);
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
        $proposal = $this->pendingActionId !== null ? $this->loadPending($this->pendingActionId) : null;

        return view('chat::livewire.chat.proposal-card', [
            'proposal' => $proposal,
            'record' => $proposal instanceof PendingAction ? $this->currentRecordDisplay($proposal) : [],
            'recordFields' => $proposal instanceof PendingAction ? $this->currentRecordFields($proposal) : [],
            'editableCodes' => $proposal instanceof PendingAction ? $this->editableCodes($proposal) : [],
            'recordCount' => $proposal instanceof PendingAction ? $this->recordCount($proposal) : 0,
            'remainingCount' => $proposal instanceof PendingAction ? $this->remainingCount($proposal) : 0,
            'position' => $proposal instanceof PendingAction ? $this->currentPosition($proposal) : 1,
        ]);
    }
}
