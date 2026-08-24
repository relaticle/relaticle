// Reverb event routing, invocation-bound bubble logic, the inactivity
// watchdog, and DB reconcile. Spread into the `chatInterface` Alpine
// component alongside transcriptModule() and sendModule(): see
// chat-interface.blade.php for the composition and the `pendingLabel`
// accessor that stays inline there because a `get` property cannot survive
// an object spread.
export const streamModule = ({ texts = {} } = {}) => ({
    channel: null,
    // Per-instance view of the shared (memoised) Echo channel. Never written onto the
    // channel object itself: siblings on the same conversation share one.
    channelName: null,
    channelConversationId: null,
    channelReady: null,
    channelSubscribed: false,
    streamTimeoutId: null,
    // Injected UI copy (chat-interface.blade.php passes @js(__()) values, the
    // same pattern voice.js uses); the defaults keep the module standalone.
    streamTexts: {
        runningTool: 'Running tool…',
        readingSummary: 'Reading CRM summary…',
        searchingCrm: 'Searching CRM…',
        runningName: 'Running :name…',
        searchingEntity: 'Searching :entity…',
        lookingUpEntity: 'Looking up :entity…',
        draftingEntity: 'Drafting :entity…',
        updatingEntity: 'Preparing :entity changes…',
        deletingEntity: 'Preparing :entity deletion…',
        streamError: 'The assistant encountered an error. Please try again.',
        timeout: 'The assistant took too long to respond.',
        retrying: 'Provider is busy, retrying (attempt :attempt of :max)…',
        ...texts,
    },
    // Set as the FIRST line of destroy() in chat-interface.blade.php. unsubscribe()
    // (window.Echo.leave) stops NEW events from being delivered but cannot cancel a
    // handler already mid-execution. handleStreamEnd/handleStreamFailed/the watchdog
    // in this file, and flushQueuedSend() in send.js, all reach localEditor() after
    // an await or a deferred $nextTick, and localEditor() resolves by context name,
    // not by instance, so a continuation surviving a wire:navigate switch would
    // otherwise write into the NEW live instance mounted under the same context.
    // Checked after every await, and as the first line of every deferred callback
    // scheduled after one, before touching the editor or persisted state.
    destroyed: false,
    // Inactivity watchdog for a lost `stream_end` (dropped Reverb frame): after this
    // long with no stream event, reconcile the turn from the DB. It MUST exceed the
    // server-side ProcessChatMessage #[Timeout(120)], a slow self-hosted model
    // (Ollama/qwen3-class "thinking" models emit no client-visible delta during their
    // reasoning phase) otherwise trips it mid-turn and shows a false "took too long"
    // for a reply that is still generating server-side and does persist.
    streamTimeoutMs: 125000,
    currentToolStatus: null,

    // Single source of truth for the assistant-bubble shape. Every streamed
    // turn renders into a stub minted here; invocationId binds the bubble to
    // one laravel/ai stream() call (fresh uuid per job attempt), which is what
    // prevents retry re-streams and later turns from appending into it.
    mintAssistantStub(extra = {}) {
        const stub = {
            role: 'assistant',
            content: '',
            pending_actions: [],
            // Read-tool tables/cards. Never broadcast (Reverb's 10 KB frame cap),
            // so this stays empty until reconcileLatestAssistant() fills it from
            // the persisted tool_results at stream end.
            display_blocks: [],
            paywall: null,
            sessionExpired: false,
            rendered: false,
            prerendered: false,
            follow_ups: [],
            created_at: new Date().toISOString(),
            invocationId: null,
            streamError: null,
            retryable: false,
            _needsSeparator: false,
            feedback: null,
            feedbackPanelOpen: false,
            feedbackCategory: null,
            feedbackComment: '',
            ...extra,
        };
        this.ensureClientKey(stub);
        this.messages.push(stub);
        // Re-read from the reactive array rather than returning the pre-push
        // object literal directly: Alpine (like Vue3 reactivity) only tracks
        // dependents through property access on the wrapped proxy handed back
        // from an array read. Callers such as targetBubbleFor()'s resume
        // fallback hand this return value straight to handleTextDelta, which
        // mutates `.content` on it. Mutating the raw object bypasses the
        // proxy's set trap, so an already-mounted x-text effect never
        // re-renders. Same fix as send.js's optimistic user bubble.
        return this.messages[this.messages.length - 1];
    },

    lastAssistantBubble() {
        return this.messages.findLast((m) => m.role === 'assistant') ?? null;
    },

    // Resolve which bubble a stream event belongs to.
    //  - exact invocation match anywhere -> that bubble (trailing deltas of a
    //    still-open turn keep landing in THEIR bubble even after a continuation
    //    stub was minted after it)
    //  - unbound in-flight stub -> bind it (first event of the turn)
    //  - different invocation on an UNRENDERED bubble -> the job retried and is
    //    re-streaming from the top: reset the partial state, rebind (de-dupes)
    //  - otherwise (last bubble already rendered) -> a turn we never minted a
    //    stub for (e.g. resume) -> mint one bound to this invocation
    targetBubbleFor(invocationId) {
        if (invocationId !== null) {
            const bound = this.messages.findLast((m) => m.role === 'assistant' && m.invocationId === invocationId);
            if (bound) return bound;
        }
        const b = this.lastAssistantBubble();
        if (b && !b.rendered) {
            if (b.invocationId == null) {
                b.invocationId = invocationId;
                return b;
            }
            b.invocationId = invocationId;
            b.content = '';
            b._needsSeparator = false;
            b.pending_actions = [];
            b.display_blocks = [];
            b.paywall = null;
            b.streamError = null;
            return b;
        }
        return this.mintAssistantStub({ invocationId });
    },

    unsubscribe() {
        if (!this.channel || !window.Echo) return;

        // laravel-echo memoises channels by name, so the full-page and side-panel
        // instances on the same conversation hold ONE object. A bare Echo.leave() here
        // therefore killed the sibling's subscription too, and its own this.channel
        // stayed non-null, so the early return below made its next subscribe a no-op
        // and the turn streamed nothing until the watchdog fired. Refcount the name and
        // only leave on the last release.
        const refs = (window.__chatChannelRefs ??= new Map());
        const name = this.channelName;
        const next = (refs.get(name) ?? 1) - 1;

        if (next <= 0) {
            refs.delete(name);
            window.Echo.leave(name);
        } else {
            refs.set(name, next);
        }

        this.channel = null;
        this.channelName = null;
        this.channelConversationId = null;
        this.channelReady = null;
        this.channelSubscribed = false;
    },

    subscribeToConversation(conversationId) {
        if (!window.Echo) return Promise.resolve();
        // Read from the component, not the shared channel object: two instances writing
        // their own name/conversationId/subscribed onto one memoised channel clobbered
        // each other's view of what they were subscribed to.
        if (this.channel && this.channelConversationId === conversationId) {
            return this.channelSubscribed ? Promise.resolve() : (this.channelReady || Promise.resolve());
        }

        this.unsubscribe();

        const channelName = `chat.conversation.${conversationId}`;
        const refs = (window.__chatChannelRefs ??= new Map());
        refs.set(channelName, (refs.get(channelName) ?? 0) + 1);

        this.channel = window.Echo.private(channelName);
        this.channelName = channelName;
        this.channelConversationId = conversationId;
        this.channelSubscribed = false;

        const readyPromise = new Promise((resolve) => {
            const pusherChannel = this.channel.subscription ?? this.channel;
            let settled = false;
            const finish = (confirmed) => {
                if (settled) return;
                settled = true;
                this.channelSubscribed = confirmed;
                resolve(confirmed);
            };
            if (typeof pusherChannel.bind === 'function') {
                pusherChannel.bind('pusher:subscription_succeeded', () => finish(true));
                pusherChannel.bind('pusher:subscription_error', () => finish(false));
                // Bounded fallback: proceed unconfirmed after 8s, but stream_end
                // reconciliation (handleStreamEnd) guarantees the final message is
                // correct even if early deltas were missed.
                setTimeout(() => finish(false), 8000);
            } else {
                finish(true);
            }
        });

        this.channelReady = readyPromise;

        this.channel
            .listen('.stream_start', (e) => this.handleStreamStart(e))
            .listen('.text_delta', (e) => this.handleTextDelta(e))
            .listen('.tool_call', (e) => this.handleToolCall(e))
            .listen('.tool_result', (e) => this.handleToolResult(e))
            .listen('.stream_end', (e) => this.handleStreamEnd(e))
            .listen('.stream.failed', (e) => this.handleStreamFailed(e))
            .listen('.stream.retrying', (e) => this.handleStreamRetrying(e))
            .listen('.conversation.resolved', (e) => this.handleConversationResolved(e))
            .listen('.conversation.title', (e) => this.handleConversationTitle(e))
            .listen('.follow_ups', (e) => this.handleFollowUps(e))
            .listen('.pending_actions_superseded', (e) => this.handlePendingActionsSuperseded(e))
            .listen('.pending_action.resolved', (e) => this.handlePendingActionResolved(e));

        return readyPromise;
    },

    handleFollowUps(event) {
        if (!this.isForCurrentConversation()) return;

        const chips = Array.isArray(event?.chips) ? event.chips.slice(0, 3) : [];
        // Chips belong to the turn that just COMPLETED. If a queued send
        // already minted a fresh stub, the last assistant bubble is the wrong
        // (unstarted) one, attach to the last rendered bubble instead.
        const target = this.messages.findLast((m) => m.role === 'assistant' && m.rendered) ?? this.lastAssistantBubble();
        if (target) target.follow_ups = chips;
    },

    // Server marked pending actions as superseded (user sent a new message without
    // acting on them). Update the local cards by id so the UI reflects state even
    // if our optimistic mark missed something.
    handlePendingActionsSuperseded(event) {
        const ids = Array.isArray(event?.ids) ? new Set(event.ids) : null;
        if (!ids || ids.size === 0) return;
        this.markPendingActionsSuperseded(ids);
    },

    // F1: a proposal was approved/rejected, possibly in a DIFFERENT tab, or
    // via approveItem()/rejectItem() for one item of a batch, via the
    // `.pending_action.resolved` broadcast (see PendingActionService::
    // broadcastResolution). Routed through the same applyProposalResolution()
    // bridge the resolving tab's own Livewire dispatch uses, so both channels
    // converge on identical reconcile logic and its idempotency guards (see
    // the comment on applyProposalResolution in transcript.js).
    handlePendingActionResolved(event) {
        if (!event?.pending_action_id) return;
        this.applyProposalResolution({
            pendingActionId: event.pending_action_id,
            index: event.index ?? null,
            decision: event.status === 'approved' ? 'approved' : 'rejected',
            finalized: !!event.finalized,
            record: null,
        });
    },

    friendlyToolStatus(toolName) {
        if (!toolName) return this.streamTexts.runningTool;
        const normalized = String(toolName)
            .replace(/Tool$/, '')
            .replace(/([a-z])([A-Z])/g, '$1_$2')
            .replace(/([A-Z]+)([A-Z][a-z])/g, '$1_$2')
            .toLowerCase();

        if (normalized === 'get_crm_summary') return this.streamTexts.readingSummary;
        if (normalized === 'search_crm') return this.streamTexts.searchingCrm;

        const m = normalized.match(/^(list|get|create|update|delete)_(.+)$/);
        if (!m) return this.streamTexts.runningName.replace(':name', normalized);

        const [, op, rest] = m;
        const entity = rest.replace(/_/g, ' ');

        const template = ({
            list: this.streamTexts.searchingEntity,
            get: this.streamTexts.lookingUpEntity,
            create: this.streamTexts.draftingEntity,
            update: this.streamTexts.updatingEntity,
            delete: this.streamTexts.deletingEntity,
        })[op];

        return template.replace(':entity', entity);
    },

    startStreamTimeout(timeoutMs = null) {
        this.clearStreamTimeout();
        this.streamTimeoutId = setTimeout(async () => {
            if (!this.isStreaming) return;
            // A lost stream_end stranded this turn: reconcile from the DB so the
            // final text AND any proposal card self-heal instead of showing a
            // truncated bubble with a missing approve/reject CTA until reload.
            const assistantMsg = this.lastAssistantBubble();
            await this.reconcileLatestAssistant(assistantMsg);
            // Same torn-down-instance hazard as handleStreamEnd (see the `destroyed`
            // comment above): this continuation reaches restoreInputFocus() below,
            // which would steal focus into whatever NEW instance is now live under
            // this same context if a wire:navigate landed during the await above.
            if (this.destroyed) return;
            if (assistantMsg?.role === 'assistant') {
                if (!assistantMsg.content) {
                    assistantMsg.streamError = this.streamTexts.timeout;
                    assistantMsg.retryable = true;
                }
                assistantMsg.rendered = true;
                assistantMsg.prerendered = false;
            }
            this.currentToolStatus = null;
            this.isStreaming = false;
            this.restoreInputFocus();
        }, timeoutMs ?? this.streamTimeoutMs);
    },

    clearStreamTimeout() {
        if (this.streamTimeoutId) {
            clearTimeout(this.streamTimeoutId);
            this.streamTimeoutId = null;
        }
    },

    /**
     * Whether events arriving on the subscribed channel still belong to the
     * conversation on screen.
     *
     * switchConversation() repaints the transcript from cache and swaps
     * conversationId without touching the Echo channel, so between the click and
     * the wire:navigate remount this instance is showing B while still listening
     * to A. A turn that finishes for A in that window would write A's tokens into
     * B's transcript. Resuming after an approval is exactly the case that starts
     * a stream this tab never sent, so the window is reachable rather than
     * theoretical.
     *
     * channelConversationId is what the subscription is actually for; the event
     * payloads themselves carry no conversation id. Before the first subscribe,
     * or before this component knows its own id (a brand new conversation streams
     * before the id lands), there is nothing to disagree with and the event is
     * ours.
     */
    isForCurrentConversation() {
        if (!this.channelConversationId || !this.conversationId) return true;

        return this.channelConversationId === this.conversationId;
    },

    handleStreamStart(event) {
        if (!this.isForCurrentConversation()) return;

        this.startStreamTimeout();
        // A turn this tab did not send still has to look like a turn: an
        // approval resumes the assistant server-side (TurnContinuationService),
        // and without this the user watches a silent, idle-looking composer
        // until the first delta lands. Already true for a turn we sent, so this
        // only ever flips for a resumed one. Cleared by stream_end, stream
        // failure, and the watchdog, exactly as a sent turn is.
        this.isStreaming = true;
        this.targetBubbleFor(event.invocation_id ?? null);
    },

    handleTextDelta(event) {
        if (!this.isForCurrentConversation()) return;
        this.startStreamTimeout();
        this.currentToolStatus = null;
        const assistantMsg = this.targetBubbleFor(event.invocation_id ?? null);
        let delta = event.delta || '';

        if (assistantMsg._needsSeparator && delta && !/^\s/.test(delta)) {
            delta = ' ' + delta;
            assistantMsg._needsSeparator = false;
        }

        assistantMsg.content += delta;
        this.scrollToBottom();
    },

    handleToolCall(event) {
        if (!this.isForCurrentConversation()) return;
        this.startStreamTimeout();
        this.currentToolStatus = this.friendlyToolStatus(event?.tool_name);
        const assistantMsg = this.targetBubbleFor(event.invocation_id ?? null);
        if (assistantMsg.content && !/\s$/.test(assistantMsg.content)) {
            assistantMsg._needsSeparator = true;
        }
        this.scrollToBottom();
    },

    handleToolResult(event) {
        if (!this.isForCurrentConversation()) return;
        this.startStreamTimeout();
        this.currentToolStatus = null;
        const assistantMsg = this.targetBubbleFor(event.invocation_id ?? null);
        if (assistantMsg.content && !/\s$/.test(assistantMsg.content)) {
            assistantMsg._needsSeparator = true;
        }
        if (!event.result) return;
        try {
            const result = typeof event.result === 'string' ? JSON.parse(event.result) : event.result;
            if (result.type !== 'pending_action') return;
            // A retried job re-emits the same proposal (server collapses it to the
            // same id), rendering it twice would show two identical cards.
            const seen = this.messages.some((m) =>
                (m.pending_actions || []).some((a) => a.pending_action_id === result.pending_action_id));
            if (seen) return;
            result.status = 'pending';
            assistantMsg.pending_actions.push(result);
            this.scrollToBottom();
        } catch { /* not pending action JSON */ }
    },

    // Reconcile a bubble against persisted state: pull the authoritative text
    // (missed deltas), merge any still-pending proposal cards the client never
    // received, and attach the read-tool display blocks. Targets the bubble
    // whose stream just ended when known; falls back to the last assistant
    // bubble (watchdog path).
    async reconcileLatestAssistant(target = null) {
        const assistantMsg = target ?? this.lastAssistantBubble();
        if (assistantMsg?.role !== 'assistant') return;
        try {
            // Pass our own id: on the first turn of a new chat the conversation
            // was created by send.js's fetch, so the server component's
            // $conversationId is still null and it would hand back nothing.
            const authoritative = await this.$wire.latestAssistantMessage(this.conversationId);
            if (!authoritative) return;
            // Capture the persisted id even when the text already matches,
            // feedback (thumbs) and supersede anchoring need it.
            if (authoritative.id && !assistantMsg.id) {
                assistantMsg.id = authoritative.id;
            }
            const isUnstartedStub = assistantMsg.invocationId == null && !assistantMsg.content && !assistantMsg.rendered;
            if (authoritative.content && authoritative.content !== assistantMsg.content && !isUnstartedStub) {
                assistantMsg.content = authoritative.content;
                assistantMsg.id = authoritative.id;
                assistantMsg.rendered = false;
                assistantMsg.prerendered = false;
            }
            if (!Array.isArray(assistantMsg.pending_actions)) assistantMsg.pending_actions = [];
            // Span ALL bubbles: a card already rendered in an earlier bubble must
            // not be merged again into this one (it would show twice).
            const have = new Set(this.messages.flatMap((m) =>
                (m.pending_actions || []).map((a) => a.pending_action_id)));
            for (const card of (authoritative.pending_actions || [])) {
                if (!have.has(card.pending_action_id)) assistantMsg.pending_actions.push(card);
            }
            // Replaced, not merged: the server derives the full set from this
            // message's own persisted tool_results, so it is already complete
            // and a second reconcile of the same turn must not double it.
            if (Array.isArray(authoritative.display_blocks)) {
                assistantMsg.display_blocks = authoritative.display_blocks;
            }
        } catch (e) {
            // Non-fatal: keep the streamed content if reconciliation fails.
        }
    },

    async handleStreamEnd(event) {
        if (!this.isForCurrentConversation()) return;
        this.currentToolStatus = null;
        const inv = event?.invocation_id ?? null;
        let assistantMsg = inv === null
            ? null
            : this.messages.findLast((m) => m.role === 'assistant' && m.invocationId === inv) ?? null;
        if (!assistantMsg) {
            assistantMsg = this.lastAssistantBubble();
            // Never finalize an unstarted continuation stub minted AFTER the
            // ended stream, the ended turn is the assistant bubble before it.
            if (assistantMsg && assistantMsg.invocationId == null && !assistantMsg.content && !assistantMsg.rendered) {
                const idx = this.messages.indexOf(assistantMsg);
                assistantMsg = this.messages.slice(0, idx).findLast((m) => m.role === 'assistant') ?? assistantMsg;
            }
        }
        await this.reconcileLatestAssistant(assistantMsg);
        // This instance was torn down (wire:navigate to another conversation)
        // while the await above was pending. unsubscribe() already stopped new
        // events, but this continuation was already in flight, so bail before
        // touching localEditor() (flushQueuedSend) or stealing focus, either of
        // which would resolve the NEW live instance under the same context.
        if (this.destroyed) return;
        if (assistantMsg?.role === 'assistant') {
            assistantMsg.rendered = true;
            assistantMsg.prerendered = false;
        }
        // A completed turn means the conversation recovered, failure banners on
        // earlier bubbles describe a state that no longer exists (and reload
        // would drop them anyway, since failed turns are never persisted).
        this.messages.forEach((m) => {
            if (m.role === 'assistant' && m.streamError) {
                m.streamError = null;
                m.retryable = false;
            }
        });
        this.isStreaming = false;
        this.clearStreamTimeout();
        this.scrollToBottom();
        this.restoreInputFocus();
        this.flushQueuedSend();
        this.maybeSyncTitle();
    },

    // Pull fallback for the `.conversation.title` push. On a brand-new chat the
    // Filament page header (H1 + tab title) was rendered at mount and still
    // reads "New chat"; if the broadcast was dropped, or landed before this
    // client finished subscribing, the header would stay generic until reload.
    // Still reading "New chat" at turn end means no title ever arrived, so a
    // pull here can never clobber a rename the user typed mid-turn.
    async maybeSyncTitle() {
        if (!this.conversationId) return;
        if (!document.title.startsWith('New chat')) return;
        try {
            const title = await this.$wire.conversationTitle(this.conversationId);
            if (title) {
                this.applyTitle(this.conversationId, title);
            }
        } catch (_) { /* non-fatal: header just stays generic until reload */ }
    },

    handleStreamFailed(event) {
        this.currentToolStatus = null;
        // Prefer the bubble that is actually mid-stream (unrendered). The last
        // bubble can be a fresh continuation stub minted after the failing
        // turn, painting the error there would mislabel a different turn.
        const b = this.messages.findLast((m) => m.role === 'assistant' && !m.rendered) ?? this.lastAssistantBubble();
        if (b && !b.rendered) {
            b.content = '';
            b.invocationId = null;
            b.streamError = event?.message || this.streamTexts.streamError;
            b.retryable = true;
            b.rendered = true;
            b.prerendered = false;
        }
        this.isStreaming = false;
        const queued = this.queuedSend;
        this.queuedSend = null;
        if (queued) {
            // Guarded INSIDE the callback, not before scheduling it: destroy()
            // can still run during the tick this is deferred to (same
            // torn-down-instance hazard as handleStreamEnd, but this handler has
            // no internal await of its own, so the $nextTick is the only gap).
            this.$nextTick(() => {
                if (this.destroyed) return;
                this.localEditor()?.setDocument?.(this.plainDocument(queued.document));
            });
        }
        this.clearStreamTimeout();
        this.restoreInputFocus();
    },

    // The job hit a provider 429/529 and will re-stream this turn from the top
    // after `delaySeconds`. Pre-clear the partial text (the re-stream replays it)
    // and tell the user what's happening instead of going silent.
    // Ghost-guard: if there is no unrendered bubble and we are not streaming, this
    // event is a stale broadcast from a previous turn, ignore it entirely.
    handleStreamRetrying(event) {
        // When an invocation_id is present, target the bubble for that specific
        // invocation (handles approve-mid-stream where the last bubble may be a
        // freshly-minted continuation stub, not the one that's retrying).
        // Fall back to lastAssistantBubble() when no id is available.
        let b = event?.invocation_id
            ? this.messages.findLast((m) => m.role === 'assistant' && m.invocationId === event.invocation_id) ?? null
            : null;
        if (!b) {
            b = this.lastAssistantBubble();
        }
        if ((!b || b.rendered) && !this.isStreaming) return;
        if (b && !b.rendered) {
            b.content = '';
            // Do NOT null invocationId, the re-stream reuses the same invocation.
            b._needsSeparator = false;
        }
        this.isStreaming = true;
        this.currentToolStatus = this.streamTexts.retrying
            .replace(':attempt', String(event?.attempt ?? '?'))
            .replace(':max', String(event?.maxAttempts ?? 5));
        this.startStreamTimeout(((event?.delaySeconds ?? 0) * 1000) + this.streamTimeoutMs);
    },

    handleConversationResolved(event) {
        if (!event?.conversationId) return;
        if (!this.conversationId) {
            this.conversationId = event.conversationId;
        }
    },

    // The conversation was auto-titled mid-turn. Reuse the exact path a manual
    // rename takes: the window event updates the H1 + browser tab, the Livewire
    // event refreshes the sidebar and the all-chats panel.
    handleConversationTitle(event) {
        const title = event?.title;
        if (!event?.conversationId || !title) return;
        if (!this.isForCurrentConversation()) return;

        this.applyTitle(event.conversationId, title);
    },

    applyTitle(conversationId, title) {
        window.dispatchEvent(new CustomEvent('chat:renamed', {
            detail: { conversationId, title },
        }));

        if (window.Livewire?.dispatch) {
            window.Livewire.dispatch('chat:conversation-renamed', { conversationId, title });
            // The sidebar's chat list is a Livewire component nested inside
            // Filament's sidebar, and a nested component's own re-render is not
            // painted -- the server renders it, the DOM keeps the old rows. Only
            // the parent repaints it, so ask Filament for that through the hook
            // it provides. Without this the sidebar row keeps the raw first
            // message until the next page load, while the heading and tab title
            // (plain DOM writes) already show the generated one.
            window.Livewire.dispatch('refresh-sidebar');
        }
    },
});
