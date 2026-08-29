<?php

declare(strict_types=1);

namespace Relaticle\Chat\Livewire\Chat;

use App\Livewire\BaseLivewireComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Renderless;
use Relaticle\Chat\Actions\FindConversation;
use Relaticle\Chat\Actions\ListConversationMessages;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Support\DisplayBlocks;
use Relaticle\Chat\Support\NextSteps;
use Relaticle\Chat\Support\RecordReferenceResolver;
use Relaticle\Chat\Support\TitleSanitizer;
use Relaticle\Chat\Support\TranscriptScope;
use Relaticle\Chat\Support\TurnPresence;

final class ChatInterface extends BaseLivewireComponent
{
    public ?string $conversationId = null;

    public ?string $initialMessage = null;

    /**
     * Text placed in the composer on arrival and left there. Unlike
     * `initialMessage`, which sends itself, this waits for the user.
     */
    public ?string $initialPrompt = null;

    public ?string $initialModel = null;

    public ?string $oldestMessageId = null;

    public bool $hasMoreMessages = false;

    /**
     * A turn is running (or queued) for this conversation right now. Nothing
     * about it is persisted until its stream settles, so without this flag a
     * reload mid-turn renders an idle-looking page: the client uses it to show
     * the streaming state and arm the watchdog from the first paint.
     */
    public bool $turnInFlight = false;

    public string $context = 'conversation';

    public ?string $pageContextType = null;

    public ?string $pageContextId = null;

    public ?string $pageContextLabel = null;

    private const int PAGE_SIZE = 50;

    /**
     * The composer refuses anything longer, so a crafted link cannot paste an
     * essay into somebody's editor.
     */
    private const int MAX_PROMPT_LENGTH = 5000;

    /**
     * @var array<int, array{id?: string, role: string, content: string, created_at?: ?string, document?: array<string, mixed>, pending_actions?: array<int, mixed>, display_blocks?: list<array<string, mixed>>, next_steps?: list<array{label: string, prompt: string}>, feedback?: array{rating: string, category: ?string}|null, mentions?: list<array{type: string, id: string, label: string, url?: ?string}>, page_context?: array{type: string, id: string, label: string, url?: ?string}|null}>
     */
    public array $messages = [];

    public function mount(?string $conversationId = null, ?string $initialMessage = null, string $context = 'conversation', ?string $initialModel = null, ?string $pageContextType = null, ?string $pageContextId = null, ?string $pageContextLabel = null): void
    {
        $this->conversationId = $conversationId;
        $this->context = $context;
        $this->pageContextType = $pageContextType;
        $this->pageContextId = $pageContextId;
        $this->pageContextLabel = $pageContextLabel;

        /** @var string|null $promptQuery */
        $promptQuery = request()->query('prompt');
        $this->initialMessage = $initialMessage;

        // `?prompt=` seeds the composer and stops. It used to feed
        // initialMessage, which sends on arrival: any link, from anywhere,
        // could spend a workspace credit before its owner had read what was
        // typed. Seeding is what every caller actually wanted.
        $this->initialPrompt = is_string($promptQuery) && trim($promptQuery) !== ''
            ? Str::limit(trim($promptQuery), self::MAX_PROMPT_LENGTH, '')
            : null;

        /** @var string|null $modelQuery */
        $modelQuery = request()->query('model');
        $this->initialModel = $initialModel ?? $modelQuery;

        if ($this->conversationId !== null) {
            $this->messages = resolve(ListConversationMessages::class)->execute(
                $this->authUser(),
                $this->conversationId,
            );
            $this->oldestMessageId = $this->messages === [] ? null : ($this->messages[0]['id'] ?? null);
            $this->hasMoreMessages = count($this->messages) === self::PAGE_SIZE;
            $this->appendInFlightTurnState($this->conversationId);
        }
    }

