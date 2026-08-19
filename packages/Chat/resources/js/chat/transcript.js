// Message array manipulation, scroll pinning, jump pill, copy, and inline
// editing for the chat transcript. Spread into the `chatInterface` Alpine
// component alongside sendModule() and streamModule(): see
// chat-interface.blade.php for the composition and the two accessors
// (`hasPendingProposal`, `pendingLabel`) that stay inline there because a
// `get` property cannot survive an object spread.

const CONVERSATION_CACHE_LIMIT = 5;

// Consecutive same-role messages closer together than this render as one
// visual group (no repeated timestamp/avatar chrome, tighter spacing).
const GROUPING_GAP_MINUTES = 3;

// Cache-first conversation switching (window-scoped Map, LRU-capped at 5).
//
// Confirmed empirically before writing this: clicking a `wire:navigate`
// sidebar link destroys the whole Alpine tree, not just the swapped content
// region (a marker written onto `this` before switching was gone on the new
// instance after the transition; a marker written onto `window` survived;
// the SAME main-page click also wiped a marker on the side panel's own
// nested chatInterface instance, even though the side panel itself was
// never clicked). The side panel's OWN `openConversation()` was not driven
// directly, but it changes the `@livewire(...)` component key, and a keyed
// Livewire remount is a destroy-then-mount by definition, so it is expected
// to behave the same way. Either way, an in-memory `this.conversationCache`
// property would be destroyed on every switch and buy nothing: only
// `window` outlives the switch, hence the cache lives there instead.
//
// Scoped to the authenticated user via an "owner" tag: whenever the tag does
// not match the current user, the whole cache is discarded before use. This
// is a defense-in-depth measure (in normal operation login/logout are hard
// navigations that already wipe `window`), not the only thing keeping
// conversations apart: conversation ids are unique per row regardless of
// team, so there is no id collision vector between two different teams'
// conversations to begin with.
function conversationCacheEntries(userId) {
    const existing = window.__chatConversationCache;
    if (existing && existing.owner === userId) {
        return existing.entries;
    }
    const entries = new Map();
    window.__chatConversationCache = { owner: userId, entries };
    return entries;
}

function cacheConversationMessages(userId, conversationId, messages) {
    if (!conversationId) return;
    const entries = conversationCacheEntries(userId);
    entries.delete(conversationId);
    entries.set(conversationId, messages);
    while (entries.size > CONVERSATION_CACHE_LIMIT) {
        const oldestKey = entries.keys().next().value;
        entries.delete(oldestKey);
    }
}

// Bumps recency (moves the entry to the end of Map's insertion order) so the
// cap above evicts the conversation that was viewed longest ago, not merely
// the one cached first.
function readCachedConversationMessages(userId, conversationId) {
    const entries = conversationCacheEntries(userId);
    if (!entries.has(conversationId)) return null;
    const messages = entries.get(conversationId);
    entries.delete(conversationId);
    entries.set(conversationId, messages);
    return messages;
}

