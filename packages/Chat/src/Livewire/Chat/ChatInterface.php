<?php

declare(strict_types=1);

namespace Relaticle\Chat\Livewire\Chat;

use App\Livewire\BaseLivewireComponent;
use Illuminate\Contracts\View\View;
use Relaticle\Chat\Actions\ListConversationMessages;

final class ChatInterface extends BaseLivewireComponent
{
    public ?string $conversationId = null;

    public ?string $initialMessage = null;

    public ?string $initialModel = null;

    public ?string $oldestMessageId = null;

    public bool $hasMoreMessages = false;

    public string $context = 'conversation';

    private const int PAGE_SIZE = 50;

    /**
     * @var array<int, array{id?: string, role: string, content: string, created_at?: ?string, pending_actions?: array<int, mixed>, mentions?: list<array{type: string, id: string, label: string}>}>
     */
    public array $messages = [];

    public function mount(?string $conversationId = null, ?string $initialMessage = null, string $context = 'conversation', ?string $initialModel = null): void
    {
        $this->conversationId = $conversationId;
        $this->context = $context;

        /** @var string|null $promptQuery */
        $promptQuery = request()->query('prompt');
        $this->initialMessage = $initialMessage ?? $promptQuery;

        /** @var string|null $modelQuery */
        $modelQuery = request()->query('model');
        $this->initialModel = $initialModel ?? $modelQuery;

        if ($this->conversationId !== null) {
            $this->messages = $this->fetchMessages();
            $this->oldestMessageId = $this->messages === [] ? null : ($this->messages[0]['id'] ?? null);
            $this->hasMoreMessages = count($this->messages) === self::PAGE_SIZE;
        }
    }

    /**
     * @return array<int, array{id: string, role: string, content: string, created_at: ?string, pending_actions: array<int, mixed>}>
     */
    public function fetchMessages(): array
    {
        if ($this->conversationId === null) {
            return [];
        }

        return resolve(ListConversationMessages::class)->execute(
            $this->authUser(),
            $this->conversationId,
        );
    }

    public function loadEarlierMessages(): void
    {
        if ($this->conversationId === null || $this->oldestMessageId === null) {
            return;
        }

        $earlier = resolve(ListConversationMessages::class)->execute(
            $this->authUser(),
            $this->conversationId,
            beforeMessageId: $this->oldestMessageId,
        );

        $this->messages = [...$earlier, ...$this->messages];
        $this->oldestMessageId = $this->messages === [] ? null : ($this->messages[0]['id'] ?? $this->oldestMessageId);
        $this->hasMoreMessages = count($earlier) === self::PAGE_SIZE;

        $this->dispatch('chat:messages-prepended', messages: $earlier, hasMore: $this->hasMoreMessages);
    }

    public function render(): View
    {
        return view('chat::livewire.chat.chat-interface');
    }
}
