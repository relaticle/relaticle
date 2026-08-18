// Message array manipulation, scroll pinning, jump pill, copy, and inline
// editing for the chat transcript. Spread into the `chatInterface` Alpine
// component alongside sendModule() and streamModule(): see
// chat-interface.blade.php for the composition and the two accessors
// (`hasPendingProposal`, `pendingLabel`) that stay inline there because a
// `get` property cannot survive an object spread.
export const transcriptModule = ({ messagesUrl }) => ({
    prependScrollAnchor: null,
    now: Date.now(),
    copyTickerId: null,

    // Bridge state for the docked livewire proposal-card. _lastActiveProposalId
    // dedupes proposal:set-active dispatches.
    _lastActiveProposalId: null,

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

    loadEarlier() {
        const el = this.$refs.messages;
        this.prependScrollAnchor = el ? el.scrollHeight : 0;
        this.$wire.loadEarlierMessages();
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

    applyProposalResolution(payload) {
        const action = this.findPendingAction(payload.pendingActionId);
        if (!action) return;

        if (payload.index === null || payload.index === undefined) {
            // Single proposal.
            action.status = payload.decision === 'approved' ? 'approved' : 'rejected';
            if (payload.record) action.record = payload.record;
        } else {
            // Batch item: the transcript renders per-item status 'approved'/'skipped'.
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
});