// Alpine's navigate plugin hands the destination as a real URL object (see
// vendor/livewire/livewire's forwarded `alpine:navigate` -> `livewire:navigate`
// event), but this also accepts a bare string defensively.
function conversationIdFromNavigateUrl(url) {
    if (!url) return null;
    try {
        const href = typeof url === 'string' ? url : url.href;
        const { pathname } = new URL(href, window.location.origin);
        const match = pathname.match(/\/chats\/([^/?#]+)\/?$/);
        return match ? match[1] : null;
    } catch (_) {
        return null;
    }
}

// Alpine's `messages` array is reactive (Proxy-wrapped); JSON round-tripping
// it before it goes into the cache both strips the Proxy (structuredClone
// throws on one, see plainDocument() in send.js for the same trap) and
// deep-clones so a later edit to the live array can never silently mutate
// what is sitting in the cache.
function snapshotMessages(messages) {
    try {
        return JSON.parse(JSON.stringify(messages));
    } catch (_) {
        return null;
    }
}

export const transcriptModule = ({ messagesUrl, todayLabel = 'Today', yesterdayLabel = 'Yesterday', feedbackDeleteConfirmText = 'Remove this feedback? Your category and comment will be deleted.' }) => ({
    prependScrollAnchor: null,
    // Guards loadEarlier() against re-entry: the top-sentinel observer
    // (see initLoadEarlierObserver) re-checks on every qualifying layout
    // change, so without this a single scroll-to-top would fire the Livewire
    // call repeatedly before the first request even lands.
    loadingEarlier: false,
    _loadEarlierObserver: null,
    now: Date.now(),
    copyTickerId: null,

    // Floating sticky-date pill (see initDaySeparatorObserver): the label of
    // the last inline day separator the user has scrolled past, or null when
    // none has (single-day transcript, or still at the very top).
    stickyDateLabel: null,
    _daySeparatorObserver: null,
    _daySeparatorEls: null,
    _unwatchDaySeparatorCount: null,

    // Bridge state for the docked livewire proposal-card. _lastActiveProposalId
    // dedupes proposal:set-active dispatches.
    _lastActiveProposalId: null,

    // Conversation switcher overlay (Cmd+O / Ctrl+O, see onChatRootKeydown()).
    // Full-page chat only (see the guard in onChatRootKeydown); the side panel
    // has its own history dropdown. The item list is fetched fresh on every
    // open, matching the side panel's own loadHistory()/filteredHistory()
    // fetch-and-substring-filter pattern rather than something new.
    switcherOpen: false,
    switcherQuery: '',
    switcherLoading: false,
    switcherError: false,
    switcherItems: [],
    switcherActiveIndex: 0,

    // Scroll ownership (see scrollToBottom): streaming only autoscrolls while
    // the user is pinned near the bottom; otherwise the jump pill shows.
    pinnedToBottom: true,
    hasUnseenBelow: false,

    // Stable identity for the x-for key: server id when persisted, otherwise a
    // minted client uuid that survives reconciliation (never reassigned).
    ensureClientKey(m) {
        if (!m.clientKey) {
            m.clientKey = m.id || ('c-' + (window.crypto?.randomUUID?.() ?? (Date.now() + '-' + Math.random())));
        }
        return m;
    },

    // Calendar-day comparison uses the BROWSER's local timezone. No user
    // timezone is threaded to any chat view today (users.timezone is only
    // ever read server-side, to seed the agent's system prompt in
    // ProcessChatMessage): adding a new server-to-client channel just for
    // this would be new plumbing the task does not call for.
    sameCalendarDay(a, b) {
        if (!a || !b) return false;
        const da = new Date(a);
        const db = new Date(b);
        if (Number.isNaN(da.getTime()) || Number.isNaN(db.getTime())) return false;
        return da.getFullYear() === db.getFullYear()
            && da.getMonth() === db.getMonth()
            && da.getDate() === db.getDate();
    },

    minutesBetween(a, b) {
        if (!a || !b) return Infinity;
        const diff = new Date(b).getTime() - new Date(a).getTime();
        return Number.isNaN(diff) ? Infinity : Math.abs(diff) / 60000;
    },

    formatDaySeparator(iso) {
        const now = new Date();
        if (this.sameCalendarDay(iso, now)) return todayLabel;
        const yesterday = new Date(now);
        yesterday.setDate(yesterday.getDate() - 1);
        if (this.sameCalendarDay(iso, yesterday)) return yesterdayLabel;
        return new Date(iso).toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' });
    },

    // Per-message render decorations, keyed by position in `messages`.
    //  - grouped: same role as the previous message, under the grouping gap,
    //    and the previous message carries no pending action (a proposal card
    //    must never be visually swallowed into a tight group).
    //  - daySeparator: the formatted date to show above this message, when
    //    its calendar day differs from the previous message's (null for the
    //    very first message, since there is nothing to differ from yet).
    decorations(index) {
        const msg = this.messages[index];
        if (!msg) return { grouped: false, daySeparator: null };
        const prev = index > 0 ? this.messages[index - 1] : null;

        const grouped = !!prev
            && prev.role === msg.role
            && this.minutesBetween(prev.created_at, msg.created_at) < GROUPING_GAP_MINUTES
            && !(Array.isArray(prev.pending_actions) && prev.pending_actions.length > 0);

        const daySeparator = (prev && !this.sameCalendarDay(prev.created_at, msg.created_at))
            ? this.formatDaySeparator(msg.created_at)
            : null;

        return { grouped, daySeparator };
    },

    // Sticky date pill: a floating element (see chat-interface.blade.php)
    // shows the label of whichever inline `[data-day-separator]` the user
    // has most recently scrolled past. Re-wired on every change to the
    // message COUNT (push, splice, prepend), not a deep watch on `messages`
    // itself, which would also fire on every streamed token and rescan the
    // DOM on each one.
    initDaySeparatorObserver() {
        this.$nextTick(() => this.refreshDaySeparatorObserver());
        this._unwatchDaySeparatorCount = this.$watch('messages.length', () => {
            this.$nextTick(() => this.refreshDaySeparatorObserver());
        });
    },

    refreshDaySeparatorObserver() {
        const container = this.$refs.messages;
        if (!container || typeof IntersectionObserver === 'undefined') return;

        if (this._daySeparatorObserver) {
            this._daySeparatorObserver.disconnect();
            this._daySeparatorObserver = null;
        }

        this._daySeparatorEls = Array.from(container.querySelectorAll('[data-day-separator]'));
        if (this._daySeparatorEls.length === 0) {
            this.stickyDateLabel = null;
            return;
        }

        // The callback ignores individual entries and re-scans from scratch:
        // isIntersecting alone can't tell which of several separators is the
        // one the user last scrolled past (that needs their relative order),
        // so the observer here is purely a cheap "something moved near the
        // top, go recompute" signal rather than the source of truth itself.
        this._daySeparatorObserver = new IntersectionObserver(
            () => this.updateStickyDateLabel(),
            { root: container, threshold: [0, 1] },
        );
        this._daySeparatorEls.forEach((el) => this._daySeparatorObserver.observe(el));
        this.updateStickyDateLabel();
    },

    updateStickyDateLabel() {
        const container = this.$refs.messages;
        if (!container || !this._daySeparatorEls) return;

        const containerTop = container.getBoundingClientRect().top;
        let active = null;
        for (const el of this._daySeparatorEls) {
            if (el.getBoundingClientRect().top - containerTop <= 1) {
                active = el;
            } else {
                break;
            }
        }

        this.stickyDateLabel = active ? active.textContent.trim() : null;
    },

    teardownDaySeparatorObserver() {
        if (this._daySeparatorObserver) {
            this._daySeparatorObserver.disconnect();
            this._daySeparatorObserver = null;
        }
        this._daySeparatorEls = null;
        if (typeof this._unwatchDaySeparatorCount === 'function') {
            this._unwatchDaySeparatorCount();
            this._unwatchDaySeparatorCount = null;
        }
    },

    // Auto-loads earlier history when the top sentinel (see topSentinel ref in
    // _transcript.blade.php, a single node placed above the message list,
    // OUTSIDE the x-for loop) scrolls into view. Unlike the day-separator
    // observer above, this is created exactly once and never needs
    // re-observing on a message.length change: the sentinel is a persistent
    // DOM node rather than one recreated per message, so array replacement
    // (conversation switch, regenerate/edit splices) never detaches it and
    // never leaves this observer watching a stale element.
    initLoadEarlierObserver() {
        const container = this.$refs.messages;
        const sentinel = this.$refs.topSentinel;
        if (!container || !sentinel || typeof IntersectionObserver === 'undefined') return;

        // init() (and so this, deferred off it) can run more than once for
        // the same live component (confirmed empirically), and already
        // guarded the same way by refreshDaySeparatorObserver() above.
        // Without disconnecting a prior instance first, each extra call
        // leaves its OWN IntersectionObserver alive watching the same
        // sentinel, and since they fire independently (not synchronously
        // back to back), more than one can slip past the loadingEarlier
        // guard and each pull a real page, duplicating messages.
        if (this._loadEarlierObserver) {
            this._loadEarlierObserver.disconnect();
            this._loadEarlierObserver = null;
        }

        this._loadEarlierObserver = new IntersectionObserver((entries) => {
            const visible = entries.some((entry) => entry.isIntersecting);
            if (visible) this.loadEarlier();
        }, { root: container });

        this._loadEarlierObserver.observe(sentinel);
    },

    teardownLoadEarlierObserver() {
        if (this._loadEarlierObserver) {
            this._loadEarlierObserver.disconnect();
            this._loadEarlierObserver = null;
        }
    },

    // Stashes the CURRENT conversation's transcript so a later switch back to
    // it can paint instantly. Called from destroy() (every teardown path:
    // wire:navigate, the side panel's keyed remount) and also from the start
    // of switchConversation() itself: see the comment there for why leaving
    // it to destroy() alone would lose data on a rapid switch.
    stashConversationCache() {
        if (!this.conversationId) return;
        const snapshot = snapshotMessages(this.messages);
        if (!snapshot) return;
        cacheConversationMessages(this.userId, this.conversationId, snapshot);
    },

    // Paints a cached transcript for `targetConversationId` into THIS
    // still-alive instance the moment a switch begins, well before the real
    // wire:navigate round trip resolves. Server truth always wins: whatever
    // this paints is provisional only, because the navigation that follows
    // always finishes by destroying this instance and mounting a fresh one
    // from ChatInterface::mount() -> fetchMessages(), which wholesale
    // replaces it regardless of whether the cache was right, stale, or
    // (via the ownership tag) refused outright. Returns true on a cache hit.
    switchConversation(targetConversationId) {
        if (!targetConversationId || targetConversationId === this.conversationId) return false;

        const cached = readCachedConversationMessages(this.userId, targetConversationId);
        if (!cached) return false;

        // Capture THIS conversation's true current state before it gets
        // overwritten below. Without this, a rapid switch (A -> C, cache
        // miss, immediately -> B, cache hit) would have already clobbered
        // this.messages/conversationId by the time destroy() runs, and
        // destroy()'s own stash would redundantly re-cache the wrong
        // (already-overwritten) content instead of A's real last state.
        this.stashConversationCache();

        // A pending draft-save debounce reads this.conversationId at fire
        // time (see send.js saveDraft()/destroy()'s own comment on this
        // exact bug): cancel it now that conversationId is about to change
        // out from under it, rather than let it fire later against the
        // wrong bucket.
        clearTimeout(this.draftDebounceTimer);

        this.messages = snapshotMessages(cached) ?? cached;
        this.conversationId = targetConversationId;

        // The cache stores messages only (see cacheConversationMessages()),
        // not whether more history exists, so this cannot know the real
        // answer for targetConversationId. Left stale (true, inherited from
        // whichever conversation was open before), the top-sentinel observer
        // could fire loadEarlierMessages() during this transitional window,
        // before the real wire:navigate swap replaces this instance: that
        // call would run against the Livewire component's STILL-OLD
        // server-side conversationId and land its response in the array now
        // painted for a different conversation. False is always safe here:
        // the destroy()-then-remount that always follows repaints with the
        // real value moments later. loadingEarlier and prependScrollAnchor
        // reset alongside it for the same reason: left stale (inherited from
        // whichever loadEarlier() call, if any, was in flight for the OLD
        // conversation), a late response landing after this paint would
        // apply its scroll-restore math against THIS conversation's now
        // totally unrelated scrollHeight, and a stale loadingEarlier=true
        // would wedge this freshly-painted conversation's own sentinel from
        // ever loading its real history.
        this.hasMoreMessages = false;
        this.loadingEarlier = false;
        this.prependScrollAnchor = null;

        this.scrollToBottom(true);

        this.$nextTick(() => {
            window.dispatchEvent(new CustomEvent('chat:cache-painted', {
                detail: { conversationId: targetConversationId },
            }));
        });

        return true;
    },

    // Only the full-page chat reacts to sidebar navigation: the side panel is
    // a different conversation entirely and must not have its transcript
    // hijacked just because the user navigated the main page elsewhere.
    // Guarded against mid-stream state (isStreaming / an active rate-limit
    // countdown) because both hold live references into this.messages
    // (the streaming target bubble, the countdown's userMsg) that a swap out
    // from under them would silently misfile onto the new conversation.
    installConversationSwitchWatch() {
        if (this.context !== 'conversation') return;
        this._conversationSwitchHandler = (event) => {
            if (this.isStreaming || this.rateLimit) return;
            const targetId = conversationIdFromNavigateUrl(event?.detail?.url);
            if (targetId) this.switchConversation(targetId);
        };
        document.addEventListener('livewire:navigate', this._conversationSwitchHandler);
    },

    uninstallConversationSwitchWatch() {
        if (this._conversationSwitchHandler) {
            document.removeEventListener('livewire:navigate', this._conversationSwitchHandler);
            this._conversationSwitchHandler = null;
        }
    },

    autosize(el) {
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 200) + 'px';
    },

    renderMessageContent(message) {
        if (!message.document || (Array.isArray(message.document.content) && message.document.content.length === 0)) {
            return this.escapeHtml(message.content ?? '');
        }

        // Server-resolved mention URLs are authoritative. When a message carries a
        // `mentions` array (loaded from the backend) links are rendered ONLY from
        // it — never from the client-controlled document. A just-sent (optimistic)
        // message has no server mentions yet, so it falls back to the picker URL
        // stored on the node, which is still scheme-validated before use.
        const serverMentions = Array.isArray(message.mentions) ? message.mentions : null;
        const mentionUrls = {};
        (serverMentions ?? []).forEach((m) => {
            if (m && m.id != null && m.url) {
                mentionUrls[String(m.id)] = m.url;
            }
        });

        return this.walkDocumentToHtml(message.document, { mentionUrls, allowNodeUrl: serverMentions === null });
    },

    walkDocumentToHtml(node, ctx = {}) {
        if (!node) return '';
        if (node.type === 'doc') {
            return (node.content ?? []).map((c) => this.walkDocumentToHtml(c, ctx)).join('');
        }
        if (node.type === 'paragraph') {
            const children = (node.content ?? []).map((c) => this.walkDocumentToHtml(c, ctx)).join('');
            return `<p>${children}</p>`;
        }
        if (node.type === 'text') {
            return this.escapeHtml(node.text ?? '');
        }
        if (node.type === 'mention') {
            return this.renderMentionNode(node, ctx);
        }
        if (node.type === 'hardBreak') {
            return '<br>';
        }
        return '';
    },

    renderMentionNode(node, ctx = {}) {
        const id = node.attrs?.id ?? '';
        const idAttr = this.escapeAttr(id);
        const type = this.escapeAttr(node.attrs?.type ?? '');
        const label = this.escapeHtml(node.attrs?.label ?? '');
        const baseClass = 'inline-flex items-center rounded-md bg-primary-100 px-1.5 py-0.5 text-xs text-primary-800 dark:bg-primary-900/30 dark:text-primary-200';

        let url = ctx.mentionUrls?.[String(id)] ?? null;
        if (!url && ctx.allowNodeUrl) {
            url = node.attrs?.url ?? null;
        }

        if (url && this.isSafeUrl(url)) {
            const href = this.escapeAttr(url);
            return `<a href="${href}" target="_blank" rel="noopener noreferrer" data-mention-id="${idAttr}" data-mention-type="${type}" class="${baseClass} no-underline transition-colors hover:bg-primary-200 dark:hover:bg-primary-900/60">@${label}</a>`;
        }

        return `<span data-mention-id="${idAttr}" data-mention-type="${type}" class="${baseClass}">@${label}</span>`;
    },

    isSafeUrl(url) {
        if (typeof url !== 'string' || url === '') return false;
        // Root-relative ("/app/...") URLs are same-origin and safe; reject the
        // protocol-relative "//host" form which would point off-origin.
        if (url.startsWith('//')) return false;
        if (url.startsWith('/')) return true;
        try {
            const parsed = new URL(url, window.location.origin);
            return parsed.protocol === 'http:' || parsed.protocol === 'https:';
        } catch (_) {
            return false;
        }
    },

    escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    },

    escapeAttr(str) {
        return this.escapeHtml(str);
    },

    // Shared by the manual "Load earlier messages" button and the top-sentinel
    // observer. loadingEarlier is cleared in two places, deliberately NOT
    // symmetrically: chat-interface.blade.php's chat:messages-prepended
    // handler clears it only AFTER the scroll-restore has moved the sentinel
    // out of view (confirmed empirically: clearing it any earlier, e.g. as
    // soon as the $wire promise settles, races that restore: the sentinel
    // is still geometrically visible for one more frame, the observer sees
    // that and fires a second real request before the first one's restore
    // has run). .catch() here only covers the request actually failing.
    loadEarlier() {
        if (this.loadingEarlier || !this.hasMoreMessages) return;
        this.loadingEarlier = true;

        const el = this.$refs.messages;
        this.prependScrollAnchor = el ? el.scrollHeight : 0;

        this.$wire.loadEarlierMessages().catch(() => {
            this.loadingEarlier = false;
        });
    },

    visiblePendingActions() {
        return this.messages
            .flatMap((m) => m.pending_actions || [])
            .filter((a) => a.status === 'pending');
    },

    activePendingActionId() {
        const pending = this.visiblePendingActions();
        return pending.length > 0 ? pending[0].pending_action_id : null;
    },

    syncActiveProposal() {
        const id = this.activePendingActionId();
        if (id === this._lastActiveProposalId) return;
        this._lastActiveProposalId = id;
        if (window.Livewire?.dispatch) {
            window.Livewire.dispatch('proposal:set-active', { id, context: this.context });
        }
    },

    findPendingAction(id) {
        for (const m of this.messages) {
            const found = (m.pending_actions || []).find((a) => a.pending_action_id === id);
            if (found) return found;
        }
        return null;
    },

    // Bridges BOTH resolution channels into one place: the resolving tab's own
    // synchronous Livewire `proposal:resolved` dispatch, AND the `.pending_
    // action.resolved` Reverb broadcast every subscribed tab (including the
    // resolving one, a moment later) receives (see stream.js's
    // handlePendingActionResolved and PendingActionService::broadcastResolution
    // server-side). The resolving tab therefore gets this call TWICE for the
    // same resolution; the "already applied" guards below make the second
    // call a no-op rather than double-firing the ai-write-completed refresh.
    // There is no socket-id-based toOthers() filter to lean on instead: this
    // app never wires Echo's socket id onto outgoing Livewire requests.
    applyProposalResolution(payload) {
        const action = this.findPendingAction(payload.pendingActionId);
        if (!action) return;

        if (payload.index === null || payload.index === undefined) {
            // Single proposal. Already resolved (by the other channel, or a
            // stale/duplicate broadcast): nothing left to apply.
            if (action.status !== 'pending') return;
            action.status = payload.decision === 'approved' ? 'approved' : 'rejected';
            if (payload.record) action.record = payload.record;
        } else {
            // Batch item: the transcript renders per-item status 'approved'/'skipped'.
            // Already resolved, same idempotency guard as above, per item.
            if (action.itemResults && action.itemResults[payload.index]) return;
            action.itemResults = {
                ...action.itemResults,
                [payload.index]: {
                    status: payload.decision === 'approved' ? 'approved' : 'skipped',
                    record: payload.record || null,
                },
            };
            if (payload.finalized) action.status = 'approved';
        }

        if (payload.decision === 'approved' && window.Livewire?.dispatch) {
            window.Livewire.dispatch('ai-write-completed', {
                entityType: action.entity_type ?? null,
                operation: action.operation ?? null,
            });
        }
    },

    startCopyTicker() {
        if (this.copyTickerId) return;
        this.copyTickerId = setInterval(() => {
            this.now = Date.now();
            const stillActive = this.messages.some((m) => m.copiedAt && this.now - m.copiedAt < 1500);
            if (!stillActive) {
                this.stopCopyTicker();
            }
        }, 200);
    },

    stopCopyTicker() {
        if (this.copyTickerId) {
            clearInterval(this.copyTickerId);
            this.copyTickerId = null;
        }
    },

    async copyMessage(msg) {
        const text = msg?.content || '';
        if (!text) return;

        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(text);
            } else {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.setAttribute('readonly', '');
                textarea.style.position = 'absolute';
                textarea.style.left = '-9999px';
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
            }
            msg.copiedAt = Date.now();
            this.now = msg.copiedAt;
            this.startCopyTicker();
        } catch (_) { /* clipboard blocked — silently ignore */ }
    },

    feedbackCategories: [
        { value: 'inaccurate', label: 'Inaccurate' },
        { value: 'did_not_follow', label: "Didn't do what I asked" },
        { value: 'too_slow', label: 'Too slow' },
        { value: 'other', label: 'Other' },
    ],

    // Thumbs funnel: up = one tap; down = rating recorded immediately, then an
    // optional category/comment panel. Tapping the active thumb retracts.
    async rateMessage(msg, rating) {
        if (!msg.id) return;

        if (msg.feedback?.rating === rating) {
            // A saved category survives reload (msg.feedback.category comes back
            // from the server); a saved comment only survives within this same
            // session (feedbackComment resets to '' on load, the server never
            // sends it back, see ListConversationMessages). Either one means the
            // user typed real detail that a silent toggle-off would discard.
            const hasSavedDetail = rating === 'down'
                && (msg.feedback?.category || (msg.feedbackComment || '').trim() !== '');
            if (hasSavedDetail && !window.confirm(feedbackDeleteConfirmText)) return;

            msg.feedback = null;
            msg.feedbackPanelOpen = false;
            await this.postFeedback(msg, null);
            return;
        }

        msg.feedback = { rating, category: null };
        msg.feedbackPanelOpen = rating === 'down';
        msg.feedbackCategory = null;
        msg.feedbackComment = '';
        await this.postFeedback(msg, { rating });
    },

    async submitFeedbackDetail(msg) {
        if (!msg.id || msg.feedback?.rating !== 'down') {
            msg.feedbackPanelOpen = false;
            return;
        }
        msg.feedback = { rating: 'down', category: msg.feedbackCategory ?? null };
        msg.feedbackPanelOpen = false;
        await this.postFeedback(msg, {
            rating: 'down',
            category: msg.feedbackCategory ?? null,
            comment: (msg.feedbackComment || '').trim() || null,
        });
    },

    async postFeedback(msg, payload) {
        try {
            const url = messagesUrl + '/' + msg.id + '/feedback';
            const headers = {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            };
            if (payload === null) {
                await fetch(url, { method: 'DELETE', headers });
                return;
            }
            await fetch(url, { method: 'POST', headers, body: JSON.stringify(payload) });
        } catch (_) { /* fire-and-forget — never block the conversation on feedback */ }
    },

    canRegenerate(index) {
        const msg = this.messages[index];
        if (msg?.pending_actions?.some((a) => a.status === 'pending')) {
            return false;
        }
        for (let i = index - 1; i >= 0; i--) {
            if (this.messages[i].role === 'user') {
                return true;
            }
        }
        return false;
    },

    canEdit(index) {
        if (this.isStreaming) return false;

        for (let i = index + 1; i < this.messages.length; i++) {
            const next = this.messages[i];
            if (next.role !== 'assistant') continue;
            const hasPending = (next.pending_actions || []).some((a) => a.status === 'pending');
            if (hasPending) return false;
            break;
        }
        return true;
    },

    startEdit(msg, index) {
        if (!this.canEdit(index)) return;
        this.messages.forEach((m) => {
            if (m.role === 'user' && m.editing) {
                m.editing = false;
                m.editText = '';
            }
        });
        msg.editText = msg.content;
        msg.editing = true;

        this.$nextTick(() => {
            const el = this.$refs.editArea;
            if (!el) return;
            el.focus();
            el.setSelectionRange(el.value.length, el.value.length);
            this.autosize(el);
        });
    },

    cancelEdit(msg) {
        msg.editing = false;
        msg.editText = '';
    },

    async saveEdit(msg, index) {
        if (this.isStreaming) return;

        const newText = (msg.editText || '').trim();
        if (!newText || newText.length > 5000) return;

        if (!(await this.supersedeServerTurns(msg))) return;

        this.messages.splice(index);

        this.input = newText;
        this.localEditor()?.setText(newText);
        this.$nextTick(() => this.sendMessage());
    },

    // Optimistic local supersede when the user sends a new message. The server
    // confirms via .pending_actions_superseded; both paths converge on the same
    // visual state so a single broadcast loss doesn't leave stale "pending" CTAs.
    markPendingActionsSuperseded(idFilter = null) {
        for (const msg of this.messages) {
            if (msg.role !== 'assistant' || !Array.isArray(msg.pending_actions)) continue;
            for (const action of msg.pending_actions) {
                if (action.status !== 'pending') continue;
                if (idFilter && !idFilter.has(action.pending_action_id)) continue;
                action.status = 'superseded';
            }
        }
    },

    // The transcript renders batch cards with per-item Created/Skipped chips.
    // applyProposalResolution() (the docked card's resolution bridge) writes
    // action.itemResults; the _proposal-card partial reads them through this
    // getter in both its compact-while-pending and full resolved modes.
    itemResult(action, index) {
        return (action.itemResults && action.itemResults[index]) || null;
    },

    // Past-tense verb for a resolved item's chip, by operation.
    itemVerb(action) {
        const op = action?.operation;
        return op === 'delete' ? 'Deleted' : (op === 'update' ? 'Updated' : 'Created');
    },

    // Reload-safe agent outcome summary for a finalized proposal. Built purely from
    // the persisted action (status, itemResults, record refs, display) so it survives
    // a conversation reload exactly like the audit card — no stored message and no AI
    // continuation (both intentionally removed). Returns null while still pending.
    proposalOutcome(action) {
        if (!action || action.status === 'pending') return null;

        const op = action.operation;
        const verb = op === 'delete' ? 'Deleted' : (op === 'update' ? 'Updated' : 'Created');
        const items = action.display?.items;

        if (Array.isArray(items) && items.length > 0) {
            const created = [];
            const skipped = [];
            items.forEach((item, i) => {
                const res = this.itemResult(action, i) || this.itemResult(action, String(i));
                if (!res) return;
                const name = res.record?.label || this.proposalItemName(item) || 'record';
                if (res.status === 'approved') created.push(name);
                else if (res.status === 'skipped') skipped.push(name);
            });
            const skippedVerb = op === 'delete' ? 'kept' : 'skipped';
            const parts = [];
            if (created.length) parts.push(`${verb} ${this.joinNames(created)}`);
            if (skipped.length) parts.push(`${skippedVerb} ${this.joinNames(skipped)}`);
            if (parts.length === 0) return null;
            const sentence = parts.join('; ') + '.';
            return sentence.charAt(0).toUpperCase() + sentence.slice(1);
        }

        if (action.status === 'approved') {
            const label = action.record?.label || this.extractQuotedName(action.display?.summary) || 'the record';
            return `${verb} ${label}.`;
        }
        if (action.status === 'rejected') {
            const label = this.extractQuotedName(action.display?.summary);
            if (op === 'delete') return label ? `Kept ${label} — deletion discarded.` : 'Deletion discarded.';
            return label ? `Discarded ${label}.` : 'Proposal discarded.';
        }
        return null;
    },

    proposalItemName(item) {
        if (!item) return null;
        const fields = item.fields;
        if (Array.isArray(fields) && fields.length > 0) {
            const value = fields[0].value ?? fields[0].new;
            if (typeof value === 'string' && value !== '') return value;
        }
        return this.extractQuotedName(item.summary);
    },

    extractQuotedName(text) {
        if (typeof text !== 'string') return null;
        const match = text.match(/"([^"]+)"/);
        return match ? match[1] : null;
    },

    joinNames(names) {
        const list = names.filter(Boolean);
        if (list.length === 0) return '';
        if (list.length === 1) return list[0];
        if (list.length === 2) return `${list[0]} and ${list[1]}`;
        return `${list.slice(0, -1).join(', ')}, and ${list[list.length - 1]}`;
    },

    // The user owns the scroll position. Streaming autoscrolls ONLY while they
    // are already pinned near the bottom; once they scroll up to read, new
    // content raises the "Jump to latest" pill instead of yanking them down.
    // force=true is for actions the user just took themselves (sending, the
    // pill, initial load).
    scrollToBottom(force = false) {
        if (!force && !this.pinnedToBottom) {
            this.hasUnseenBelow = true;
            return;
        }
        this.$nextTick(() => {
            const el = this.$refs.messages;
            if (el) el.scrollTop = el.scrollHeight;
            this.pinnedToBottom = true;
            this.hasUnseenBelow = false;
        });
    },

    trackScrollPosition() {
        const el = this.$refs.messages;
        if (!el) return;
        this.pinnedToBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
        if (this.pinnedToBottom) {
            this.hasUnseenBelow = false;
        }
    },

    jumpToLatest() {
        this.scrollToBottom(true);
    },

    // Keyboard layer. Bound via `x-on:keydown` directly on the chat root
    // element (see chat-interface.blade.php), NOT `window`/`document`: Alpine
    // tears the listener down with the component for free, and it only fires
    // for a keydown whose target is inside this chat instance, so it can never
    // hijack a key while the user is focused elsewhere in the panel (e.g. the
    // Filament top-nav search, which already owns Cmd+K, or the sidebar's own
    // Cmd+J side-panel toggle (both confirmed live app-wide before picking
    // Cmd+O for the switcher; see the PR description for the full trail).
    onChatRootKeydown(e) {
        // The side panel renders its own chatInterface instance on every page
        // (including this one); the switcher is a full-page-only feature, and
        // the side panel already has its own history dropdown.
        if (this.context !== 'conversation') return;

        if ((e.metaKey || e.ctrlKey) && !e.shiftKey && !e.altKey && e.key.toLowerCase() === 'o') {
            e.preventDefault();
            this.switcherOpen ? this.closeSwitcher() : this.openSwitcher();
            return;
        }

        if (e.key === 'Escape') {
            if (!this.switcherOpen) return;
            e.preventDefault();
            // Stops the side panel's own window-level Escape handler from also
            // acting on this same keypress (it closes the panel/its dropdowns
            // when open): the two must not both react to one Esc.
            e.stopPropagation();
            this.closeSwitcher();
            return;
        }

        if (!this.switcherOpen) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            this.switcherMoveActive(1);
            return;
        }
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            this.switcherMoveActive(-1);
            return;
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            this.switcherOpenActive();
        }
    },

    // ArrowUp-to-edit: wired from chat-editor.js's handleKeyDown (which only
    // calls this when the composer doc is empty) via the chat:editor-arrow-up
    // bridge event on the chatInterface root, see chat-interface.blade.php.
    // Reuses canEdit()/startEdit() so this behaves exactly like clicking the
    // edit pencil on the last user message (isStreaming and a pending-approval
    // block are already covered by canEdit()).
    maybeEditLastUserMessage() {
        for (let i = this.messages.length - 1; i >= 0; i--) {
            if (this.messages[i].role !== 'user') continue;
            if (this.canEdit(i)) this.startEdit(this.messages[i], i);
            return;
        }
    },

    filteredSwitcherItems() {
        const needle = this.switcherQuery.trim().toLowerCase();
        if (!needle) return this.switcherItems;
        return this.switcherItems.filter((item) => (item.title || '').toLowerCase().includes(needle));
    },

    openSwitcher() {
        if (this.switcherOpen) return;
        this.switcherOpen = true;
        this.switcherQuery = '';
        this.switcherActiveIndex = 0;
        this.$nextTick(() => this.$refs.switcherSearch?.focus());
        this.loadSwitcherConversations();
    },

    closeSwitcher() {
        if (!this.switcherOpen) return;
        this.switcherOpen = false;
        this.restoreInputFocus();
    },

    async loadSwitcherConversations() {
        this.switcherLoading = true;
        this.switcherError = false;
        try {
            const res = await fetch(this.conversationsUrl, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error('failed');
            this.switcherItems = (await res.json()).data ?? [];
        } catch (_) {
            this.switcherItems = [];
            this.switcherError = true;
        } finally {
            this.switcherLoading = false;
        }
    },

    switcherMoveActive(delta) {
        const items = this.filteredSwitcherItems();
        if (items.length === 0) return;
        this.switcherActiveIndex = (this.switcherActiveIndex + delta + items.length) % items.length;
    },

    switcherOpenActive() {
        const item = this.filteredSwitcherItems()[this.switcherActiveIndex];
        if (item) this.openSwitcherItem(item);
    },

    // Navigates via Alpine's SPA transition (the same mechanism a real
    // `wire:navigate` link click drives) rather than a hard `location.href`
    // assignment, so the existing cache-first switchConversation() instant
    // paint (installConversationSwitchWatch(), fired off the resulting
    // `livewire:navigate` event) applies here for free.
    openSwitcherItem(item) {
        if (!item?.id || !this.switcherConversationUrlTemplate) return;
        this.closeSwitcher();
        if (item.id === this.conversationId) return;
        const url = this.switcherConversationUrlTemplate.replace(this.switcherConversationUrlPlaceholder, item.id);
        if (window.Alpine?.navigate) {
            window.Alpine.navigate(url);
        } else {
            window.location.href = url;
        }
    },
});
