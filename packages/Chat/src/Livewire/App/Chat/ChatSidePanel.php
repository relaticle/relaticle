<?php

declare(strict_types=1);

namespace Relaticle\Chat\Livewire\App\Chat;

use App\Enums\Plan;
use App\Livewire\BaseLivewireComponent;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\ChatContextService;

final class ChatSidePanel extends BaseLivewireComponent
{
    public bool $isOpen = false;

    public ?string $conversationId = null;

    /** @var array<int, array{label: string, prompt: string}> */
    public array $suggestedPrompts = [];

    /**
     * @var array<string, string>
     */
    protected $listeners = [
        'chat:open-panel' => 'openPanel',
        'chat:close-panel' => 'closePanel',
        'chat:toggle-panel' => 'togglePanel',
    ];

    public function mount(): void
    {
        $this->refreshContext();
    }

    public function openPanel(?string $conversationId = null): void
    {
        $this->isOpen = true;

        if ($conversationId !== null) {
            $this->conversationId = $conversationId;
        }
    }

    public function closePanel(): void
    {
        $this->isOpen = false;
    }

    public function togglePanel(): void
    {
        $this->isOpen = ! $this->isOpen;
    }

    /**
     * Called when the dashboard hero input sends a message.
     * Opens the panel and forwards the message to the embedded chat.
     */
    public function handleSendFromDashboard(string $message, string $source = 'dashboard'): void
    {
        $this->isOpen = true;
        $this->dispatch('chat:send-message', message: $message);
    }

    /**
     * Refresh context from ChatContextService.
     * Called on mount and after SPA navigation.
     */
    public function refreshContext(): void
    {
        if (! $this->isOpen) {
            return;
        }

        $contextService = resolve(ChatContextService::class);
        $context = $contextService->getContext();
        $this->suggestedPrompts = $contextService->getSuggestedPrompts($context);
    }

    #[Computed]
    public function plan(): Plan
    {
        /** @var User|null $user */
        $user = auth()->user();
        $team = $user?->currentTeam;

        return $team !== null ? $team->plan : Plan::default();
    }

    #[Computed]
    public function creditsRemaining(): int
    {
        /** @var User|null $user */
        $user = auth()->user();
        $teamId = $user?->currentTeam?->getKey();

        if ($teamId === null) {
            return 0;
        }

        return AiCreditBalance::query()
            ->where('team_id', $teamId)
            ->value('credits_remaining') ?? 0;
    }

    public function render(): View
    {
        return view('chat::livewire.app.chat.chat-side-panel');
    }
}
