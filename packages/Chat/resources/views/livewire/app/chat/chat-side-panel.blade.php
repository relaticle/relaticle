<div>
    {{-- Side Panel --}}
    <div
        x-data="{
            open: @entangle('isOpen'),
            {{-- try/catch: storage access throws in Safari private mode, and an
                 exception here would kill the whole component's init. --}}
            width: (() => {
                try {
                    return Math.max(360, Math.min(720, parseInt(localStorage.getItem('chat-panel-width') || '420', 10) || 420));
                } catch (_) {
                    return 420;
                }
            })(),
            minWidth: 360,
            maxWidth: 720,
            resizing: false,
            viewportWidth: window.innerWidth,

            {{-- The panel only learns the id of a brand-new conversation from the
                 client, so the header menu tracks it here rather than round-tripping
                 to the server (which would remount the chat mid-stream). --}}
            currentConversationId: @js($conversationId),
            historyOpen: false,
            historyLoading: false,
            historyError: false,
            historySearch: '',
            historyItems: [],
            menuOpen: false,
            copied: false,

            init() {
                this.$watch('open', (newValue) => {
                    if (newValue === true) {
                        this.$nextTick(() => {
                            window.dispatchEvent(new CustomEvent('chat:focus-editor', { detail: { context: 'side-panel' } }));
                        });
                    } else {
                        this.historyOpen = false;
                        this.menuOpen = false;
                    }
                });

                this.keydownHandler = (e) => {
                    if ((e.metaKey || e.ctrlKey) && e.key === 'j') {
                        e.preventDefault();
                        this.open = !this.open;
                        return;
                    }
                    if (e.key === 'Escape' && (this.historyOpen || this.menuOpen)) {
                        e.preventDefault();
                        this.historyOpen = false;
                        this.menuOpen = false;
                        return;
                    }
                    if (e.key === 'Escape' && this.open) {
                        e.preventDefault();
                        this.open = false;
                    }
                };
                window.addEventListener('keydown', this.keydownHandler);

                this.conversationCreatedHandler = (e) => {
                    if (e.detail?.id) {
                        this.currentConversationId = e.detail.id;
                    }
                };
                window.addEventListener('chat:conversation-created', this.conversationCreatedHandler);

                this.resizeHandler = () => { this.viewportWidth = window.innerWidth; };
                window.addEventListener('resize', this.resizeHandler);
            },

            destroy() {
                window.removeEventListener('keydown', this.keydownHandler);
                window.removeEventListener('chat:conversation-created', this.conversationCreatedHandler);
                window.removeEventListener('resize', this.resizeHandler);
            },

            get filteredHistory() {
                const needle = this.historySearch.trim().toLowerCase();

                if (!needle) {
                    return this.historyItems;
                }

                return this.historyItems.filter((item) => (item.title || '').toLowerCase().includes(needle));
            },

            async toggleHistory() {
                this.menuOpen = false;
                this.historyOpen = !this.historyOpen;

                if (!this.historyOpen) {
                    return;
                }

                this.historySearch = '';
                this.$nextTick(() => this.$refs.historySearch?.focus());
                await this.loadHistory();
            },

            async loadHistory() {
                this.historyLoading = true;
                this.historyError = false;

                try {
                    const res = await fetch(@js(route('chat.conversations')), {
                        headers: { 'Accept': 'application/json' },
                    });

                    if (!res.ok) {
                        throw new Error('failed');
                    }

                    this.historyItems = (await res.json()).data ?? [];
                } catch (_) {
                    this.historyItems = [];
                    this.historyError = true;
                } finally {
                    this.historyLoading = false;
                }
            },

            async selectConversation(id) {
                this.historyOpen = false;
                this.currentConversationId = id;
                await $wire.openConversation(id);
                window.dispatchEvent(new CustomEvent('chat:focus-editor', { detail: { context: 'side-panel' } }));
            },

            async startNewChat() {
                this.historyOpen = false;
                this.menuOpen = false;
                this.currentConversationId = null;
                await $wire.startNewConversation();
            },

            {{-- Null on tenant-less pages, where the chat routes cannot be resolved. --}}
            fullPageUrl() {
                if (this.currentConversationId && @js($conversationUrlTemplate)) {
                    return @js($conversationUrlTemplate).replace(@js($conversationUrlPlaceholder), this.currentConversationId);
                }

                return @js($newChatUrl);
            },

            openInFullPage() {
                const url = this.fullPageUrl();

                if (!url) {
                    return;
                }

                this.menuOpen = false;
                window.location.href = url;
            },

            async copyConversationId() {
                if (!this.currentConversationId) {
                    return;
                }

                try {
                    await navigator.clipboard.writeText(this.currentConversationId);
                    this.copied = true;
                    setTimeout(() => { this.copied = false; }, 1500);
                } catch (_) { /* clipboard blocked: nothing useful to fall back to */ }
            },

            async deleteCurrentConversation() {
                if (!this.currentConversationId) {
                    return;
                }

                if (!window.confirm(@js(__('Delete this chat? Messages and any pending actions will be removed.')))) {
                    return;
                }

                const id = this.currentConversationId;
                this.menuOpen = false;
                this.currentConversationId = null;
                this.historyItems = this.historyItems.filter((item) => item.id !== id);
                await $wire.deleteConversation(id);
            },

            startResize(e) {
                this.resizing = true;
                const startX = e.clientX;
                const startWidth = this.width;

                {{-- Pointer events + capture: works for touch/pen too, and the
                     drag can never strand over an iframe or outside the window. --}}
                try { e.target.setPointerCapture(e.pointerId); } catch (_) { /* unsupported */ }

                const onPointerMove = (moveEvent) => {
                    const delta = startX - moveEvent.clientX;
                    this.width = Math.max(this.minWidth, Math.min(this.maxWidth, startWidth + delta));
                };

                const onPointerUp = () => {
                    this.resizing = false;
                    try { localStorage.setItem('chat-panel-width', this.width.toString()); } catch (_) { /* ignore */ }
                    document.removeEventListener('pointermove', onPointerMove);
                    document.removeEventListener('pointerup', onPointerUp);
                };

                document.addEventListener('pointermove', onPointerMove);
                document.addEventListener('pointerup', onPointerUp);
            }
        }"
        x-show="open"
        x-transition:enter="motion-safe:transition motion-safe:ease-out motion-safe:duration-200"
        x-transition:enter-start="motion-safe:translate-x-full"
        x-transition:enter-end="motion-safe:translate-x-0"
        x-transition:leave="motion-safe:transition motion-safe:ease-in motion-safe:duration-150"
        x-transition:leave-start="motion-safe:translate-x-0"
        x-transition:leave-end="motion-safe:translate-x-full"
        x-cloak
        role="dialog"
        aria-modal="false"
        aria-label="{{ __('Chat side panel') }}"
        tabindex="-1"
        class="fixed inset-y-0 right-0 z-50 flex max-w-full"
        :style="{ width: (viewportWidth < 640 ? viewportWidth : width) + 'px' }"
        data-chat-side-panel
    >
        {{-- Resize Handle: a grabbable strip that stays invisible, so the panel's
             own 1px border is the only line the eye sees. --}}
        <div
            @pointerdown="startResize($event)"
            x-show="viewportWidth >= 640"
            class="w-1 cursor-col-resize bg-transparent transition-colors hover:bg-primary-400/60 dark:hover:bg-primary-500/60"
            :class="{ 'bg-primary-500/80': resizing }"
            aria-hidden="true"
        ></div>

        {{-- Panel Content --}}
        <div class="flex flex-1 flex-col overflow-hidden border-l border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-900">
            {{-- Panel Header --}}
            <div class="flex items-center justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                <div class="flex min-w-0 items-center gap-2">
                    <x-heroicon-o-chat-bubble-left-right class="h-5 w-5 shrink-0 text-primary-600 dark:text-primary-400" />
                    <h3 class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ config('chat.assistant_name') }}</h3>
                </div>

                <div class="flex shrink-0 items-center gap-0.5">
                    {{-- Chat history --}}
                    <div class="relative" @click.outside="historyOpen = false">
                        <button
                            type="button"
                            @click="toggleHistory()"
                            aria-label="{{ __('View history') }}"
                            title="{{ __('View history') }}"
                            :aria-expanded="historyOpen"
                            class="flex h-7 w-7 items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                            :class="{ 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300': historyOpen }"
                        >
                            <x-ri-history-line class="h-4 w-4" aria-hidden="true" />
                        </button>

                        <div
                            x-show="historyOpen"
                            x-cloak
                            x-transition:enter="motion-safe:transition motion-safe:ease-out motion-safe:duration-100"
                            x-transition:enter-start="motion-safe:opacity-0 motion-safe:scale-95"
                            x-transition:enter-end="motion-safe:opacity-100 motion-safe:scale-100"
                            role="dialog"
                            aria-label="{{ __('Chat history') }}"
                            class="absolute end-0 z-20 mt-1.5 w-80 max-w-[calc(100vw-2rem)] origin-top-right overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-900"
                        >
                            <div class="flex items-center gap-2 border-b border-gray-200 px-3 py-2 dark:border-gray-700">
                                <x-heroicon-o-magnifying-glass class="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
                                <input
                                    x-ref="historySearch"
                                    x-model="historySearch"
                                    type="search"
                                    placeholder="{{ __('Search history...') }}"
                                    aria-label="{{ __('Search chat history') }}"
                                    class="w-full border-0 bg-transparent p-0 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-0 dark:text-white"
                                />
                                <button
                                    type="button"
                                    @click="startNewChat()"
                                    aria-label="{{ __('New chat') }}"
                                    title="{{ __('New chat') }}"
                                    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-primary-600 dark:hover:bg-white/5 dark:hover:text-primary-400"
                                >
                                    <x-heroicon-o-plus class="h-4 w-4" aria-hidden="true" />
                                </button>
                            </div>

                            <div class="max-h-72 overflow-y-auto py-1">
                                <template x-if="historyLoading">
                                    <p class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400" role="status">{{ __('Loading…') }}</p>
                                </template>

                                <template x-if="!historyLoading && historyError">
                                    <p class="px-3 py-2 text-xs text-danger-600 dark:text-danger-400" role="status">
                                        {{ __('Could not load your chats. Try again.') }}
                                    </p>
                                </template>

                                <template x-if="!historyLoading && !historyError && filteredHistory.length === 0">
                                    <p class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400" role="status">
                                        <span x-show="historySearch.trim()">{{ __('No matches.') }}</span>
                                        <span x-show="!historySearch.trim()">{{ __("No chats yet. Ask about a deal, a contact, or what's overdue.") }}</span>
                                    </p>
                                </template>

                                <template x-for="item in filteredHistory" :key="item.id">
                                    <button
                                        type="button"
                                        @click="selectConversation(item.id)"
                                        class="flex w-full items-center gap-2 px-3 py-2 text-start text-sm text-gray-700 transition hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-500 dark:text-gray-200 dark:hover:bg-white/5"
                                        :class="{ 'bg-gray-50 font-medium dark:bg-white/5': item.id === currentConversationId }"
                                        :aria-current="item.id === currentConversationId ? 'true' : null"
                                    >
                                        <x-heroicon-o-chat-bubble-left class="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
                                        <span class="truncate" x-text="item.title || @js(__('Untitled chat'))" :title="item.title || @js(__('Untitled chat'))"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>

                    {{-- Chat actions --}}
                    <div class="relative" @click.outside="menuOpen = false">
                        <button
                            type="button"
                            @click="menuOpen = !menuOpen; historyOpen = false"
                            aria-label="{{ __('Chat actions') }}"
                            title="{{ __('Chat actions') }}"
                            :aria-expanded="menuOpen"
                            class="flex h-7 w-7 items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                            :class="{ 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300': menuOpen }"
                        >
                            <x-heroicon-o-ellipsis-vertical class="h-4 w-4" aria-hidden="true" />
                        </button>

                        <div
                            x-show="menuOpen"
                            x-cloak
                            x-transition:enter="motion-safe:transition motion-safe:ease-out motion-safe:duration-100"
                            x-transition:enter-start="motion-safe:opacity-0 motion-safe:scale-95"
                            x-transition:enter-end="motion-safe:opacity-100 motion-safe:scale-100"
                            role="menu"
                            aria-label="{{ __('Chat actions') }}"
                            class="absolute end-0 z-20 mt-1.5 w-56 origin-top-right overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-900"
                        >
                            <button
                                type="button"
                                role="menuitem"
                                @click="openInFullPage()"
                                x-show="fullPageUrl()"
                                class="flex w-full items-center gap-2.5 px-3 py-2 text-start text-sm text-gray-700 transition hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-500 dark:text-gray-200 dark:hover:bg-white/5"
                            >
                                <x-heroicon-o-arrows-pointing-out class="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
                                {{ __('Open in full page') }}
                            </button>

                            <button
                                type="button"
                                role="menuitem"
                                @click="copyConversationId()"
                                x-show="currentConversationId"
                                class="flex w-full items-center gap-2.5 px-3 py-2 text-start text-sm text-gray-700 transition hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-500 dark:text-gray-200 dark:hover:bg-white/5"
                            >
                                <x-heroicon-o-document-duplicate class="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
                                <span x-text="copied ? @js(__('Copied!')) : @js(__('Copy chat ID'))"></span>
                            </button>

                            <div x-show="currentConversationId" class="my-1 border-t border-gray-200 dark:border-gray-700"></div>

                            <button
                                type="button"
                                role="menuitem"
                                @click="deleteCurrentConversation()"
                                x-show="currentConversationId"
                                class="flex w-full items-center gap-2.5 px-3 py-2 text-start text-sm text-danger-600 transition hover:bg-danger-50 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-danger-500 dark:text-danger-400 dark:hover:bg-danger-500/10"
                            >
                                <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Delete') }}
                            </button>
                        </div>
                    </div>

                    <button
                        @click="open = false"
                        type="button"
                        aria-label="{{ __('Close chat panel') }}"
                        class="flex h-7 w-7 items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    >
                        <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>
            </div>

            {{-- Chat Content Area. min-h-0 (not overflow-y-auto): the chat
                 interface owns its own transcript scroller ($refs.messages), and
                 a second scroll container here would be the one that actually
                 scrolls, stranding the sticky date/jump pills and the
                 pinned-to-bottom tracking inside a box that never moves. --}}
            <div class="min-h-0 flex-1" data-chat-messages>
                @livewire('chat.chat-interface', [
                    'conversationId' => $conversationId,
                    'context' => 'side-panel',
                    'pageContextType' => $recordType,
                    'pageContextId' => $recordId,
                    'pageContextLabel' => $recordName,
                    'contextPrompts' => $starterPrompts,
                ], key('side-panel-chat-' . ($conversationId ?? 'new')))
            </div>
        </div>
    </div>

    {{-- Toggle button is now rendered in the topbar via GLOBAL_SEARCH_BEFORE render hook (chat-topbar-toggle-hook). Cmd+J keyboard shortcut still works via the keydown handler above. --}}
</div>
