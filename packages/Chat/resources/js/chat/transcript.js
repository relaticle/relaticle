// Message array manipulation, scroll pinning, jump pill, copy, and inline
// editing for the chat transcript. Spread into the `chatInterface` Alpine
// component alongside sendModule() and streamModule(): see
// chat-interface.blade.php for the composition and the two accessors
// (`hasPendingProposal`, `pendingLabel`) that stay inline there because a
// `get` property cannot survive an object spread.

import { MENTION_CHIP_CLASS } from './mention-chip';

const CONVERSATION_CACHE_MAX_MESSAGES = 200;
const CONVERSATION_CACHE_LIMIT = 5;

// In-conversation search (Cmd+F). The endpoint enforces min:2, so anything
// shorter is not worth a round trip.
const SEARCH_MIN_QUERY = 2;
const SEARCH_DEBOUNCE_MS = 250;
// Safety net on the load-until-found loop. The normal exits are "found" and
// "no more history"; this only catches a server that keeps handing back full
// pages forever. 40 pages is 2000 messages at the component's PAGE_SIZE.
const MAX_SEARCH_JUMP_PAGES = 40;
const LOAD_EARLIER_POLL_MS = 40;
const LOAD_EARLIER_TIMEOUT_MS = 10000;
const SEARCH_HIGHLIGHT_MS = 2600;
const CONVERSATION_ID_PLACEHOLDER = '__CONVERSATION_ID__';

// Consecutive same-role messages closer together than this render as one
// visual group (no repeated timestamp/avatar chrome, tighter spacing).
const GROUPING_GAP_MINUTES = 3;

// A records_table block carries the WHOLE page the model read (see
// BaseReadListTool: it stopped slicing server-side so the model and the
// table never disagree about what was shown). But a chat bubble that scrolls
// past ten rows stops reading as a summary and starts reading as a dump, so
// the client still caps the PAINT at this many rows until the user asks for
// the rest via the collapse toggle.
const RECORDS_TABLE_COLLAPSE_ROWS = 10;

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

    // Capped by size as well as count. A transcript walked back through
    // MAX_SEARCH_JUMP_PAGES carries rendered HTML, display blocks and pending
    // actions for hundreds of messages, and five of those live on `window` across
    // every wire:navigate. Skipping the outsized ones costs one cache miss; keeping
    // them costs the tab.
    if (Array.isArray(messages) && messages.length > CONVERSATION_CACHE_MAX_MESSAGES) return;

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

