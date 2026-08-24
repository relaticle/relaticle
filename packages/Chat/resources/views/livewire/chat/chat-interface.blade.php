<div
    x-data="chatInterface(@js($conversationId), @js(route('chat.send')), @js($initialMessage), @js($messages), @js(auth()->id()), @js($hasMoreMessages), @js($initialModel ?? auth()->user()?->ai_preferences['default_model'] ?? 'auto'))"
    x-on:keydown="onChatRootKeydown($event)"
    x-on:chat:focus-editor.window="if ($event.detail?.context === @js($context ?? 'conversation')) localEditor()?.focus()"
    x-on:chat:editor-arrow-up.window="if ($event.detail?.context === @js($context ?? 'conversation')) maybeEditLastUserMessage()"
    x-on:chat:context-updated.window="
        pageContext = ($event.detail.type && $event.detail.id) ? { type: $event.detail.type, id: $event.detail.id } : null;
        pageContextLabel = $event.detail.label ?? null;
        starterPrompts = ($event.detail.prompts && $event.detail.prompts.length) ? $event.detail.prompts : starterPrompts;
        pageContextDismissed = false;
        pageContextConsumed = false;
    "
    data-chat-context="{{ $context ?? 'conversation' }}"
    class="relative flex h-full flex-col bg-[var(--surface-canvas-bg)]"
