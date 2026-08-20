<?php

declare(strict_types=1);

namespace Relaticle\Chat\Livewire\Chat;

use App\Livewire\BaseLivewireComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Renderless;
use Relaticle\Chat\Actions\FindConversation;
use Relaticle\Chat\Actions\ListConversationMessages;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Support\DisplayBlocks;
use Relaticle\Chat\Support\TitleSanitizer;

final class ChatInterface extends BaseLivewireComponent
{
    public ?string $conversationId = null;

    public ?string $initialMessage = null;

    public ?string $initialModel = null;

    public ?string $oldestMessageId = null;

    public bool $hasMoreMessages = false;

    public string $context = 'conversation';

    public ?string $pageContextType = null;

    public ?string $pageContextId = null;

    public ?string $pageContextLabel = null;

    /**
     * Record-aware starter prompts supplied by the side panel. Empty on the
     * full-page chat, where the interface falls back to a generic list.
     *
     * @var array<int, array{label: string, prompt: string}>
     */
    public array $contextPrompts = [];

    private const int PAGE_SIZE = 50;

    /**
     * @var array<int, array{id?: string, role: string, content: string, created_at?: ?string, pending_actions?: array<int, mixed>, mentions?: list<array{type: string, id: string, label: string}>}>
     */
    public array $messages = [];

    /**
     * @param  array<int, array{label: string, prompt: string}>  $contextPrompts
     */
    public function mount(?string $conversationId = null, ?string $initialMessage = null, string $context = 'conversation', ?string $initialModel = null, ?string $pageContextType = null, ?string $pageContextId = null, ?string $pageContextLabel = null, array $contextPrompts = []): void
    {
        $this->conversationId = $conversationId;
        $this->context = $context;
        $this->pageContextType = $pageContextType;
        $this->pageContextId = $pageContextId;
        $this->pageContextLabel = $pageContextLabel;
        $this->contextPrompts = $contextPrompts;

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

    /**
     * Renderless: the client already applies the delta from the dispatched
     * chat:messages-prepended event (prepending `messages`, restoring scroll
     * by anchoring to the pre-prepend scrollHeight). A normal render here
     * would re-serialize the now-larger $this->messages back into the root
     * element's `x-data="chatInterface(..., @js($messages), ...)"` attribute
     * and morph it in, which (confirmed empirically) does not merely patch
     * that attribute string but tears down and remounts the whole Alpine
     * component (destroy() then a fresh init()), discarding the very
     * prependScrollAnchor/loadingEarlier state this method's own client-side
     * counterpart depends on, and unconditionally re-running init()'s own
     * scrollToBottom(true), silently overwriting the scroll-restore with a
     * jump to the bottom on every call.
     */
    #[Renderless]
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
     * client to reconcile the streamed bubble against persisted state on stream_end
     * (and on the watchdog timeout). Also returns the conversation's still-pending
     * proposal cards so a dropped `.tool_result` websocket event — which would
     * otherwise leave the approve/reject CTA missing until a full reload — is
     * self-healed by the client merging any cards it never received.
     *
     * Read-tool display blocks ride along for the same reason: they are never
     * broadcast live (Reverb's 10 KB cap), so stream_end is the first moment the
     * client can render them.
     *
     * @return array{id: string, content: string, pending_actions: list<array<string, mixed>>, display_blocks: list<array<string, mixed>>}|null
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
            ->where('m.participant_type', $user->getMorphClass())
            ->where('m.participant_id', $user->getKey())
            ->where('c.team_id', $user->current_team_id)
            ->where('m.role', 'assistant')
            ->whereNull('m.superseded_at')
            ->latest('m.created_at')
            ->orderByDesc('m.id')
            ->first(['m.id', 'm.content', 'm.tool_results']);

        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row->id,
            'content' => (string) $row->content,
            'pending_actions' => $this->pendingActionCards(),
            'display_blocks' => DisplayBlocks::collect(
                $row->tool_results === null ? null : (string) $row->tool_results,
            ),
        ];
    }

    /**
     * Still-pending (and not-yet-expired) proposal cards for this conversation,
     * shaped exactly as the live `.tool_result` payload the client renders. Used
     * only for reconciliation, so the conversation ownership is already verified
     * by latestAssistantMessage()'s scoped query before this runs.
     *
     * @return list<array<string, mixed>>
     */
    private function pendingActionCards(): array
    {
        $actions = PendingAction::query()
            ->where('conversation_id', $this->conversationId)
            ->where('status', PendingActionStatus::Pending)
            ->where('expires_at', '>', now())
            ->oldest()
            ->get();

        return array_values(array_map(static fn (PendingAction $action): array => [
            'type' => 'pending_action',
            'pending_action_id' => (string) $action->getKey(),
            'operation' => $action->operation->value,
            'entity_type' => $action->entity_type,
            'data' => $action->action_data,
            'display' => $action->display_data,
            'status' => 'pending',
        ], $actions->all()));
    }

    /**
     * The conversation's current (possibly auto-generated) title, used by the
     * client to sync the Filament page header and tab title after a turn ends
     * without requiring a full page reload.
     *
     * On a brand-new chat the conversation is created client-side via a fetch,
     * so the server-side $conversationId stays null until a reload — the client
     * therefore passes its own id, scoped to the authed user and team by
     * FindConversation.
     */
    public function conversationTitle(?string $conversationId = null): ?string
    {
        $conversationId ??= $this->conversationId;

        if ($conversationId === null) {
            return null;
        }

        $title = resolve(FindConversation::class)->execute($this->authUser(), $conversationId)?->title;

        if (! is_string($title) || trim($title) === '') {
            return null;
        }

        return TitleSanitizer::clean($title);
    }

    public function render(): View
    {
        return view('chat::livewire.chat.chat-interface');
    }
}