export const transcriptModule = ({ messagesUrl, messageSearchUrlTemplate, messageSearchUnreachableText, messageSearchStalledText, todayLabel, yesterdayLabel, feedbackDeleteConfirmText, blockTitles, blockColumnLabels, blockFooterTemplate, blockShowAllTemplate, blockShowFewerText, blockOpenUrlTemplate, feedbackCategories = null, proposalTexts = {} }) => ({
    messageSearchUrlTemplate,
    messageSearchUnreachableText,
    messageSearchStalledText,
    prependScrollAnchor: null,
    // Guards loadEarlier() against re-entry: the top-sentinel observer
    // (see initLoadEarlierObserver) re-checks on every qualifying layout
    // change, so without this a single scroll-to-top would fire the Livewire
    // call repeatedly before the first request even lands.
    loadingEarlier: false,
    _loadEarlierObserver: null,
    // clientKey of the message whose "Copied" indicator is showing.
    copiedKey: null,
    _copiedTimer: null,

    // Floating sticky-date pill (see initDaySeparatorObserver): the label of
    // the last inline day separator the user has scrolled past, or null when
    // none has (single-day transcript, or still at the very top).
    stickyDateLabel: null,
    _daySeparatorObserver: null,
    _daySeparatorEls: null,
    _unwatchDaySeparatorCount: null,

    // Bridge state for the docked livewire proposal-card. The signature of the
    // whole pending set dedupes proposal:set-active dispatches, so a plan that
    // gains a step mid-stream still re-docks (see syncActiveProposal).
    _lastActiveProposalId: null,
    _lastActiveProposalSignature: null,

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

    // In-conversation search overlay (Cmd+F / Ctrl+F, see onChatRootKeydown()).
    // Same interaction model as the switcher above: type to filter, arrows to
    // move, Enter to act, Esc to dismiss. Unlike the switcher it does not
    // filter a preloaded list client-side, because the transcript the user is
    // looking at is only the newest page: the hits it needs may not be in the
    // DOM at all, which is the whole reason the server endpoint exists.
    searchOpen: false,
    searchQuery: '',
    searchLoading: false,
    searchError: false,
    searchResults: [],
    searchActiveIndex: 0,
    // True while the load-until-found loop is pulling earlier pages;
    // searchUnreachable when it finished without ever seeing the id (the
    // message was superseded between the search and the jump); searchStalled
    // when a history load never came back at all.
    searchJumping: false,
    searchUnreachable: false,
    searchStalled: false,
    // Set by Escape (see onChatRootKeydown) while searchJumping is true; the
    // loop below checks it once per page and bails, a user-left exit rather
    // than a failure, so it must not set searchStalled/searchUnreachable.
    searchJumpCancelled: false,
    highlightedMessageId: null,
    _searchDebounceTimer: null,
    _searchRequestToken: 0,
    _highlightTimer: null,

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

    // Normalizes a server-fetched message row for the transcript: stable
    // client key, marks assistant rows as already-rendered HTML, and seeds
    // the per-row UI state the templates bind to. Shared by the initial
    // mount hydration and the chat:messages-prepended handler (see
    // chat-interface.blade.php).
    hydrateServerMessage(m) {
        this.ensureClientKey(m);
        if (m.role === 'assistant') {
            m.rendered = true;
            m.prerendered = true;
            if (!Array.isArray(m.follow_ups)) {
                m.follow_ups = [];
            }
            m.feedback = m.feedback ?? null;
            m.feedbackPanelOpen = false;
            m.feedbackCategory = m.feedback?.category ?? null;
            m.feedbackComment = '';
        }
        if (m.role === 'user') {
            m.editing = false;
            m.editText = '';
        }
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

    // --- Read-tool display blocks -------------------------------------------
    //
    // A block is built inside the queued chat job, whose locale is whoever
    // happened to send that turn, and it is then persisted and re-read by
    // every later reader of the conversation. So its chrome (the table
    // heading, the core column's label) is generated in English and
    // translated HERE, at render time, against the reader's own locale. The
    // server strings stay as the fallback for anything these maps miss.

    // Blocks this build knows how to paint, in payload order. Filtering here
    // rather than in the template means an unregistered type leaves no empty
    // frame or stray margin behind.
    displayBlocks(msg) {
        return (msg?.display_blocks || []).filter((block) => window.ChatModules.isKnownBlock(block?.block));
    },

    // Interleaved render plan for an assistant reply: html and block segments
    // in reading order. The model places a block mid-reply by writing
    // {{block:N}} (1-based, tool-call order) alone on its own line, which both
    // markdown pipelines wrap as its own <p>; the reply splits there and the
    // real block component renders in that spot. Everything degrades to the
    // old behaviour: no markers, an out-of-range N, or a marker the split
    // cannot honor (inline mid-sentence) leaves the text intact minus the
    // marker and appends the unplaced blocks below the reply in order.
    messageSegments(msg) {
        const blocks = this.displayBlocks(msg);
        const html = msg.prerendered ? (msg.content || '') : window.renderMarkdown(msg.content || '');
        const stripStray = (chunk) => chunk.replace(/\{\{block:\d+\}\}/g, '');

        // Marker N follows tool-call order. A blockless earlier tool can make
        // that order differ from the display-block array index.
        const blockIndexForMarker = (markerOrder) => {
            const byOrder = blocks.findIndex((block) => block?.tool_call_order === markerOrder);
            if (byOrder !== -1) return byOrder;
            return blocks.some((block) => block?.tool_call_order !== undefined) ? -1 : markerOrder - 1;
        };

        const segments = [];
        const placed = new Set();
        const re = /<p>\s*\{\{block:(\d+)\}\}\s*<\/p>/g;
        let last = 0;
        let match;

        while ((match = re.exec(html)) !== null) {
            const idx = blockIndexForMarker(Number(match[1]));
            const before = stripStray(html.slice(last, match.index));
            if (before.trim() !== '') segments.push({ type: 'html', html: before });
            if (idx >= 0 && idx < blocks.length && !placed.has(idx)) {
                placed.add(idx);
                segments.push({ type: 'block', block: blocks[idx] });
            }
            last = re.lastIndex;
        }

        const rest = stripStray(html.slice(last));
        if (rest.trim() !== '') segments.push({ type: 'html', html: rest });

        blocks.forEach((block, idx) => {
            if (!placed.has(idx)) segments.push({ type: 'block', block });
        });

        return segments;
    },

    // Render streaming Markdown without exposing raw syntax.
    // Hide block markers until reconciliation.
    // Hide incomplete trailing markers and links until they close.
    streamingHtml(msg) {
        const text = (msg?.content || '')
            .replace(/\{\{block:\d+\}\}/g, '')
            .replace(/\{\{[^}]*\}?$/, '')
            .replace(/\{$/, '')
            .replace(/\[([^\]]*)\]\([^)]*$/, '$1')
            .replace(/\[[^\]]*$/, '');

        return window.renderMarkdown(text);
    },

    blockTitle(block) {
        return blockTitles[block?.type] ?? (block?.title || '');
    },

    // Only columns THIS build named are ours to translate, and the map is
    // keyed by block type so a team's own custom field can share a key with
    // one of ours without inheriting our label: a records_table's non-core
    // columns are custom fields, named by the team in the team's own words,
    // while an activity table's columns are all ours.
    blockColumnLabel(block, column) {
        return blockColumnLabels[block?.type]?.[column?.key] ?? (column?.label || '');
    },

    // A row may name its own record type: an activity table's rows point at a
    // mix of companies, tasks and notes, while a records_table's rows all share
    // the block's type.
    blockRowType(block, row) {
        return row?.type ?? block?.type;
    },

    // `cells` is sparse: formatStored() emits nothing for a field the record
    // holds no value for, so any column key can be missing from any row.
    blockCell(row, column) {
        return row?.cells?.[column?.key] ?? '';
    },

    // The record's own name cell links to the record; a promoted filter/sort
    // column sits in front of it and must not steal the link.
    blockCellLinksRecord(block, row, column) {
        return column?.key === block?.core && !!row?.url && this.blockCell(row, column) !== '';
    },

    // --- Records-table row collapse (D4) ------------------------------------
    //
    // Widget state, not tool state: whether a block is expanded lives ONLY in
    // this plain object, never on `block` or `msg` themselves. That keeps the
    // OpenAI Apps SDK boundary this codebase already follows for `data` versus
    // `display_block` intact one level deeper: the tool result stays exactly
    // what the model saw, and "did the user click show-more" is purely a
    // rendering fact. It is never persisted (excluded from the conversation
    // cache's snapshotMessages(), which clones `messages`, not this) and never
    // reaches the server, so a reload always starts every block collapsed.
    expandedBlocks: {},

    // A block carries no id of its own, so one is minted the first time it is
    // actually toggled (never on a plain read, to keep every other accessor
    // below a pure function with no side effect). Mirrors ensureClientKey()'s
    // approach for messages: a client-only tag the server never sees.
    toggleBlockExpanded(block) {
        if (!block) return;
        if (!block.__uiKey) {
            block.__uiKey = 'blk-' + (window.crypto?.randomUUID?.() ?? (Date.now() + '-' + Math.random()));
        }
        this.expandedBlocks[block.__uiKey] = !this.expandedBlocks[block.__uiKey];
    },

    blockIsExpanded(block) {
        return !!(block?.__uiKey && this.expandedBlocks[block.__uiKey]);
    },

    blockCanExpand(block) {
        return (block?.rows || []).length > RECORDS_TABLE_COLLAPSE_ROWS;
    },

    // The rows the table actually paints: every row once expanded, otherwise
    // the first RECORDS_TABLE_COLLAPSE_ROWS of the page `block.rows` already
    // holds in full (see BaseReadListTool, which stopped slicing server-side).
    blockVisibleRows(block) {
        const rows = block?.rows || [];
        return this.blockIsExpanded(block) ? rows : rows.slice(0, RECORDS_TABLE_COLLAPSE_ROWS);
    },

    blockToggleLabel(block) {
        return this.blockIsExpanded(block)
            ? blockShowFewerText
            : blockShowAllTemplate.replace(':count', String((block?.rows || []).length));
    },

    // D5: the entity's real list page, present on the payload only when the
    // tool's OWN pagination says a further page exists (see
    // BaseReadListTool::openUrlFor / RecordReferenceResolver::indexUrlFor).
    // Distinct from the row toggle above: expanding to see every row already
    // on this page never reveals a row that lives on page 2.
    blockOpenUrl(block) {
        return block?.open_url ?? null;
    },

    blockOpenUrlLabel(block) {
        return blockOpenUrlTemplate
            .replace(':total', String(block?.total ?? ''))
            .replace(':title', this.blockTitle(block));
    },

    blockFooter(block) {
        const showing = this.blockVisibleRows(block).length;
        const from = Number(block?.from ?? 0);

        // On any page past the first the rows are a window into the middle of the
        // result set, so a bare count would claim they are the leading N.
        const label = from > 1 && showing > 0
            ? `${from}-${from + showing - 1}`
            : String(showing);

        return blockFooterTemplate
            .replace(':showing', label)
            .replace(':total', String(block?.total ?? showing));
    },

    // Counts VISIBLE rows, not the full page: while collapsed this is what
    // keeps "Showing 10 of 25" honest instead of silently claiming credit for
    // rows 11-25, which are in the DOM's data but not yet painted.
    blockHasMore(block) {
        return (block?.total ?? 0) > this.blockVisibleRows(block).length;
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
    // from ChatInterface::mount()'s ListConversationMessages fetch, which wholesale
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

        // Hits and the highlight belong to the conversation being left: a
        // message id from it will never appear in the one being painted, so
        // carrying either over would just leave a dead result list open.
        this.dismissMessageSearch();
        this.searchResults = [];
        this.searchActiveIndex = 0;
        this.searchUnreachable = false;
        this.searchStalled = false;
        this.highlightedMessageId = null;
        clearTimeout(this._highlightTimer);

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
        // it, never from the client-controlled document. A just-sent (optimistic)
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
        const idAttr = this.escapeHtml(id);
        const type = this.escapeHtml(node.attrs?.type ?? '');
        const label = this.escapeHtml(node.attrs?.label ?? '');
        const baseClass = MENTION_CHIP_CLASS;

        let url = ctx.mentionUrls?.[String(id)] ?? null;
        if (!url && ctx.allowNodeUrl) {
            url = node.attrs?.url ?? null;
        }

        if (url && this.isSafeUrl(url)) {
            const href = this.escapeHtml(url);
            return `<a href="${href}" target="_blank" rel="noopener noreferrer" data-mention-id="${idAttr}" data-mention-type="${type}" class="${baseClass} no-underline transition-colors hover:bg-primary-200 dark:hover:bg-primary-900/60">@${label}</a>`;
        }

        return `<span data-mention-id="${idAttr}" data-mention-type="${type}" class="${baseClass}">@${label}</span>`;
    },

    isSafeUrl(url) {
        if (typeof url !== 'string' || url === '') return false;
        // Root-relative ("/app/...") URLs are same-origin and safe. Both off-origin
        // authority forms are rejected: per the URL spec a special scheme treats "\\"
        // like "/" in path-or-authority state, so "/\\evil.com" resolves to
        // https://evil.com/ and must not take the same-origin fast path.
        if (url.startsWith('//') || url.startsWith('/\\')) return false;
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

    // Keyed on the WHOLE pending set, not just the docked id: a chained turn
    // streams its steps in one by one, and the dock has to be told about the
    // later ones, otherwise it keeps rendering the plan as it looked when only
    // step one existed.
    syncActiveProposal() {
        const pending = this.visiblePendingActions();
        const signature = pending.map((a) => a.pending_action_id).join(',');
        if (signature === this._lastActiveProposalSignature) return;
        this._lastActiveProposalSignature = signature;

        const id = pending.length > 0 ? pending[0].pending_action_id : null;
        this._lastActiveProposalId = id;

        if (window.Livewire?.dispatch) {
            window.Livewire.dispatch('proposal:set-active', { id, context: this.context, model: this.selectedModel ?? null });
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
    // synchronous Livewire `proposal:resolved` dispatch (which CARRIES the
    // created record), AND the `.pending_action.resolved` Reverb broadcast
    // every subscribed tab (including the resolving one, a moment later)
    // receives with `record` always null (see stream.js's
    // handlePendingActionResolved and PendingActionService::broadcastResolution
    // server-side). The resolving tab therefore gets this call TWICE for the
    // same resolution, and `ShouldBroadcastNow` typically delivers the
    // record-less broadcast FIRST (mid-request, before the Livewire response
    // finishes rendering). Whichever payload carries a record must still win
    // on the record, regardless of arrival order, so the "already applied"
    // guard below only gates the one-time ai-write-completed refresh, not the
    // record merge. There is no socket-id-based toOthers() filter to lean on
    // instead: this app never wires Echo's socket id onto outgoing Livewire
    // requests.
    applyProposalResolution(payload) {
        const action = this.findPendingAction(payload.pendingActionId);
        if (!action) return;

        let isNewResolution = false;

        if (payload.index === null || payload.index === undefined) {
            // Single proposal.
            if (action.status === 'pending') {
                action.status = payload.decision === 'approved' ? 'approved' : 'rejected';
                isNewResolution = true;
            }
            if (payload.record && !action.record) action.record = payload.record;
            // Why the step ended, not just that it did: a cascade cancel renders
            // "Cancelled with the step it depended on." off this field, and
            // without it the live card says only "Rejected" until a reload.
            if (payload.cancelledBy) action.cancelled_by = payload.cancelledBy;
        } else {
            // Batch item: the transcript renders per-item status 'approved'/'skipped'.
            const existing = action.itemResults && action.itemResults[payload.index];
            if (!existing) {
                action.itemResults = {
                    ...action.itemResults,
                    [payload.index]: {
                        status: payload.decision === 'approved' ? 'approved' : 'skipped',
                        record: payload.record || null,
                    },
                };
                // Mirror finalizeBatchIfComplete server-side: a batch whose every
                // item was skipped finalizes as rejected, not approved.
                if (payload.finalized) {
                    action.status = Object.values(action.itemResults).some((r) => r?.status === 'approved')
                        ? 'approved'
                        : 'rejected';
                }
                isNewResolution = true;
            } else if (payload.record && !existing.record) {
                action.itemResults = {
                    ...action.itemResults,
                    [payload.index]: { ...existing, record: payload.record },
                };
            }
        }

        if (!isNewResolution) return;

        if (payload.decision === 'approved' && window.Livewire?.dispatch) {
            window.Livewire.dispatch('ai-write-completed', {
                entityType: action.entity_type ?? null,
                operation: action.operation ?? null,
            });
        }
    },

    // Record citations are stored root-relative (`/r/{type}/{id}`) so a
    // transcript renders on whatever host serves it. Pasted anywhere else they
    // have to carry the origin. Both forms are rewritten because `msg.content`
    // is markdown for a message rendered in this session and server HTML for
    // one rehydrated on reload (see `prerendered` in chat-interface.blade.php).
    absolutizeRecordLinks(text) {
        const origin = window.location.origin;

        return text
            .replaceAll('](/r/', `](${origin}/r/`)
            .replaceAll('href="/r/', `href="${origin}/r/`);
    },

    async copyMessage(msg) {
        const text = this.absolutizeRecordLinks(msg?.content || '').replace(/\{\{block:\d+\}\}/g, '').trim();
        if (!text) return;

        try {
            await navigator.clipboard.writeText(text);
            this.copiedKey = msg.clientKey;
            clearTimeout(this._copiedTimer);
            this._copiedTimer = setTimeout(() => { this.copiedKey = null; }, 1500);
        } catch (_) { /* clipboard blocked, silently ignore */ }
    },

    // Injected translated labels (chat-interface.blade.php); defaults keep the
    // module standalone.
    feedbackCategories: feedbackCategories || [
        { value: 'inaccurate', label: 'Inaccurate' },
        { value: 'did_not_follow', label: "Didn't do what I asked" },
        { value: 'too_slow', label: 'Too slow' },
        { value: 'other', label: 'Other' },
    ],

    // Injected translated copy for the resolved-item chips.
    proposalTexts: {
        createdVerb: 'Created',
        updatedVerb: 'Updated',
        deletedVerb: 'Deleted',
        countCreated: ':count created',
        countUpdated: ':count updated',
        countDeleted: ':count deleted',
        countSkipped: ':count skipped',
        countKept: ':count kept',
        ...proposalTexts,
    },

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

            const previous = msg.feedback ? { ...msg.feedback } : null;
            msg.feedback = null;
            msg.feedbackPanelOpen = false;
            await this.postFeedback(msg, null, previous);
            return;
        }

        const previous = msg.feedback ? { ...msg.feedback } : null;
        msg.feedback = { rating, category: null };
        msg.feedbackPanelOpen = rating === 'down';
        msg.feedbackCategory = null;
        msg.feedbackComment = '';
        await this.postFeedback(msg, { rating }, previous);
    },

    async submitFeedbackDetail(msg) {
        if (!msg.id || msg.feedback?.rating !== 'down') {
            msg.feedbackPanelOpen = false;
            return;
        }
        const previous = msg.feedback ? { ...msg.feedback } : null;
        msg.feedback = { rating: 'down', category: msg.feedbackCategory ?? null };
        msg.feedbackPanelOpen = false;
        await this.postFeedback(msg, {
            rating: 'down',
            category: msg.feedbackCategory ?? null,
            comment: (msg.feedbackComment || '').trim() || null,
        }, previous);
    },

    // Optimistic UI with a revert: the thumbs never block the conversation,
    // but a failed request must not leave a rating painted as saved. `previous`
    // is the caller's pre-mutation snapshot of msg.feedback.
    async postFeedback(msg, payload, previous = null) {
        try {
            const url = messagesUrl + '/' + msg.id + '/feedback';
            const headers = {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            };
            const res = payload === null
                ? await fetch(url, { method: 'DELETE', headers })
                : await fetch(url, { method: 'POST', headers, body: JSON.stringify(payload) });
            if (!res.ok) throw new Error('failed');
        } catch (_) {
            msg.feedback = previous;
            msg.feedbackPanelOpen = false;
        }
    },

    canRegenerate(index) {
        const msg = this.messages[index];
        if (msg?.pending_actions?.some((a) => a.status === 'pending')) {
            return false;
        }
        return this.messages.slice(0, index).some((m) => m.role === 'user');
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
        // Same guard as regenerateMessage() and retryTurn() (issue #499): sendMessage()
        // below is a no-op while rate-limited, so running the supersede-then-splice
        // sequence first would permanently delete this turn server-side (superseded_at
        // stamped, never undone) with nothing sent to replace it. Bail before the
        // supersede call, not just before the splice: unlike a local-only splice, the
        // server-side supersede is not recoverable by a later reload.
        if (this.rateLimit) return;

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

    // Derived receipt for a finalized batch. The row-level status 'approved'
    // hides a mixed outcome (approving 2 of 3 records still finalizes the row
    // as approved), so the header chip is computed from the per-item results
    // instead of echoing it. Only that status: a batch that wrote nothing
    // finalizes as 'rejected' and keeps the plain red Rejected chip, which is
    // both truthful and the stronger signal that nothing happened. Undecided,
    // expired and superseded cards keep their status chip too.
    batchOutcome(action) {
        if (!action || action.status !== 'approved') return null;

        const results = Object.values(action.itemResults || {});
        if (results.length === 0) return null;

        const done = results.filter((r) => r?.status === 'approved').length;
        const skipped = results.length - done;

        const t = this.proposalTexts;
        const op = action.operation;
        const doneTemplate = op === 'delete' ? t.countDeleted : (op === 'update' ? t.countUpdated : t.countCreated);
        const skippedTemplate = op === 'delete' ? t.countKept : t.countSkipped;

        return {
            done,
            skipped,
            doneLabel: doneTemplate.replace(':count', String(done)),
            skippedLabel: skippedTemplate.replace(':count', String(skipped)),
        };
    },

    // Whether any item of a batch has been decided. Read by the card's
    // compact-while-pending branch and by its exact complement, the
    // still-undecided-step branch; they must stay complements or a pending step
    // renders both or neither, which is how "0 of 0 resolved" reached a card
    // nobody had answered.
    hasItemResults(action) {
        return Object.keys(action?.itemResults || {}).length > 0;
    },

    // Past-tense verb for a resolved item's chip, by operation.
    itemVerb(action) {
        const op = action?.operation;
        return op === 'delete'
            ? this.proposalTexts.deletedVerb
            : (op === 'update' ? this.proposalTexts.updatedVerb : this.proposalTexts.createdVerb);
    },

    // Keep resolved proposal identity aligned with the record pills used in
    // assistant replies and read-result blocks. Approved records carry their
    // resolved label. Rejected and expired proposals fall back to the quoted
    // title stored in the display summary.
    proposalRecordLabel(action) {
        const resolvedLabel = action?.record?.label;
        if (typeof resolvedLabel === 'string' && resolvedLabel !== '') return resolvedLabel;

        return this.quotedSummaryTitle(action?.display?.summary);
    },

    // A batch item's record identity: its display title holds the operation
    // ("Create Task"), so the record name only exists inside the quoted summary.
    proposalItemLabel(item) {
        return this.quotedSummaryTitle(item?.summary);
    },

    quotedSummaryTitle(summary) {
        if (typeof summary !== 'string') return '';

        const match = summary.match(/"(.*)"/u);
        return match?.[1] || '';
    },

    // Proposals from one assistant turn are ONE decision, so the transcript shows
    // them as one card in the order they run. A proposal without a turn (anything
    // written before plans existed) stands alone, which is also the single-write
    // case.
    proposalGroups(msg) {
        const actions = (msg && msg.pending_actions) || [];
        const groups = [];
        const byTurn = new Map();

        for (const action of actions) {
            const turn = action.turn_id || null;

            if (!turn) {
                groups.push({ key: action.pending_action_id, turnId: null, actions: [action] });
                continue;
            }

            if (!byTurn.has(turn)) {
                const group = { key: turn, turnId: turn, actions: [] };
                byTurn.set(turn, group);
                groups.push(group);
            }

            byTurn.get(turn).actions.push(action);
        }

        return groups;
    },

    isPlanGroup(group) {
        return !!group && Array.isArray(group.actions) && group.actions.length > 1;
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

        // Cmd+F is taken over only while the keydown target is inside this chat
        // instance (this listener is bound to the chat root, not window), so
        // the browser's own find still works everywhere else on the page. Worth
        // the trade here: native find can only see the newest page of a paged
        // transcript, whereas this searches the whole conversation server-side.
        if ((e.metaKey || e.ctrlKey) && !e.shiftKey && !e.altKey && e.key.toLowerCase() === 'f') {
            if (this.searchOpen) {
                e.preventDefault();
                this.closeMessageSearch();
                return;
            }

            // Nothing to search yet (a brand-new chat with no conversation).
            // Fall through WITHOUT preventDefault so the browser's own find
            // still opens, rather than swallowing the key to do nothing.
            if (!this.canOpenMessageSearch()) return;

            e.preventDefault();
            this.openMessageSearch();
            return;
        }

        if (e.key === 'Escape') {
            if (!this.switcherOpen && !this.searchOpen && !this.searchJumping) {
                // Nothing overlay-like open: Escape stops an active generation
                // (the ChatGPT/Claude convention). Guarded to this full-page
                // context by the top of this handler, so the side panel keeps
                // its own Escape-closes-panel behaviour.
                if (this.isStreaming) {
                    e.preventDefault();
                    e.stopPropagation();
                    this.cancelStream();
                }
                return;
            }
            e.preventDefault();
            // Stops the side panel's own window-level Escape handler from also
            // acting on this same keypress (it closes the panel/its dropdowns
            // when open): the two must not both react to one Esc.
            e.stopPropagation();
            if (this.searchJumping) {
                this.searchJumpCancelled = true;
                return;
            }
            this.switcherOpen ? this.closeSwitcher() : this.closeMessageSearch();
            return;
        }

        if (this.searchOpen) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.messageSearchMoveActive(1);
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.messageSearchMoveActive(-1);
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                this.openActiveMessageSearchResult();
            }
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
        const i = this.messages.findLastIndex((m) => m.role === 'user');
        if (i !== -1 && this.canEdit(i)) this.startEdit(this.messages[i], i);
    },

    filteredSwitcherItems() {
        const needle = this.switcherQuery.trim().toLowerCase();
        if (!needle) return this.switcherItems;
        return this.switcherItems.filter((item) => (item.title || '').toLowerCase().includes(needle));
    },

    openSwitcher() {
        if (this.switcherOpen) return;
        this.dismissMessageSearch();
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
        this.closeSwitcher();
        if (!item?.id || !this.switcherConversationUrlTemplate) return;
        if (item.id === this.conversationId) return;
        const url = this.switcherConversationUrlTemplate.replace(CONVERSATION_ID_PLACEHOLDER, item.id);
        if (window.Alpine?.navigate) {
            window.Alpine.navigate(url);
        } else {
            window.location.href = url;
        }
    },

    // ---- In-conversation search (Cmd+F) -----------------------------------

    // A brand-new chat has no conversation to search yet. Checked before the
    // keydown handler calls preventDefault(), so Cmd+F falls through to the
    // browser's own find rather than being swallowed for nothing.
    canOpenMessageSearch() {
        return !!this.conversationId && !!this.messageSearchUrlTemplate;
    },

    openMessageSearch() {
        if (this.searchOpen) return;
        if (!this.canOpenMessageSearch()) return;

        this.switcherOpen = false;
        this.searchOpen = true;
        this.searchQuery = '';
        this.searchResults = [];
        this.searchActiveIndex = 0;
        this.searchError = false;
        this.searchUnreachable = false;
        this.searchStalled = false;
        this.focusMessageSearchInput();
    },

    // The overlay is toggled by x-show, so the input is display:none at the
    // moment the flag flips and .focus() on it would be a no-op. $nextTick puts
    // this after Alpine has applied the show, which is where every other focus
    // call in this component sits. Destroyed-guarded because callers reach here
    // after an await.
    focusMessageSearchInput() {
        this.$nextTick(() => {
            if (this.destroyed) return;
            this.$refs.messageSearchInput?.focus();
        });
    },

    closeMessageSearch() {
        if (!this.searchOpen) return;
        this.dismissMessageSearch();
        this.restoreInputFocus();
    },

    // Closes without touching focus, so opening the other overlay (or jumping
    // to a hit) does not fight it for the caret.
    dismissMessageSearch() {
        this.searchOpen = false;
        this.searchLoading = false;
        clearTimeout(this._searchDebounceTimer);
        // Invalidates any response still in flight: it must not repopulate the
        // list of a search the user has already dismissed.
        this._searchRequestToken++;
    },

    onMessageSearchInput() {
        this.searchActiveIndex = 0;
        this.searchUnreachable = false;
        this.searchStalled = false;
        clearTimeout(this._searchDebounceTimer);
        this._searchDebounceTimer = setTimeout(() => this.runMessageSearch(), SEARCH_DEBOUNCE_MS);
    },

    async runMessageSearch() {
        const query = this.searchQuery.trim();

        this.searchError = false;

        if (query.length < SEARCH_MIN_QUERY || !this.conversationId || !this.messageSearchUrlTemplate) {
            this.searchResults = [];
            this.searchLoading = false;
            return;
        }

        // Monotonic token, checked after every await: a slow response for an
        // earlier keystroke must never overwrite a newer one's results.
        const token = ++this._searchRequestToken;
        this.searchLoading = true;

        try {
            const url = this.messageSearchUrlTemplate.replace(CONVERSATION_ID_PLACEHOLDER, encodeURIComponent(this.conversationId))
                + '?q=' + encodeURIComponent(query);
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error('failed');
            const payload = await res.json();
            if (this.destroyed || token !== this._searchRequestToken) return;
            this.searchResults = Array.isArray(payload?.matches) ? payload.matches : [];
            this.searchActiveIndex = 0;
        } catch (_) {
            if (this.destroyed || token !== this._searchRequestToken) return;
            this.searchResults = [];
            this.searchError = true;
        } finally {
            if (!this.destroyed && token === this._searchRequestToken) {
                this.searchLoading = false;
            }
        }
    },

    // The one notice that outranks the result rows, or '' when there is none.
    // Both outcomes it covers are dead ends for the hit the user just picked,
    // so they share a slot rather than each growing another gate on every other
    // branch in the overlay.
    messageSearchNotice() {
        if (this.searchUnreachable) return this.messageSearchUnreachableText;
        if (this.searchStalled) return this.messageSearchStalledText;

        return '';
    },

    messageSearchMoveActive(delta) {
        const total = this.searchResults.length;
        if (total === 0) return;
        this.searchActiveIndex = (this.searchActiveIndex + delta + total) % total;
    },

    openActiveMessageSearchResult() {
        const match = this.searchResults[this.searchActiveIndex];
        if (match) this.jumpToMessage(match.message_id);
    },

    messageIndexById(messageId) {
        return this.messages.findIndex((m) => m.id === messageId);
    },

    // Resolves once no loadEarlier() is in flight. `loadingEarlier` is cleared
    // by chat-interface.blade.php's chat:messages-prepended handler, and only
    // AFTER that handler has restored the scroll position, so seeing it false
    // here means the prepend has fully settled. Returns false when it never
    // settles (a wedged request) or the component died mid-wait, which is the
    // signal for the caller to give up rather than spin.
    async settleEarlierLoad() {
        const deadline = Date.now() + LOAD_EARLIER_TIMEOUT_MS;

        while (this.loadingEarlier && Date.now() < deadline) {
            await new Promise((resolve) => setTimeout(resolve, LOAD_EARLIER_POLL_MS));
            if (this.destroyed) return false;
        }

        return !this.loadingEarlier;
    },

    // Walks earlier pages until `messageId` is in `this.messages`, then centres
    // and highlights it. Drives the SAME loadEarlier() path the top sentinel
    // uses rather than a parallel fetch, so paging stays single-sourced.
    //
    // Terminates on any of: the message is present; the server says there is no
    // more history; a page came back without growing the array (empty or
    // failed); MAX_SEARCH_JUMP_PAGES; a load that never settles; teardown.
    //
    // Every exit that leaves the user on this page reopens the overlay and puts
    // the caret back in its input. The overlay is hidden while the walk runs, so
    // the focused input is display:none'd and focus falls to <body>, which is
    // NOT inside the chat root: without re-focusing, the reopened dialog would
    // ignore Escape, the arrows and Enter, and a later Cmd+F would be dead too
    // (both measured before this was added).
    async jumpToMessage(messageId) {
        if (!messageId) return;

        this.dismissMessageSearch();
        // Parks focus on the composer (still inside the chat root) instead of
        // leaving it stranded on the now-hidden search input, whose display:none
        // drops focus to <body>, outside onChatRootKeydown's subtree, meaning
        // Escape could never reach the handler to cancel this same walk.
        this.restoreInputFocus();
        this.searchJumping = true;

        try {
            for (let page = 0; page < MAX_SEARCH_JUMP_PAGES; page++) {
                if (this.searchJumpCancelled) return;

                // First: let any load the top sentinel already started finish,
                // otherwise loadEarlier() below would no-op on its re-entry
                // guard and this loop would give up on its first pass.
                if (!(await this.settleEarlierLoad())) return this.abandonJump();
                if (this.messageIndexById(messageId) !== -1) break;
                if (!this.hasMoreMessages) break;

                const before = this.messages.length;
                this.loadEarlier();
                if (!(await this.settleEarlierLoad())) return this.abandonJump();
                if (this.messages.length === before) break;
            }
        } finally {
            this.searchJumping = false;
            this.searchJumpCancelled = false;
        }

        if (this.destroyed) return;

        if (this.messageIndexById(messageId) === -1) {
            // Drop the hit that led nowhere before reopening, otherwise the
            // notice sits above the very row it just said no longer exists and
            // invites a second futile click. Any other hits stay clickable, so
            // the active row is clamped back into the shortened list rather
            // than left pointing past its end.
            this.searchResults = this.searchResults.filter((match) => match.message_id !== messageId);
            this.searchActiveIndex = 0;
            this.searchUnreachable = true;
            this.searchOpen = true;
            this.focusMessageSearchInput();
            return;
        }

        this.revealMessage(messageId);
        this.restoreInputFocus();
    },

    // A history load that never settled (its 10s deadline elapsed). Without
    // this the banner just disappeared and the user was told nothing at all.
    abandonJump() {
        if (this.destroyed) return;
        this.searchStalled = true;
        this.searchOpen = true;
        this.focusMessageSearchInput();
    },

    revealMessage(messageId) {
        this.highlightedMessageId = messageId;
        clearTimeout(this._highlightTimer);

        // A macrotask, not $nextTick: the prepend handler restores scrollTop
        // inside its own $nextTick, and Alpine flushes those in queue order, so
        // a $nextTick queued here could still land before a restore and have
        // its centring stomped. Destroyed-guarded because a wire:navigate can
        // land in the gap.
        setTimeout(() => {
            if (this.destroyed) return;

            const container = this.$refs.messages;
            if (!container) return;

            const id = window.CSS?.escape ? window.CSS.escape(messageId) : messageId;
            const el = container.querySelector(`[data-message-id="${id}"]`);
            if (!el) return;

            el.scrollIntoView({
                block: 'center',
                behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
            });
        }, 0);

        this._highlightTimer = setTimeout(() => {
            if (this.destroyed) return;
            this.highlightedMessageId = null;
        }, SEARCH_HIGHLIGHT_MS);
    },

    teardownMessageSearch() {
        clearTimeout(this._searchDebounceTimer);
        clearTimeout(this._highlightTimer);
        this._searchRequestToken++;
    },
});
