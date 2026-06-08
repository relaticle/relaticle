<?php

declare(strict_types=1);

namespace Relaticle\Chat\Livewire\Chat;

use App\Livewire\BaseLivewireComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
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

    /**
     * Authoritative latest assistant message for the conversation, used by the
     * client to reconcile the streamed bubble against persisted state on stream_end.
     *
     * @return array{id: string, content: string}|null
     */
    public function latestAssistantMessage(): ?array
    {
        if ($this->conversationId === null) {
            return null;
        }

        $user = $this->authUser();

        $row = DB::table('agent_conversation_messages as m')
            ->join('agent_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('m.conversation_id', $this->conversationId)
            ->where('m.user_id', $user->getKey())
            ->where('c.team_id', $user->current_team_id)
            ->where('m.role', 'assistant')
            ->latest('m.created_at')
            ->orderByDesc('m.id')
            ->first(['m.id', 'm.content']);

        if ($row === null) {
            return null;
        }

        return ['id' => (string) $row->id, 'content' => (string) $row->content];
    }

    public function render(): View
    {
        return view('chat::livewire.chat.chat-interface');
    }
}
