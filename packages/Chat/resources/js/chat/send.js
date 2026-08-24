// sendMessage, retry/resume, rate-limit handling, and the optimistic user
// bubble. Spread into the `chatInterface` Alpine component alongside
// transcriptModule() and streamModule(): see chat-interface.blade.php for
// the composition. `sendUrl` and `createConversationUrl` replace what were
// `@js(...)` Blade calls inline in these method bodies before the move; they
// carry the exact same route values. Conversation-scoped endpoints build on
// `this.conversationsUrl`, set on the composed component by the same blade.
const jsonHeaders = () => ({
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
});

export const sendModule = ({ sendUrl, createConversationUrl, texts = {} }) => ({
    input: '',
    streamAbortController: null,

    // Injected UI copy (chat-interface.blade.php passes @js(__()) values, the
    // same pattern voice.js uses); the defaults keep the module standalone.
    sendTexts: {
        sessionExpired: 'Your session expired. Please sign in again: your message is saved locally.',
        paywallHeading: "You've used all your AI credits",
        paywallReset: 'Your plan resets on :date.',
        paywallTopUp: 'Add credits to keep chatting.',
        requestError: 'Error :status: :text',
        networkError: 'Network error. Please try again.',
        cancelled: 'Cancelled.',
        ...texts,
    },

    // When the user types + sends during an active stream, we stash the
    // message here, clear the editor (so they see their intent was accepted),
    // and auto-flush this on handleStreamEnd / cancel / failure.
    queuedSend: null,

    // Active send-throttle window: {secondsLeft, timerId, userMsg}. While set,
    // the composer is soft-disabled with a countdown and the failed bubble
    // (userMsg, already visible marked failed) auto-resends the moment the
    // window opens. Cancel drops the auto-resend; the failed bubble stays put
    // with its own resend control.
    rateLimit: null,
    rateLimitResendTimer: null,

    // Documents stashed in Alpine state come back as reactive Proxies, and
    // TipTap's setDocument structuredClones its input — Proxies cannot be
    // structuredCloned, so the call throws and silently kills whatever line
    // was next (this lost queued messages AND broke rate-limit auto-send
    // before it was found). Always unwrap to plain JSON first.
    plainDocument(doc) {
        if (!doc) return null;
        try {
            return JSON.parse(JSON.stringify(doc));
        } catch (_) {
            return null;
        }
    },

    draftDebounceTimer: null,

    // A conversation that hasn't been created yet (composer works before the
    // first message creates one) buckets its draft under the literal 'new'
    // segment. There is exactly one composer worth persisting per browser tab
    // in that state (the side panel or the full-page composer, never both
    // meaningfully at once), so a flat key is sufficient.
    draftKey(conversationId) {
        return `chat.draft.${conversationId ?? 'new'}`;
    },

    // Debounced (400ms) persistence of the live editor document. The key is
    // resolved from this.conversationId at the moment the timer actually
    // fires, not when typing started, so a draft typed before the first send
    // naturally migrates from the 'new' bucket to the real conversation id
    // once one exists. An empty editor removes the key rather than writing an
    // empty document: this also makes the explicit clearDraft() call in
    // deliverMessage's success path idempotent with whatever this debounce
    // independently does, regardless of which one lands first.
    saveDraft() {
        clearTimeout(this.draftDebounceTimer);
        this.draftDebounceTimer = setTimeout(() => {
            this.draftDebounceTimer = null;

            const editor = this.localEditor();
            if (!editor) return;

            const key = this.draftKey(this.conversationId);
            if (editor.isEmpty()) {
                try { localStorage.removeItem(key); } catch (_) { /* ignore */ }
                return;
            }

            const document = this.plainDocument(editor.getDocument());
            if (!document) return;

            try {
                localStorage.setItem(key, JSON.stringify({ document, savedAt: Date.now() }));
            } catch (_) { /* quota exceeded or private mode: draft safety is best-effort */ }
        }, 400);
    },

    // Restores a persisted draft into the editor on mount / conversation
    // switch. The document read back from localStorage is already plain JSON
    // (it round-tripped through JSON.stringify going in and JSON.parse coming
    // out), but it is explicitly re-run through plainDocument() anyway rather
    // than relying on that implicitly: this restore path must never be the
    // one that regresses to handing TipTap a live Proxy.
    restoreDraft(conversationId) {
        let raw = null;
        try {
            raw = localStorage.getItem(this.draftKey(conversationId));
        } catch (_) {
            return;
        }
        if (!raw) return;

        let parsed = null;
        try {
            parsed = JSON.parse(raw);
        } catch (_) {
            return;
        }

        const document = this.plainDocument(parsed?.document);
        if (!document) return;

        this.$nextTick(() => {
            // plainDocument() only guards against the Proxy/structuredClone
            // throw; it does not validate ProseMirror schema shape. Drafts
            // are pruned only after 30 days (pruneStaleDrafts()), so a stored
            // document can easily outlive a TipTap/mention schema change
            // across a deploy. setDocument() -> editor.commands.setContent()
            // throws on a schema-invalid document, and an uncaught throw
            // inside $nextTick can drop other callbacks queued in the same
            // Alpine flush (see plainDocument()'s own comment above). Discard
            // the bad draft on failure so it does not throw on every future
            // mount of this conversation.
            try {
                this.localEditor()?.setDocument?.(document);
            } catch (_) {
                this.clearDraft(conversationId);
            }
        });
    },

    clearDraft(conversationId) {
        try {
            localStorage.removeItem(this.draftKey(conversationId));
        } catch (_) { /* ignore */ }
    },

    // A conversation can be deleted from several independent client entry
    // points (side panel menu, sidebar nav, all-chats panel) plus a REST API
    // path with no client to notify at all. Rather than wire a cleanup call
    // into every one of those, prune anything stale on mount instead:
    // bounded leakage (up to 30 days) rather than an exhaustive, fragile hook
    // list that a future deletion path can silently miss.
    pruneStaleDrafts() {
        const cutoffMs = 30 * 24 * 60 * 60 * 1000;
        try {
            const staleKeys = [];
            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                if (!key || !key.startsWith('chat.draft.')) continue;

                let savedAt = 0;
                try {
                    savedAt = JSON.parse(localStorage.getItem(key))?.savedAt ?? 0;
                } catch (_) {
                    staleKeys.push(key);
                    continue;
                }

                if (!savedAt || (Date.now() - savedAt) > cutoffMs) {
                    staleKeys.push(key);
                }
            }
            staleKeys.forEach((key) => localStorage.removeItem(key));
        } catch (_) { /* localStorage unavailable */ }
    },

    async regenerateMessage(index) {
        if (this.isStreaming) return;
        // The composer is throttled team-wide right now: sendMessage() below
        // would bail on its own `if (this.rateLimit) return` guard without
        // pushing anything back, so splicing first would just delete the
        // turn with nothing to replace it (issue #499). Bailing here instead
        // leaves the still-failed bubble and its auto-resend countdown
        // untouched. This is the guard that actually prevents the data loss;
        // the Regenerate button's own `:disabled` (_transcript.blade.php) is
        // UX only, unlike isStreaming, which hides the button via x-show and
        // makes it unreachable by click in the first place.
        if (this.rateLimit) return;

        const userIndex = this.messages.slice(0, index).findLastIndex((m) => m.role === 'user');
        if (userIndex === -1) return;

        const userMsg = this.messages[userIndex];
        if (!(await this.supersedeServerTurns(userMsg))) return;

        const userText = userMsg.content;
        this.messages.splice(userIndex);

        this.input = userText;
        this.localEditor()?.setText(userText);
        this.$nextTick(() => this.sendMessage());
    },

    // Tell the server the turns from this user message onward are replaced.
    // Without this, the local splice is cosmetic: reload resurrects the old
    // turns (with the user row duplicated) and the model keeps them in its
    // history. Persisted messages anchor by id; optimistic ones (no id yet)
    // anchor by content against the latest user row server-side.
    async supersedeServerTurns(userMsg) {
        if (!this.conversationId) return true;
        try {
            const res = await fetch(this.conversationsUrl + '/' + this.conversationId + '/messages/supersede', {
                method: 'POST',
                headers: jsonHeaders(),
                body: JSON.stringify({
                    anchor_id: userMsg.id ?? null,
                    anchor_content: userMsg.id ? null : (userMsg.content || null),
                }),
            });
            return res.ok;
        } catch {
            return false;
        }
    },

    documentFromInput(text) {
        const trimmed = text.trim();
        if (trimmed === '') {
            return { type: 'doc', content: [] };
        }
        return {
            type: 'doc',
            content: [{
                type: 'paragraph',
                content: [{ type: 'text', text: trimmed }],
            }],
        };
    },

    async sendMessage() {
        if (this.hasPendingProposal) return;
        if (this.rateLimit) return;

        const editor = this.localEditor();
        const text = (editor?.getText() ?? this.input).trim();
        if (!text) return;
        if (text.length > 5000) return;

        // If a previous turn is still streaming, queue this message and clear
        // the editor so the user sees their intent was accepted. handleStreamEnd
        // (or cancel / failure) will flush this queue.
        if (this.isStreaming) {
            this.queuedSend = {
                document: this.localEditor()?.getDocument() ?? this.documentFromInput(text),
                model: this.selectedModel,
            };
            this.localEditor()?.clear();
            this.input = '';
            return;
        }

        // Claim the lock SYNCHRONOUSLY so a second tick of sendMessage() bails at the
        // guard above. Any failure path between here and the existing isStreaming=false
        // resets must keep that invariant.
        this.isStreaming = true;

        // Captured BEFORE editor.clear() below and before any await: this is the
        // bucket the draft debounce was writing to while the user was still
        // typing this exact message. deliverMessage() clears it (via
        // clearDraft(), which re-derives the key itself: this must stay a
        // conversationId, not a pre-built key) on success. If we instead
        // resolved this AFTER conversationId is assigned (isFirstMessage sets
        // it mid-flight), a successful first send would clear the wrong
        // ('new') bucket and leave the real one orphaned.
        const draftConversationId = this.conversationId;

        // The user moved on without acting on any prior proposals. Server will
        // confirm via .pending_actions_superseded; we update locally so the
        // approve/reject buttons disappear immediately.
        this.markPendingActionsSuperseded();

        const payload = this.localEditor()?.getDocument() ?? this.documentFromInput(text);
        // Captured once per send: the pill attaches to THIS message only. Both the
        // optimistic bubble below and the request payload further down must read
        // the SAME snapshot — re-reading activePageContext() after the consumed
        // flag flips would silently drop it from the outgoing request.
        const contextForSend = this.activePageContext();
        const nowIso = new Date().toISOString();

        this.messages.push(this.ensureClientKey({
            role: 'user',
            content: text,
            document: payload,
            editing: false,
            editText: '',
            created_at: nowIso,
            page_context: contextForSend,
            sendState: 'sending',
        }));
        this.pageContextConsumed = true;
        this.localEditor()?.clear();
        this.input = '';

        // Re-read from the reactive array rather than mutating the object
        // literal above directly: Alpine (like Vue3 reactivity) only tracks
        // dependents through property access on the wrapped proxy it hands
        // back from an array read. Mutating the pre-push object bypasses
        // that proxy: later reads still see the new value, but the DOM
        // binding is never notified to re-render.
        const userMsg = this.messages[this.messages.length - 1];

        await this.deliverMessage(userMsg, payload, contextForSend, draftConversationId);
    },

    // Re-sends a FAILED optimistic bubble in place: same clientKey, same
    // content, never mints a second bubble. This is the F2 fix: the old
    // 429 handling popped the bubble and a later sendMessage() call minted a
    // fresh one, which could transiently render as a duplicate.
    async resendMessage(msg) {
        if (this.destroyed) return;
        if (this.isStreaming) return;
        if (this.hasPendingProposal) return;
        if (msg.sendState === 'sending') return;

        // Same capture-before-any-async reasoning as sendMessage(): a retry of
        // the very first message can still take the isFirstMessage branch (the
        // original attempt never got a conversationId), so this must be resolved
        // before deliverMessage has a chance to assign one.
        const draftConversationId = this.conversationId;

        // A manual resend overrides any auto-resend countdown still ticking
        // for this bubble.
        this.clearRateLimit();

        msg.sendState = 'sending';
        this.isStreaming = true;
        this.markPendingActionsSuperseded();

        const payload = this.plainDocument(msg.document) ?? this.documentFromInput(msg.content);
        await this.deliverMessage(msg, payload, msg.page_context ?? null, draftConversationId);
    },

    // Shared failure path for a non-OK create/send response. Paints the
    // status-specific state on the latest assistant stub (429 throttle
    // countdown, 401/419 session-expired with a local draft, 402 paywall,
    // generic message otherwise) and marks the user turn failed.
    async failSend(res, userMsg) {
        const body = await res.json().catch(() => ({}));

        // A wire:navigate landing while the request was in flight tears this instance
        // down. localEditor() resolves by data-chat-context, not by instance, so a dead
        // component would steal focus into (and write error text against) the newly
        // mounted one's composer.
        if (this.destroyed) return;

        if (res.status === 429 && body?.error === 'rate_limited') {
            this.handleSendRateLimit(userMsg, body);
            return;
        }

        const assistantMsg = this.messages[this.messages.length - 1];

        if (res.status === 401 || res.status === 419) {
            try { localStorage.setItem('chat:draft', userMsg.content); } catch (_) { /* ignore */ }
            assistantMsg.content = this.sendTexts.sessionExpired;
            assistantMsg.sessionExpired = true;
        } else if (res.status === 402 && body?.error === 'credits_exhausted') {
            const resetLabel = body.reset_at ? new Date(body.reset_at).toLocaleDateString() : null;
            assistantMsg.paywall = {
                heading: this.sendTexts.paywallHeading,
                body: resetLabel ? this.sendTexts.paywallReset.replace(':date', resetLabel) : this.sendTexts.paywallTopUp,
                upgrade_url: body.top_up_url || body.upgrade_url || '/app',
            };
            assistantMsg.content = '';
        } else {
            assistantMsg.content = body?.errors?.document?.[0] || body?.message
                || this.sendTexts.requestError.replace(':status', String(res.status)).replace(':text', res.statusText);
        }

        assistantMsg.rendered = true;
        userMsg.sendState = 'failed';
        this.isStreaming = false;
        this.clearStreamTimeout();
        this.restoreInputFocus();
    },

    // The network round trip shared by a fresh sendMessage() and a
    // resendMessage() retry. `userMsg` is mutated in place (sendState only,
    // never re-pushed), so both callers converge on the exact same bubble.
    // `draftConversationId` is this.conversationId AT THE TIME this send
    // started (see the capture comments in sendMessage()/resendMessage()):
    // clearDraft() re-derives the storage key from it, and is called on every
    // path below that lands sendState at 'sent'.
    async deliverMessage(userMsg, payload, contextForSend, draftConversationId) {
        const isFirstMessage = !this.conversationId;

        if (isFirstMessage) {
            this.mintAssistantStub();
            this.currentToolStatus = null;

            let newId = null;
            try {
                // Step 1: create the conversation row. Server returns the id
                // immediately without dispatching the AI job. Channel auth
                // requires this row to exist, so we must complete this before
                // attempting to subscribe.
                const createRes = await fetch(createConversationUrl, {
                    method: 'POST',
                    headers: jsonHeaders(),
                    body: JSON.stringify({
                        document: payload,
                        model: this.selectedModel !== 'auto' ? this.selectedModel : undefined,
                    }),
                });

                if (!createRes.ok) {
                    await this.failSend(createRes, userMsg);
                    return;
                }

                newId = (await createRes.json()).conversation_id;
                this.conversationId = newId;

                // Step 2: subscribe BEFORE dispatching the job so the broadcasts
                // emitted during the streaming job land on a live channel. The
                // row exists at this point so channel auth will succeed.
                await this.subscribeToConversation(newId);

                // Step 3: update the URL so a reload keeps the conversation.
                const url = new URL(window.location.href);
                url.pathname = url.pathname.replace(/\/chats\/?$/, `/chats/${newId}`);
                url.search = '';
                url.hash = '';
                history.replaceState(null, '', url.toString());

                window.dispatchEvent(new CustomEvent('chat:conversation-created', {
                    detail: { id: newId },
                }));

                // Step 4: trigger the AI by hitting the existing send endpoint.
                // It reserves a credit, dispatches ProcessChatMessage, and the
                // job's broadcasts arrive on our already-subscribed channel.
                this.startStreamTimeout();
                this.scrollToBottom(true);

                this.streamAbortController = new AbortController();

                const sendRes = await fetch(sendUrl.replace(/\/$/, '') + '/' + newId, {
                    method: 'POST',
                    headers: jsonHeaders(),
                    body: JSON.stringify({
                        document: payload,
                        conversation_id: newId,
                        model: this.selectedModel,
                        page_context: contextForSend ? { type: contextForSend.type, id: contextForSend.id } : null,
                    }),
                    signal: this.streamAbortController.signal,
                });

                if (!sendRes.ok) {
                    await this.failSend(sendRes, userMsg);
                    return;
                }

                userMsg.sendState = 'sent';
                this.clearDraft(draftConversationId);
            } catch (error) {
                if (error?.name === 'AbortError') {
                    // A deliberate cancel, not a delivery failure: the request
                    // may already have landed server-side.
                    userMsg.sendState = 'sent';
                    this.clearDraft(draftConversationId);
                    return;
                }
                const assistantMsg = this.messages[this.messages.length - 1];
                assistantMsg.content = this.sendTexts.networkError;
                assistantMsg.rendered = true;
                userMsg.sendState = 'failed';
                this.isStreaming = false;
                this.clearStreamTimeout();
                this.restoreInputFocus();
            }

            return;
        }

        this.currentToolStatus = null;

        const url = this.conversationId
            ? sendUrl.replace(/\/$/, '') + '/' + this.conversationId
            : sendUrl;

        // The channel-subscribe await used to run before the optimistic bubble
        // even existed, so a throw here was silently swallowed (nothing shown,
        // nothing stuck). Now the bubble is pushed and marked 'sending' before
        // this runs, so it MUST live inside the same try/catch as the fetch:
        // otherwise an uncaught throw here leaves sendState at 'sending'
        // forever (no Resend ever appears, gated on 'failed') and isStreaming
        // never resets to false, which soft-locks every later send into
        // queuedSend with nothing to flush it.
        try {
            if (!this.channel) {
                await this.subscribeToConversation(this.conversationId);
            } else if (this.channelReady) {
                await this.channelReady;
            }

            this.mintAssistantStub();
            this.startStreamTimeout();
            this.streamAbortController = new AbortController();

            const response = await fetch(url, {
                method: 'POST',
                headers: jsonHeaders(),
                body: JSON.stringify({
                    document: payload,
                    conversation_id: this.conversationId,
                    model: this.selectedModel,
                    page_context: contextForSend ? { type: contextForSend.type, id: contextForSend.id } : null,
                }),
                signal: this.streamAbortController.signal,
            });

            if (!response.ok) {
                await this.failSend(response, userMsg);
                return;
            }

            const body = await response.json();
            if (body.conversation_id && body.conversation_id !== this.conversationId) {
                this.conversationId = body.conversation_id;
                this.subscribeToConversation(body.conversation_id);
            }
            userMsg.sendState = 'sent';
            this.clearDraft(draftConversationId);
        } catch (error) {
            if (error?.name === 'AbortError') {
                userMsg.sendState = 'sent';
                this.clearDraft(draftConversationId);
                return;
            }

            // No assistant stub exists yet when the throw comes from the
            // channel-subscribe step above (it runs before mintAssistantStub());
            // the last message in that case is userMsg itself. Only touch a
            // REAL assistant bubble, never write assistant-only fields onto
            // the user message.
            const assistantMsg = this.messages[this.messages.length - 1];
            if (assistantMsg?.role === 'assistant') {
                assistantMsg.content = this.sendTexts.networkError;
                assistantMsg.rendered = true;
            }
            userMsg.sendState = 'failed';
            this.isStreaming = false;
            this.clearStreamTimeout();
            this.restoreInputFocus();
        }

        this.scrollToBottom(true);
    },

    async cancelStream() {
        if (this.conversationId) {
            try {
                await fetch(this.conversationsUrl + '/' + this.conversationId + '/cancel', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    },
                });
            } catch (_) { /* best-effort */ }
        }

        try { this.streamAbortController?.abort(); } catch (_) { /* ignore */ }
        this.streamAbortController = null;

        this.unsubscribe();
        this.clearStreamTimeout();

        const assistantMsg = this.messages[this.messages.length - 1];
        if (assistantMsg?.role === 'assistant') {
            if (!assistantMsg.content) {
                assistantMsg.content = this.sendTexts.cancelled;
            }
            assistantMsg.rendered = true;
            assistantMsg.prerendered = false;
        }

        this.currentToolStatus = null;
        this.isStreaming = false;
        this.queuedSend = null;
        this.restoreInputFocus();
    },

    async retryTurn(msg) {
        if (this.isStreaming) return;
        if (msg._retrying) return;
        // Same guard as regenerateMessage() and for the same reason (issue
        // #499): sendMessage() below is a no-op while rate-limited, so
        // splicing this turn out first would just delete it with nothing to
        // replace it. Bail and leave the retryable bubble in place.
        if (this.rateLimit) return;

        // Failed user turn: the server never stored the message — re-send the
        // preceding user message from local state (same flow as edit-resend).
        // Compute userIndex BEFORE committing to any state change so the no-op
        // path never sets _retrying.
        const userIndex = this.messages.slice(0, this.messages.indexOf(msg)).findLastIndex((m) => m.role === 'user');
        if (userIndex === -1) return;

        const userText = this.messages[userIndex].content;
        // Splice exactly the user message at userIndex plus the failed assistant
        // bubble (msg) when it sits directly after — no further messages removed.
        const removeCount = (this.messages[userIndex + 1] === msg) ? 2 : 1;
        this.messages.splice(userIndex, removeCount);
        this.input = userText;
        this.localEditor()?.setText(userText);
        this.$nextTick(() => this.sendMessage());
    },

    // The send hit the per-plan throttle. Drop the empty assistant filler
    // stub (nothing streamed into it), but KEEP the user's bubble, marked
    // failed, and count down to an automatic resend of that SAME bubble.
    // Popping and later re-minting a fresh one is exactly the F2 duplicate
    // bug this replaced.
    handleSendRateLimit(userMsg, body) {
        const stub = this.messages[this.messages.length - 1];
        if (stub?.role === 'assistant' && !stub.content && !stub.rendered && !(stub.pending_actions || []).length) {
            this.messages.pop();
        }

        userMsg.sendState = 'failed';

        this.isStreaming = false;
        this.clearStreamTimeout();
        // +1s margin: re-sending at the exact Retry-After boundary lands back
        // in the closing window and 429s again (observed live).
        this.startRateLimitCountdown(Math.max(2, (Number(body?.retry_after_seconds) || 30) + 1), userMsg);
        this.restoreInputFocus();
    },

    startRateLimitCountdown(seconds, userMsg = null) {
        this.clearRateLimit();
        this.rateLimit = { secondsLeft: seconds, timerId: null, userMsg };
        this.rateLimit.timerId = setInterval(() => {
            if (!this.rateLimit) return;
            this.rateLimit.secondsLeft -= 1;
            if (this.rateLimit.secondsLeft > 0) return;
            const target = this.rateLimit.userMsg;
            this.clearRateLimit();
            if (!target) return;
            // setTimeout, NOT $nextTick: an exception in any effect sharing
            // Alpine's flush queue (e.g. the banner tearing down) would drop a
            // queued nextTick callback — observed live. A macrotask is isolated.
            this.rateLimitResendTimer = setTimeout(() => this.resendMessage(target), 50);
        }, 1000);
    },

    clearRateLimit() {
        if (this.rateLimit?.timerId) {
            clearInterval(this.rateLimit.timerId);
        }
        // The macrotask above outlives the interval, so destroy() could only clear the
        // countdown and not the pending resend: a teardown inside that 50ms window
        // POSTed a send for a conversation the user had already left.
        if (this.rateLimitResendTimer) {
            clearTimeout(this.rateLimitResendTimer);
            this.rateLimitResendTimer = null;
        }
        this.rateLimit = null;
    },

    flushQueuedSend() {
        if (!this.queuedSend) return;
        const queued = this.queuedSend;
        this.queuedSend = null;
        if (queued.model && this.modelOptions.some((o) => o.value === queued.model)) {
            this.selectedModel = queued.model;
        }
        // Guarded INSIDE the callback, not before scheduling it: handleStreamEnd's own
        // `destroyed` check (stream.js) runs BEFORE this method is called, guarding the
        // await gap it just came out of, but this $nextTick opens a SEPARATE, later gap
        // of its own: a wire:navigate teardown can still land between this tick being
        // scheduled and it firing, same hazard and same fix shape as handleStreamFailed.
        this.$nextTick(() => {
            if (this.destroyed) return;
            this.localEditor()?.setDocument?.(this.plainDocument(queued.document));
            this.sendMessage();
        });
    },
});
