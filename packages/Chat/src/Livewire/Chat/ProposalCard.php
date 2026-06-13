<?php

declare(strict_types=1);

namespace Relaticle\Chat\Livewire\Chat;

use App\Livewire\BaseLivewireComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Support\RecordReferenceResolver;

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

    /**
     * @param  array{id?: string|null, context?: string}  $payload
     */
    #[On('proposal:set-active')]
    public function setActive(array $payload): void
    {
        if (($payload['context'] ?? 'conversation') !== $this->context) {
            return;
        }

        $this->editingFieldCode = null;
        $id = $payload['id'] ?? null;

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
        $this->stepTo($this->cursor + 1);
    }

    public function stepPrev(): void
    {
        $this->stepTo($this->cursor - 1);
    }

    private function stepTo(int $index): void
    {
        $lastIndex = $this->recordCount() - 1;

        $this->cursor = max(0, min($index, $lastIndex));
        $this->editingFieldCode = null;
    }

    public function recordCount(): int
    {
        if ($this->pendingActionId === null) {
            return 1;
        }

        $pendingAction = $this->loadPending($this->pendingActionId);

        if (! $pendingAction instanceof PendingAction) {
            return 1;
        }

        return $this->resolveRecordCount($pendingAction);
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

    public function createCurrent(PendingActionService $service): void
    {
        $pendingAction = $this->loadPending($this->pendingActionId ?? '');

        if (! $pendingAction instanceof PendingAction) {
            return;
        }

        $isBatch = ($pendingAction->action_data['_batch'] ?? false) === true;
        $willFinalize = $this->willFinalize($pendingAction, $this->cursor);

        $this->dispatch('proposal:will-resolve', willFinalize: $willFinalize, context: $this->context);

        if ($isBatch) {
            $result = $service->approveItem($pendingAction, $this->authUser(), $this->cursor);
            $finalized = $result['finalized'];
            $record = $result['record'] instanceof Model
                ? resolve(RecordReferenceResolver::class)->resolve($pendingAction->entity_type, (string) $result['record']->getKey())
                : null;
        } else {
            $resolved = $service->approve($pendingAction, $this->authUser());
            $finalized = true;
            $record = $this->recordReferenceFor($resolved);
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

        $this->cursor = $this->firstUnresolvedIndex($pendingAction->fresh());
    }

    public function discardCurrent(PendingActionService $service): void
    {
        $pendingAction = $this->loadPending($this->pendingActionId ?? '');

        if (! $pendingAction instanceof PendingAction) {
            return;
        }

        $isBatch = ($pendingAction->action_data['_batch'] ?? false) === true;
        $willFinalize = $this->willFinalize($pendingAction, $this->cursor);

        $this->dispatch('proposal:will-resolve', willFinalize: $willFinalize, context: $this->context);

        if ($isBatch) {
            $result = $service->rejectItem($pendingAction, $this->cursor);
            $finalized = $result['finalized'];
        } else {
            $service->reject($pendingAction);
            $finalized = true;
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

        $this->cursor = $this->firstUnresolvedIndex($pendingAction->fresh());
    }

    private function willFinalize(PendingAction $pendingAction, int $index): bool
    {
        if (($pendingAction->action_data['_batch'] ?? false) !== true) {
            return true;
        }

        $count = $this->resolveRecordCount($pendingAction);

        $items = $this->resolvedItems($pendingAction);

        for ($other = 0; $other < $count; $other++) {
            if ($other === $index) {
                continue;
            }

            if (! isset($items[(string) $other])) {
                return false;
            }
        }

        return true;
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

    public function render(): View
    {
        $proposal = $this->pendingActionId !== null ? $this->loadPending($this->pendingActionId) : null;

        return view('chat::livewire.chat.proposal-card', [
            'proposal' => $proposal,
        ]);
    }
}