>
    @include('chat::livewire.chat.partials._transcript')

    @include('chat::livewire.chat.partials._composer')

    {{-- Full-page chat only: the switcher never opens on the side panel's own
         nested chatInterface instance (see onChatRootKeydown's context guard),
         and rendering the overlay markup there too would leave two identical
         role="dialog" aria-label="Switch conversation" nodes on every page
         that hosts the side panel, i.e. nearly every app page. --}}
    @if (($context ?? 'conversation') === 'conversation')
        @include('chat::livewire.chat.partials._switcher')
        @include('chat::livewire.chat.partials._message-search')
    @endif
</div>

@script
<script>
Alpine.data('chatInterface', (initialConversationId, sendUrl, initialMessage, initialMessages, userId, initialHasMoreMessages, initialModel) => ({
    ...window.ChatModules.transcriptModule({
        messagesUrl: @js(url('/chat/messages')),
        {{-- Templated on the conversation id for the same reason the switcher's
             own URL is: the id is minted client-side for a brand-new chat, so
             it is not knowable when this attribute renders. --}}
        messageSearchUrlTemplate: @js(route('chat.conversations.search', ['conversationId' => '__CONVERSATION_ID__'])),
        messageSearchUnreachableText: @js(__('That message is no longer part of this conversation.')),
        messageSearchStalledText: @js(__('Could not load enough history to reach that message. Try again.')),
        todayLabel: @js(__('Today')),
        yesterdayLabel: @js(__('Yesterday')),
        feedbackDeleteConfirmText: @js(__('Remove this feedback? Your category and comment will be deleted.')),
        {{-- Display-block chrome. The block itself is generated inside the queued
             chat job in whatever locale sent that turn, then persisted and re-read
             by everyone else, so the heading and the core column label are
             translated here against the reader's locale instead. --}}
        blockTitles: @js([
            'company' => __('Companies'),
            'people' => __('People'),
            'opportunity' => __('Opportunities'),
            'task' => __('Tasks'),
            'note' => __('Notes'),
            'activity' => __('Activity'),
        ]),
        blockColumnLabels: @js([
            'company' => ['name' => __('Name')],
            'people' => ['name' => __('Name')],
            'opportunity' => ['name' => __('Name')],
            'task' => ['title' => __('Title')],
            'note' => ['title' => __('Title')],
            'activity' => [
                'when' => __('When'),
                'record' => __('Record'),
                'who' => __('Who'),
                'what' => __('What changed'),
            ],
        ]),
        blockFooterTemplate: @js(__('Showing :showing of :total')),
        feedbackCategories: @js([
            ['value' => 'inaccurate', 'label' => __('Inaccurate')],
            ['value' => 'did_not_follow', 'label' => __("Didn't do what I asked")],
            ['value' => 'too_slow', 'label' => __('Too slow')],
            ['value' => 'other', 'label' => __('Other')],
        ]),
        proposalTexts: @js([
            'createdVerb' => __('Created'),
            'updatedVerb' => __('Updated'),
            'deletedVerb' => __('Deleted'),
            'keptWord' => __('kept'),
            'skippedWord' => __('skipped'),
            'outcomePart' => __(':verb :names'),
            'outcomeSingle' => __(':verb :name.'),
            'fallbackRecord' => __('record'),
            'fallbackTheRecord' => __('the record'),
            'keptDeletionDiscarded' => __('Kept :name, deletion discarded.'),
            'deletionDiscarded' => __('Deletion discarded.'),
            'discardedName' => __('Discarded :name.'),
            'proposalDiscarded' => __('Proposal discarded.'),
        ]),
    }),
    ...window.ChatModules.sendModule({
        sendUrl,
        createConversationUrl: @js(route('chat.conversations.create')),
        texts: @js([
            'sessionExpired' => __('Your session expired. Please sign in again: your message is saved locally.'),
            'paywallHeading' => __("You've used all your AI credits"),
            'paywallReset' => __('Your plan resets on :date.'),
            'paywallTopUp' => __('Add credits to keep chatting.'),
            'requestError' => __('Error :status: :text'),
            'networkError' => __('Network error. Please try again.'),
            'cancelled' => __('Cancelled.'),
        ]),
    }),
    ...window.ChatModules.streamModule({
        texts: @js([
            'runningTool' => __('Running tool…'),
            'readingSummary' => __('Reading CRM summary…'),
            'searchingCrm' => __('Searching CRM…'),
            'runningName' => __('Running :name…'),
            'searchingEntity' => __('Searching :entity…'),
            'lookingUpEntity' => __('Looking up :entity…'),
            'draftingEntity' => __('Drafting :entity…'),
            'updatingEntity' => __('Preparing :entity changes…'),
            'deletingEntity' => __('Preparing :entity deletion…'),
            'streamError' => __('The assistant encountered an error. Please try again.'),
            'timeout' => __('The assistant took too long to respond.'),
            'retrying' => __('Provider is busy, retrying (attempt :attempt of :max)…'),
        ]),
    }),

    conversationId: initialConversationId,
    userId,
    context: 'conversation',
    // Conversation switcher (Cmd+O / Ctrl+O): the list itself is fetched fresh
    // client-side from chat.conversations on open, but building the URL for a
    // conversation the user picks needs a template resolved server-side:
    // mirrors ChatSidePanel's own CONVERSATION_URL_PLACEHOLDER mechanism,
    // including the null-tenant guard (this view also renders on tenant-less
    // pages/tests, where ChatConversation::getUrl() would otherwise throw
    // a UrlGenerationException for the missing {tenant} route parameter).
    conversationsUrl: @js(url('/chat/conversations')),
    switcherConversationUrlTemplate: @js(\Filament\Facades\Filament::getTenant() === null ? null : \App\Filament\Pages\ChatConversation::getUrl(['conversationId' => '__CONVERSATION_ID__'])),
    messages: initialMessages || [],
    hasMoreMessages: !!initialHasMoreMessages,
    isStreaming: false,
    @include('chat::livewire.chat.partials._model-state', ['persistSelection' => true])
    pageContext: @js($pageContextType && $pageContextId ? ['type' => $pageContextType, 'id' => $pageContextId] : null),
    pageContextLabel: @js($pageContextLabel),
    // Alpine re-initialises on SPA navigation, so this resets per record — a dismissal
    // applies to the record you dismissed it on, not to every record afterwards.
    pageContextDismissed: false,
    // The pill behaves like a pre-filled attachment: it attaches to the NEXT message
    // only, then this flips true and the pill disappears — subsequent messages carry
    // no page_context until the bound record actually changes. Distinct from
    // pageContextDismissed (explicit "stop referring to this"): chat:context-updated
    // resets both, but only dismissal is a user action.
    pageContextConsumed: false,

    // Snapshot of the record this message is bound to, in the same shape
    // ListConversationMessages returns after a reload — the optimistic bubble
    // and the reloaded one must render identically. `url` isn't resolvable
    // client-side, so the chip falls back to its non-clickable branch until
    // a reload fills it in from the server.
    activePageContext() {
        if (this.pageContextDismissed || this.pageContextConsumed || !this.pageContext?.type || !this.pageContext?.id) {
            return null;
        }

        return {
            type: this.pageContext.type,
            id: this.pageContext.id,
            label: this.pageContextLabel || this.pageContext.id,
            url: null,
        };
    },

    pageContextIcon() {
        const icons = {
            company: '<svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M4 16.5v-13h-.25a.75.75 0 0 1 0-1.5h12.5a.75.75 0 0 1 0 1.5H16v13h.25a.75.75 0 0 1 0 1.5h-3.5a.75.75 0 0 1-.75-.75v-2.5a1 1 0 0 0-1-1h-2a1 1 0 0 0-1 1v2.5a.75.75 0 0 1-.75.75h-3.5a.75.75 0 0 1 0-1.5H4Zm3-11a.75.75 0 0 1 .75-.75h.5a.75.75 0 0 1 0 1.5h-.5A.75.75 0 0 1 7 5.5Zm4.75-.75a.75.75 0 0 0 0 1.5h.5a.75.75 0 0 0 0-1.5h-.5ZM7 8.5a.75.75 0 0 1 .75-.75h.5a.75.75 0 0 1 0 1.5h-.5A.75.75 0 0 1 7 8.5Zm4.75-.75a.75.75 0 0 0 0 1.5h.5a.75.75 0 0 0 0-1.5h-.5ZM7 11.5a.75.75 0 0 1 .75-.75h.5a.75.75 0 0 1 0 1.5h-.5a.75.75 0 0 1-.75-.75Zm4.75-.75a.75.75 0 0 0 0 1.5h.5a.75.75 0 0 0 0-1.5h-.5Z" clip-rule="evenodd" /></svg>',
            people: '<svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM3.465 14.493a1.23 1.23 0 0 0 .41 1.412A9.957 9.957 0 0 0 10 18c2.31 0 4.438-.784 6.131-2.1.43-.333.604-.903.408-1.41a7.002 7.002 0 0 0-13.074.003Z" /></svg>',
            opportunity: '<svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 10.818v2.614A3.13 3.13 0 0 0 11.888 13c.482-.315.612-.648.612-.875 0-.227-.13-.56-.612-.875a3.13 3.13 0 0 0-1.138-.432ZM8.33 8.62c.053.055.115.11.184.164.208.16.46.284.736.363V6.603a2.45 2.45 0 0 0-.35.13c-.14.065-.27.143-.386.233-.377.292-.514.627-.514.909 0 .184.058.39.202.592.037.051.08.102.128.152Z" /><path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-8-6a.75.75 0 0 1 .75.75v.316a3.78 3.78 0 0 1 1.653.713c.426.33.744.74.925 1.2a.75.75 0 0 1-1.395.55 1.35 1.35 0 0 0-.447-.563 2.187 2.187 0 0 0-.736-.363V9.3c.698.093 1.383.32 1.891.66.533.359 1.017.937 1.017 1.723 0 .74-.4 1.32-.923 1.709a3.945 3.945 0 0 1-1.985.752v.316a.75.75 0 0 1-1.5 0v-.316a3.76 3.76 0 0 1-1.79-.813 3.187 3.187 0 0 1-.933-1.216.75.75 0 1 1 1.38-.59c.09.211.224.4.394.552.28.25.63.418 1 .486V9.7a3.68 3.68 0 0 1-1.786-.756C7.185 8.581 6.75 8 6.75 7.25c0-.79.44-1.377.972-1.79a3.712 3.712 0 0 1 1.528-.694V4.75A.75.75 0 0 1 10 4Z" clip-rule="evenodd" /></svg>',
            task: '<svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>',
            note: '<svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M4.25 2A2.25 2.25 0 0 0 2 4.25v11.5A2.25 2.25 0 0 0 4.25 18h11.5A2.25 2.25 0 0 0 18 15.75V4.25A2.25 2.25 0 0 0 15.75 2H4.25ZM6 6.25a.75.75 0 0 1 .75-.75h6.5a.75.75 0 0 1 0 1.5h-6.5A.75.75 0 0 1 6 6.25Zm.75 3.25a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5ZM6 13.75a.75.75 0 0 1 .75-.75h3.5a.75.75 0 0 1 0 1.5h-3.5a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" /></svg>',
        };

        return icons[this.pageContext?.type] ?? icons.company;
    },

    // Scoped lookup of THIS chat-interface's TipTap editor. Avoids a global
    // that breaks when multiple chat-interface instances render simultaneously
    // (e.g. side panel + main page). Deliberately `document.querySelector`
    // scoped by data-chat-context rather than `this.$root.querySelector`:
    // Livewire's morphdom can briefly detach children from the root during a
    // re-render, which is exactly when sendMessage() needs the editor most.
    localEditor() {
        const ctx = (this.$root || this.$el)?.getAttribute?.('data-chat-context') ?? 'conversation';
        const wrapper = document.querySelector(`[data-chat-context="${ctx}"][x-data*="chatEditor"]`);
        if (! wrapper || ! window.Alpine) return null;
        return window.Alpine.$data(wrapper);
    },

    // Record-aware when the side panel supplies them (so the chips name the record
    // you are looking at); generic fallback on the full-page chat, which has no record.
    starterPrompts: @js($contextPrompts !== [] ? $contextPrompts : [
        ['label' => __('Give me a CRM overview'), 'prompt' => __('Give me a CRM overview')],
        ['label' => __('Show overdue tasks'), 'prompt' => __('Show overdue tasks')],
        ['label' => __('Recent companies'), 'prompt' => __('Recent companies')],
        ['label' => __('Pipeline summary'), 'prompt' => __('Pipeline summary')],
    ]),

    init() {
        this.context = this.$root?.dataset?.chatContext ?? 'conversation';
        this.installConversationSwitchWatch();
        this.initDaySeparatorObserver();

        const validModels = this.modelOptions
            .map((o) => o.value)
            .filter((v) => this.allowedModels.includes(v));
        let stored = null;
        try { stored = localStorage.getItem('chat:model'); } catch (_) { /* ignore */ }
        const candidate = stored || initialModel || 'auto';
        this.selectedModel = validModels.includes(candidate) ? candidate : 'auto';

        this.messages.forEach((m) => this.hydrateServerMessage(m));

        if (this.conversationId) {
            this.subscribeToConversation(this.conversationId);
        }

        // Land at the latest message when reopening an existing conversation.
        // Without this, the messages container starts scrolled to the top
        // (oldest message), forcing the user to scroll down to see context.
        if (this.messages.length > 0) {
            this.scrollToBottom(true);
        }

        // Deferred behind its own $nextTick, queued after scrollToBottom's
        // above, so the observer's first intersection check runs against the
        // settled post-scroll layout rather than a mid-render one.
        this.$nextTick(() => this.initLoadEarlierObserver());

        // Bootstrap payload from the dashboard: when the user submits their
        // first message there, we stash the editor document in sessionStorage
        // and navigate immediately. Restore the document (preserves mentions)
        // and fire sendMessage() so this page does the actual POST without a
        // server round-trip blocking the navigation.
        let ranBootstrapSend = false;
        try {
            const raw = sessionStorage.getItem('chat:bootstrap');
            if (raw && !this.conversationId) {
                sessionStorage.removeItem('chat:bootstrap');
                const parsed = JSON.parse(raw);
                const bootstrapDoc = parsed?.document;
                const bootstrapModel = parsed?.model;

                if (bootstrapModel && this.modelOptions.some((o) => o.value === bootstrapModel)) {
                    this.selectedModel = bootstrapModel;
                }

                if (bootstrapDoc) {
                    ranBootstrapSend = true;
                    this.$nextTick(() => {
                        this.localEditor()?.setDocument?.(bootstrapDoc);
                        this.sendMessage();
                    });
                }
            }
        } catch (_) { /* sessionStorage unavailable or malformed payload */ }

        if (initialMessage) {
            this.$nextTick(() => {
                this.input = initialMessage;
                this.localEditor()?.setText(initialMessage);
                this.sendMessage();
            });
        }

        this.pruneStaleDrafts();

        // A message is about to be sent automatically (bootstrap handoff or a
        // ?prompt= deep link): restoring a leftover draft on top of that would
        // just get overwritten by sendMessage()'s own clear() a moment later,
        // so skip it entirely rather than race the two setDocument calls.
        if (!ranBootstrapSend && !initialMessage) {
            try {
                const legacyDraft = localStorage.getItem('chat:draft');
                if (legacyDraft) {
                    this.input = legacyDraft;
                    this.$nextTick(() => this.localEditor()?.setText(legacyDraft));
                    localStorage.removeItem('chat:draft');
                } else {
                    this.restoreDraft(this.conversationId);
                }
            } catch (_) { /* ignore */ }
        }

        this.beforeUnloadHandler = (e) => {
            if (!this.isStreaming) return;
            // Browsers show their own generic prompt; custom strings are ignored.
            e.preventDefault();
            e.returnValue = '';
        };
        window.addEventListener('beforeunload', this.beforeUnloadHandler);

        this.approvalKeyHandler = (e) => {
            if (!((e.metaKey || e.ctrlKey) && e.key === 'Enter')) return;

            const pending = this.visiblePendingActions();
            if (pending.length !== 1 || this.isStreaming) return;
            if (this.input.trim().length > 0) return; // composer draft wins

            e.preventDefault();
            if (window.Livewire?.dispatch) {
                window.Livewire.dispatch('proposal:create-current', { context: this.context });
            }
        };
        window.addEventListener('keydown', this.approvalKeyHandler);

        this.renamedHandler = (e) => {
            const detail = e.detail || {};
            if (!detail.conversationId || detail.conversationId !== this.conversationId) return;

            // Update document.title for the browser tab.
            document.title = `${detail.title || @js(__('Untitled chat'))} - ${@js(config('app.name'))}`;

            const title = document.querySelector('[data-page-heading]');
            if (title && detail.title) {
                title.textContent = detail.title;
                title.setAttribute('title', detail.title);
            }
        };
        window.addEventListener('chat:renamed', this.renamedHandler);

        // Registered exactly once: init() itself now runs exactly once per
        // mount (the root element's x-data auto-invokes init() on its own;
        // this file used to ALSO carry x-init="init()", which double-called
        // it, doubling every registration below including this one). See the
        // removed x-init attribute's git history for the fix and how it was
        // confirmed.
        this.$wire.$on('chat:messages-prepended', (payload) => {
            const earlier = (payload && payload.messages) || [];
            const hasMore = payload ? !!payload.hasMore : false;
            if (earlier.length > 0) {
                earlier.forEach((m) => this.hydrateServerMessage(m));
                this.messages = [...earlier, ...this.messages];
            }
            this.hasMoreMessages = hasMore;

            this.$nextTick(() => {
                const el = this.$refs.messages;
                if (el && this.prependScrollAnchor !== null) {
                    el.scrollTop = el.scrollHeight - this.prependScrollAnchor;
                    this.prependScrollAnchor = null;
                }
                // Cleared here, after the restore above, not any earlier (e.g.
                // not from loadEarlier()'s own $wire promise settling): see
                // the comment on loadEarlier() in transcript.js for why that
                // ordering matters. Unconditional (outside the `if` above) so
                // a missing ref or an already-null anchor can never leave this
                // stuck true and permanently disable further history loading.
                this.loadingEarlier = false;
            });
        });

        // Bridge the docked livewire proposal-card's resolution lifecycle back
        // into Alpine state. window.Livewire.on returns an unsubscribe fn (v4);
        // named-arg dispatches arrive as a single params object (e.detail).
        // A FAILED resolve needs no bridge: the proposal stays pending, so the dock
        // keeps rendering it and owns the error message (`resolve` error bag).
        this._proposalListeners = [
            window.Livewire.on('proposal:resolved', (payload) => {
                if ((payload?.context ?? 'conversation') !== this.context) return;
                this.applyProposalResolution(payload);
            }),
            // The dock queued a resumed turn (TurnContinuationService). Nothing
            // streams for a second or two while the job picks it up, and without
            // this the composer sits there looking like the approval ended the
            // exchange. Only ever dispatched when a turn really was queued, and
            // the same three exits a sent turn has clear it: stream_end, stream
            // failure, and the watchdog below.
            window.Livewire.on('chat:resuming', (payload) => {
                if ((payload?.context ?? 'conversation') !== this.context) return;
                this.isStreaming = true;
                this.currentToolStatus = null;
                this.startStreamTimeout();
            }),
        ];

    },

    get hasPendingProposal() {
        return this.visiblePendingActions().length > 0;
    },

    destroy() {
        // First line, unconditionally: guards a stream-end/stream-failed
        // continuation already mid-await when this instance is torn down (see
        // the `destroyed` comment in stream.js). Set before any teardown step
        // below that could throw, so the guard is armed even if one does.
        this.destroyed = true;
        this.stashConversationCache();
        this.uninstallConversationSwitchWatch();
        this.teardownDaySeparatorObserver();
        this.teardownLoadEarlierObserver();
        this.teardownMessageSearch();
        this.clearStreamTimeout();
        clearTimeout(this._copiedTimer);
        this.clearRateLimit();
        // Without this, a pending saveDraft() debounce outlives this instance
        // (e.g. a fragment typed here, then the user switches conversations
        // within 400ms via the side panel or a wire:navigate link). The
        // orphaned timer still fires bound to this dead instance's `this`,
        // calls localEditor(), which resolves by context name and finds the
        // NEW live instance mounted under the same context: it reads and
        // saves THAT instance's content under THIS instance's conversation
        // id. Confirmed live: switching A -> B within the debounce window
        // wrote B's private draft text into both chat.draft.<A> and
        // chat.draft.<B>.
        clearTimeout(this.draftDebounceTimer);
        this.unsubscribe();
        window.removeEventListener('beforeunload', this.beforeUnloadHandler);
        window.removeEventListener('chat:renamed', this.renamedHandler);
        window.removeEventListener('keydown', this.approvalKeyHandler);
        (this._proposalListeners || []).forEach((off) => typeof off === 'function' && off());
    },

    get pendingLabel() {
        return this.currentToolStatus ?? @js(__('Thinking…'));
    },

    // Shared by :title and :aria-label on the transcript's edit/regenerate
    // buttons, so the four call sites stay one string each.
    editButtonLabel(index) {
        if (this.rateLimit !== null) return @js(__('Cannot edit: sending too fast'));
        return this.canEdit(index) ? @js(__('Edit message')) : @js(__('Cannot edit: pending approval'));
    },

    regenerateButtonLabel(index) {
        if (this.rateLimit !== null) return @js(__('Cannot regenerate: sending too fast'));
        return this.canRegenerate(index) ? @js(__('Regenerate response')) : @js(__('Cannot regenerate: pending approval'));
    },

    restoreInputFocus() {
        this.$nextTick(() => {
            if (this.messages.some((m) => m.editing)) return;
            this.localEditor()?.focus();
        });
    },
}));
</script>
@endscript
