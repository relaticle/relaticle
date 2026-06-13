<?php

declare(strict_types=1);

namespace Relaticle\Chat\Livewire\Chat;

use App\Livewire\BaseLivewireComponent;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;

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

        if ($pendingAction === null) {
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

        if ($pendingAction === null) {
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

    private function firstUnresolvedIndex(PendingAction $pendingAction): int
    {
        $count = $this->resolveRecordCount($pendingAction);

        $resultData = $pendingAction->result_data;
        $items = is_array($resultData) && is_array($resultData['items'] ?? null) ? $resultData['items'] : [];

        for ($index = 0; $index < $count; $index++) {
            if (! isset($items[(string) $index])) {
                return $index;
            }
        }

        return $count - 1;
    }

    public function render(): View
    {
        $proposal = $this->pendingActionId !== null ? $this->loadPending($this->pendingActionId) : null;

        return view('chat::livewire.chat.proposal-card', [
            'proposal' => $proposal,
        ]);
    }
}
