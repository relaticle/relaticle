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

    private function loadPending(string $id): ?PendingAction
    {
        $user = $this->authUser();

        return PendingAction::query()
            ->whereKey($id)
            ->where('team_id', $user->currentTeam->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', PendingActionStatus::Pending)
            ->first();
    }

    private function firstUnresolvedIndex(PendingAction $pendingAction): int
    {
        return 0;
    }

    public function render(): View
    {
        $proposal = $this->pendingActionId !== null ? $this->loadPending($this->pendingActionId) : null;

        return view('chat::livewire.chat.proposal-card', [
            'proposal' => $proposal,
        ]);
    }
}
