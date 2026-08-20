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
    data-chat-context-name="{{ $context ?? 'conversation' }}"
    class="relative flex h-full flex-col"
>
    @include('chat::livewire.chat.partials._transcript')

    {{-- Jump-to-latest pill: new content arrived while reading older messages --}}
    <template x-if="hasUnseenBelow">
        <div class="pointer-events-none absolute inset-x-0 bottom-28 z-30 flex justify-center">
            <button
                type="button"
                x-on:click="jumpToLatest()"
                class="pointer-events-auto flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-lg transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
            >
                <x-heroicon-o-arrow-down class="h-3.5 w-3.5" aria-hidden="true" />
                New messages
            </button>
        </div>
    </template>

    @include('chat::livewire.chat.partials._composer')

    {{-- Full-page chat only: the switcher never opens on the side panel's own
         nested chatInterface instance (see onChatRootKeydown's context guard),
         and rendering the overlay markup there too would leave two identical
         role="dialog" aria-label="Switch conversation" nodes on every page
         that hosts the side panel, i.e. nearly every app page. --}}
    @if (($context ?? 'conversation') === 'conversation')
        @include('chat::livewire.chat.partials._switcher')
    @endif
</div>

@script
<script>
Alpine.data('chatInterface', (initialConversationId, sendUrl, initialMessage, initialMessages, userId, initialHasMoreMessages, initialModel) => ({
    ...window.ChatModules.transcriptModule({
        messagesUrl: @js(url('/chat/messages')),
        todayLabel: @js(__('Today')),
        yesterdayLabel: @js(__('Yesterday')),
        feedbackDeleteConfirmText: @js(__('Remove this feedback? Your category and comment will be deleted.')),
    }),
    ...window.ChatModules.sendModule({
        sendUrl,
        createConversationUrl: @js(route('chat.conversations.create')),
        conversationsUrl: @js(url('/chat/conversations')),
    }),
    ...window.ChatModules.streamModule(),

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
    switcherConversationUrlPlaceholder: '__CONVERSATION_ID__',
    messages: initialMessages || [],
    hasMoreMessages: !!initialHasMoreMessages,
    isStreaming: false,
    currentPlan: @js(auth()->user()?->currentTeam?->plan?->value ?? \App\Enums\Plan::default()->value),
    currentPlanLabel: @js(auth()->user()?->currentTeam?->plan?->label() ?? \App\Enums\Plan::default()->label()),
    allowedModels: @js(app(\Relaticle\Chat\Services\ModelRegistry::class)->allowedIdsFor(auth()->user()?->currentTeam?->plan ?? \App\Enums\Plan::default())),
    selectedModel: 'auto',
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

    localEditor() {
        const ctx = (this.$root || this.$el)?.getAttribute?.('data-chat-context') ?? 'conversation';
        const wrapper = document.querySelector(`[data-chat-context="${ctx}"][x-data*="chatEditor"]`);
        if (! wrapper || ! window.Alpine) return null;
        return window.Alpine.$data(wrapper);
    },

    modelOptions: @js(app(\Relaticle\Chat\Services\ModelRegistry::class)->pickerOptions()),

    ...window.ChatModules.modelPickerModule({
        providerIcons: @js([
            'anthropic' => svg('ri-claude-fill')->toHtml(),
            'openai' => svg('ri-openai-fill')->toHtml(),
            'ollama' => svg('ri-server-line')->toHtml(),
            'selfhosted' => svg('ri-server-line')->toHtml(),
        ]),
    }),

    selectModel(value) {
        if (! this.allowedModels.includes(value)) {
            window.dispatchEvent(new CustomEvent('chat:model-locked', {
                detail: { model: value, plan: this.currentPlan, planLabel: this.currentPlanLabel },
            }));
            return;
        }
        this.selectedModel = value;
        try { localStorage.setItem('chat:model', value); } catch (_) { /* ignore */ }
    },

    // Record-aware when the side panel supplies them (so the chips name the record
    // you are looking at); generic fallback on the full-page chat, which has no record.
    starterPrompts: @js($contextPrompts !== [] ? $contextPrompts : [
        ['label' => 'Give me a CRM overview', 'prompt' => 'Give me a CRM overview'],
        ['label' => 'Show overdue tasks', 'prompt' => 'Show overdue tasks'],
        ['label' => 'Recent companies', 'prompt' => 'Recent companies'],
        ['label' => 'Pipeline summary', 'prompt' => 'Pipeline summary'],
    ]),

    init() {
        this.context = this.$root?.dataset?.chatContextName ?? 'conversation';
        this.installConversationSwitchWatch();
        this.initDaySeparatorObserver();

        const validModels = this.modelOptions
            .map((o) => o.value)
            .filter((v) => this.allowedModels.includes(v));
        let stored = null;
        try { stored = localStorage.getItem('chat:model'); } catch (_) { /* ignore */ }
        const candidate = stored || initialModel || 'auto';
        this.selectedModel = validModels.includes(candidate) ? candidate : 'auto';

        this.messages.forEach((m) => {
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
            if (typeof m.copiedAt === 'undefined') {
                m.copiedAt = 0;
            }
        });

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
            e.preventDefault();
            e.returnValue = 'Your message is still being generated. Leave anyway?';
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
            document.title = `${detail.title || 'Untitled'} - Relaticle`;

            // Update the visible H1 if present (Filament page header).
            const h1 = document.querySelector('main h1');
            if (h1 && detail.title) {
                h1.textContent = detail.title;
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
                earlier.forEach((m) => {
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
                    if (typeof m.copiedAt === 'undefined') {
                        m.copiedAt = 0;
                    }
                });
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
        ];

    },

    get hasPendingProposal() {
        return this.visiblePendingActions().length > 0;
    },

    destroy() {
        this.stashConversationCache();
        this.uninstallConversationSwitchWatch();
        this.teardownDaySeparatorObserver();
        this.teardownLoadEarlierObserver();
        this.clearStreamTimeout();
        this.stopCopyTicker();
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
        return this.currentToolStatus ?? 'Thinking…';
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
