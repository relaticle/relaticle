// Reverb event routing, invocation-bound bubble logic, the inactivity
// watchdog, and DB reconcile. Spread into the `chatInterface` Alpine
// component alongside transcriptModule() and sendModule(): see
// chat-interface.blade.php for the composition and the `pendingLabel`
// accessor that stays inline there because a `get` property cannot survive
// an object spread.
export const streamModule = () => ({
    channel: null,
    streamTimeoutId: null,
    // Inactivity watchdog for a lost `stream_end` (dropped Reverb frame): after this
    // long with no stream event, reconcile the turn from the DB. It MUST exceed the
    // server-side ProcessChatMessage #[Timeout(120)] — a slow self-hosted model
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
            paywall: null,
            sessionExpired: false,
            rendered: false,
            prerendered: false,
            copiedAt: 0,
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
        return stub;
    },

    lastAssistantBubble() {
        for (let i = this.messages.length - 1; i >= 0; i--) {
            if (this.messages[i].role === 'assistant') return this.messages[i];
        }
        return null;
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
            for (let i = this.messages.length - 1; i >= 0; i--) {
                const m = this.messages[i];
                if (m.role === 'assistant' && m.invocationId === invocationId) return m;
            }
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
            b.paywall = null;
            b.streamError = null;
            return b;
        }
        return this.mintAssistantStub({ invocationId });
    },

    unsubscribe() {
        if (this.channel && window.Echo) {
            window.Echo.leave(this.channel.name);
            this.channel = null;
        }
    },

    subscribeToConversation(conversationId) {
        if (!window.Echo) return Promise.resolve();
        if (this.channel && this.channel.conversationId === conversationId) {
            return this.channel.subscribed ? Promise.resolve() : (this.channel.readyPromise || Promise.resolve());
        }

        this.unsubscribe();

        const channelName = `chat.conversation.${conversationId}`;
        this.channel = window.Echo.private(channelName);
        this.channel.name = channelName;
        this.channel.conversationId = conversationId;
        this.channel.subscribed = false;

        const readyPromise = new Promise((resolve) => {
            const pusherChannel = this.channel.subscription ?? this.channel;
            let settled = false;
            const finish = (confirmed) => {
                if (settled) return;
                settled = true;
                this.channel.subscribed = confirmed;
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

        this.channel.readyPromise = readyPromise;

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
        const chips = Array.isArray(event?.chips) ? event.chips.slice(0, 3) : [];
        // Chips belong to the turn that just COMPLETED. If a queued send
        // already minted a fresh stub, the last assistant bubble is the wrong
        // (unstarted) one — attach to the last rendered bubble instead.
        for (let i = this.messages.length - 1; i >= 0; i--) {
            const m = this.messages[i];
            if (m.role === 'assistant' && m.rendered) {
                m.follow_ups = chips;
                return;
            }
        }
        const last = this.lastAssistantBubble();
        if (last) last.follow_ups = chips;
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
        if (!toolName) return 'Running tool…';
        const normalized = String(toolName)
            .replace(/Tool$/, '')
            .replace(/([a-z])([A-Z])/g, '$1_$2')
            .replace(/([A-Z]+)([A-Z][a-z])/g, '$1_$2')
            .toLowerCase();

        if (normalized === 'get_crm_summary') return 'Reading CRM summary…';
        if (normalized === 'search_crm') return 'Searching CRM…';

        const m = normalized.match(/^(list|get|create|update|delete)_(.+)$/);
        if (!m) return `Running ${normalized}…`;

        const [, op, rest] = m;
        const entity = rest.replace(/_/g, ' ');

        if (op === 'list') return `Searching ${entity}…`;
        if (op === 'get') return `Looking up ${entity}…`;
        return `Preparing ${op} ${entity} proposal…`;
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
            if (assistantMsg?.role === 'assistant') {
                if (!assistantMsg.content) {
                    assistantMsg.streamError = 'The assistant took too long to respond.';
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

    handleStreamStart(event) {
        this.startStreamTimeout();
        this.targetBubbleFor(event.invocation_id ?? null);
    },

    handleTextDelta(event) {
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
        this.startStreamTimeout();
        this.currentToolStatus = this.friendlyToolStatus(event?.tool_name);
        const assistantMsg = this.targetBubbleFor(event.invocation_id ?? null);
        if (assistantMsg.content && !/\s$/.test(assistantMsg.content)) {
            assistantMsg._needsSeparator = true;
        }
        this.scrollToBottom();
    },

    handleToolResult(event) {
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
            // same id) — rendering it twice would show two identical cards.
            const seen = this.messages.some((m) =>
                (m.pending_actions || []).some((a) => a.pending_action_id === result.pending_action_id));
            if (seen) return;
            result.status = 'pending';
            assistantMsg.pending_actions.push(result);
            this.scrollToBottom();
        } catch { /* not pending action JSON */ }
    },

    // Reconcile a bubble against persisted state: pull the authoritative text
    // (missed deltas) AND merge any still-pending proposal cards the client
    // never received. Targets the bubble whose stream just ended when known;
    // falls back to the last assistant bubble (watchdog path).
    async reconcileLatestAssistant(target = null) {
        const assistantMsg = target ?? this.lastAssistantBubble();
        if (assistantMsg?.role !== 'assistant') return;
        try {
            const authoritative = await this.$wire.latestAssistantMessage();
            if (!authoritative) return;
            // Capture the persisted id even when the text already matches —
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
        } catch (e) {
            // Non-fatal: keep the streamed content if reconciliation fails.
        }
    },

    async handleStreamEnd(event) {
        this.currentToolStatus = null;
        const inv = event?.invocation_id ?? null;
        let assistantMsg = null;
        if (inv !== null) {
            for (let i = this.messages.length - 1; i >= 0; i--) {
                const m = this.messages[i];
                if (m.role === 'assistant' && m.invocationId === inv) { assistantMsg = m; break; }
            }
        }
        if (!assistantMsg) {
            assistantMsg = this.lastAssistantBubble();
            // Never finalize an unstarted continuation stub minted AFTER the
            // ended stream — the ended turn is the assistant bubble before it.
            if (assistantMsg && assistantMsg.invocationId == null && !assistantMsg.content && !assistantMsg.rendered) {
                const idx = this.messages.indexOf(assistantMsg);
                for (let i = idx - 1; i >= 0; i--) {
                    const m = this.messages[i];
                    if (m.role === 'assistant') { assistantMsg = m; break; }
                }
            }
        }
        await this.reconcileLatestAssistant(assistantMsg);
        if (assistantMsg?.role === 'assistant') {
            assistantMsg.rendered = true;
            assistantMsg.prerendered = false;
        }
        // A completed turn means the conversation recovered — failure banners on
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
    // reads "New chat"; if the broadcast was dropped — or landed before this
    // client finished subscribing — the header would stay generic until reload.
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
        // turn — painting the error there would mislabel a different turn.
        let b = null;
        for (let i = this.messages.length - 1; i >= 0; i--) {
            const m = this.messages[i];
            if (m.role === 'assistant' && !m.rendered) { b = m; break; }
        }
        if (!b) b = this.lastAssistantBubble();
        if (b && !b.rendered) {
            b.content = '';
            b.invocationId = null;
            b.streamError = event?.message || 'The assistant encountered an error. Please try again.';
            b.retryable = true;
            b.rendered = true;
            b.prerendered = false;
        }
        this.isStreaming = false;
        const queued = this.queuedSend;
        this.queuedSend = null;
        if (queued) {
            this.$nextTick(() => this.localEditor()?.setDocument?.(this.plainDocument(queued.document)));
        }
        this.clearStreamTimeout();
        this.restoreInputFocus();
    },

    // The job hit a provider 429/529 and will re-stream this turn from the top
    // after `delaySeconds`. Pre-clear the partial text (the re-stream replays it)
    // and tell the user what's happening instead of going silent.
    // Ghost-guard: if there is no unrendered bubble and we are not streaming, this
    // event is a stale broadcast from a previous turn — ignore it entirely.
    handleStreamRetrying(event) {
        // When an invocation_id is present, target the bubble for that specific
        // invocation (handles approve-mid-stream where the last bubble may be a
        // freshly-minted continuation stub, not the one that's retrying).
        // Fall back to lastAssistantBubble() when no id is available.
        let b = null;
        if (event?.invocation_id) {
            for (let i = this.messages.length - 1; i >= 0; i--) {
                const m = this.messages[i];
                if (m.role === 'assistant' && m.invocationId === event.invocation_id) {
                    b = m;
                    break;
                }
            }
        }
        if (!b) {
            b = this.lastAssistantBubble();
        }
        if ((!b || b.rendered) && !this.isStreaming) return;
        if (b && !b.rendered) {
            b.content = '';
            // Do NOT null invocationId — the re-stream reuses the same invocation.
            b._needsSeparator = false;
        }
        this.isStreaming = true;
        this.currentToolStatus = `Provider is busy — retrying (attempt ${event?.attempt ?? '?'} of ${event?.maxAttempts ?? 5})…`;
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
        if (this.conversationId && event.conversationId !== this.conversationId) return;

        this.applyTitle(event.conversationId, title);
    },

    applyTitle(conversationId, title) {
        window.dispatchEvent(new CustomEvent('chat:renamed', {
            detail: { conversationId, title },
        }));

        if (window.Livewire?.dispatch) {
            window.Livewire.dispatch('chat:conversation-renamed', { conversationId, title });
        }
    },
});