    /**
     * Restore what a reload would otherwise lose about a turn that has not
     * persisted yet: the user's own just-sent message (nothing reaches the
     * database before the stream settles) and any proposals the turn already
     * created. Without this, refreshing mid-turn shows an idle page with the
     * sent message gone and 25 pending proposals invisible until the next
     * broadcast happens to arrive.
     */
    private function appendInFlightTurnState(string $conversationId): void
    {
        // $conversationId is a route parameter, so it names any conversation the
        // caller cares to type. Neither source below is scoped on its own: the
        // marker is keyed by conversation id alone, and the cards query trusted
        // its previous caller to have verified ownership first. An empty
        // transcript is not that verification, it is what a foreign id also
        // returns.
        if (resolve(FindConversation::class)->execute($this->authUser(), $conversationId) === null) {
            return;
        }

        $presence = TurnPresence::current($conversationId);

        if ($presence !== null && ! $this->turnAlreadyPersisted($presence['started_at'])) {
            $this->turnInFlight = true;

            if ($presence['kind'] === 'message') {
                $this->messages[] = $this->inFlightUserMessage($presence);
            }
        }

        // Same merge stream_end reconciliation does, at mount: a still-pending
        // proposal whose carrying assistant message is not persisted yet (the
        // turn is mid-stream) must still dock, or the reloaded page hides a
        // decision that other tabs are already showing.
        $missing = $this->missingPendingCards($conversationId);

        if ($missing !== []) {
            $this->messages[] = [
                'role' => 'assistant',
                'content' => '',
                'created_at' => now()->toISOString(),
                'pending_actions' => $missing,
                'display_blocks' => [],
                'next_steps' => [],
                'feedback' => null,
                'mentions' => [],
                'page_context' => null,
            ];
        }
    }

    /**
     * The marker outlives persistence for a moment (it is cleared after the
     * rows are written), so a user message stored at or after the turn began
     * means the reload can rebuild everything from the database already.
     */
    private function turnAlreadyPersisted(string $startedAt): bool
    {
        $started = Date::parse($startedAt);

        foreach ($this->messages as $message) {
            if ($message['role'] !== 'user') {
                continue;
            }

            $createdAt = $message['created_at'] ?? null;

            if (is_string($createdAt) && Date::parse($createdAt)->gte($started)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The in-flight user message, shaped like a ListConversationMessages row so
     * the client renders it exactly as the persisted one will after the turn.
     *
     * @param  array{kind: string, message: string, document: array<string, mixed>, mentions: list<array{type: string, id: string, label: string}>, page_context: array{type: string, id: string, label: string}|null, started_at: string}  $presence
     * @return array{role: string, content: string, created_at: string, document: array<string, mixed>, pending_actions: array<int, mixed>, display_blocks: list<array<string, mixed>>, next_steps: list<array{label: string, prompt: string}>, feedback: null, mentions: list<array{type: string, id: string, label: string, url: ?string}>, page_context: array{type: string, id: string, label: string, url: ?string}|null}
     */
    private function inFlightUserMessage(array $presence): array
    {
        $resolver = resolve(RecordReferenceResolver::class);

        $pageContext = $presence['page_context'];

        return [
            'role' => 'user',
            'content' => $presence['message'],
            'document' => $presence['document'],
            'created_at' => $presence['started_at'],
            'pending_actions' => [],
            'display_blocks' => [],
            'next_steps' => [],
            'feedback' => null,
            'mentions' => array_map(static fn (array $mention): array => [
                ...$mention,
                'url' => $resolver->urlFor($mention['type'], $mention['id']),
            ], $presence['mentions']),
            'page_context' => $pageContext === null ? null : [
                ...$pageContext,
                'url' => $resolver->urlFor($pageContext['type'], $pageContext['id']),
            ],
        ];
    }

    /**
     * Still-pending proposal cards not carried by any loaded message.
     *
     * @return list<array<string, mixed>>
     */
    private function missingPendingCards(string $conversationId): array
    {
        $known = [];

        foreach ($this->messages as $message) {
            foreach ($message['pending_actions'] ?? [] as $action) {
                if (is_array($action) && isset($action['pending_action_id'])) {
                    $known[(string) $action['pending_action_id']] = true;
                }
            }
        }

        return array_values(array_filter(
            $this->pendingActionCards($conversationId),
            static fn (array $card): bool => ! isset($known[(string) $card['pending_action_id']]),
        ));
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
     * The client passes its own id for the same reason conversationTitle() takes
     * one: on the FIRST turn of a new chat the conversation is created by the
     * client's fetch, so $conversationId is still null here and reconcile would
     * hand back nothing, leaving the turn's tables missing until a reload. The
     * query below is scoped to the authed participant and team, so an id from
     * the client cannot reach another user's conversation.
     *
     * @return array{id: string, content: string, pending_actions: list<array<string, mixed>>, display_blocks: list<array<string, mixed>>}|null
     */
    public function latestAssistantMessage(?string $conversationId = null): ?array
    {
        $conversationId ??= $this->conversationId;

        if ($conversationId === null) {
            return null;
        }

        $user = $this->authUser();

        $row = TranscriptScope::apply(DB::table('agent_conversation_messages as m'), $user, $conversationId)
            ->where('m.role', 'assistant')
            ->latest('m.created_at')
            ->orderByDesc('m.id')
            ->first(['m.id', 'm.content', 'm.tool_results', 'm.meta']);

        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row->id,
            'content' => (string) $row->content,
            'pending_actions' => $this->pendingActionCards($conversationId),
            'display_blocks' => DisplayBlocks::collect(
                $row->tool_results === null ? null : (string) $row->tool_results,
            ),
            'next_steps' => NextSteps::fromMeta($row->meta === null ? null : (string) $row->meta),
        ];
    }

    /**
     * Still-pending (and not-yet-expired) proposal cards for this conversation,
     * shaped exactly as the live `.tool_result` payload the client renders.
     *
     * Scoped to the authed participant in its own right, matching the filter
     * ListConversationMessages applies to the same rows. Every caller does
     * verify the conversation, but leaning on that precondition is how a
     * route-supplied id once reached these payloads.
     *
     * @return list<array<string, mixed>>
     */
    private function pendingActionCards(string $conversationId): array
    {
        $user = $this->authUser();

        $actions = PendingAction::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $user->getKey())
            ->where('team_id', $user->current_team_id)
            ->where('status', PendingActionStatus::Pending)
            ->where('expires_at', '>', now())
            ->oldest()
            ->get();

        return array_values(array_map(static fn (PendingAction $action): array => [
            'type' => 'pending_action',
            'pending_action_id' => (string) $action->getKey(),
            'turn_id' => $action->turn_id,
            'operation' => $action->operation->value,
            'entity_type' => $action->entity_type,
            'data' => $action->action_data,
            'display' => $action->display_data,
            'status' => 'pending',
            // The client hides the composer while a proposal is pending, so it has
            // to know when this one stops being pending on its own. Without the
            // instant, an open tab keeps a lapsed proposal docked forever and the
            // user can never type again (ChatInterface's own query already filters
            // these out, so nothing already-lapsed reaches here).
            'expires_at' => $action->expires_at->toIso8601String(),
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
        return view('chat::livewire.chat.chat-interface', [
            // Lets the docked ProposalCard render WITH the page instead of
            // arriving a Livewire round trip after Alpine boots: the reloaded
            // page used to show dead air where the decision was.
            'initialProposalId' => $this->firstPendingCardId(),
        ]);
    }

    /**
     * The id the dock anchors on at first paint: the earliest still-pending
     * card in transcript order, matching what the client's own
     * syncActiveProposal() will dispatch a moment later.
     */
    private function firstPendingCardId(): ?string
    {
        foreach ($this->messages as $message) {
            foreach ($message['pending_actions'] ?? [] as $action) {
                if (is_array($action) && ($action['status'] ?? null) === 'pending' && isset($action['pending_action_id'])) {
                    return (string) $action['pending_action_id'];
                }
            }
        }

        return null;
    }
}
