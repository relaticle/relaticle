<li
    x-data="{
        label: 'Chats',
        onKeydown(e) {
            const tag = e.target?.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || e.target?.isContentEditable) return;
            if (e.metaKey || e.ctrlKey || e.altKey || e.shiftKey) return;
            {{-- Bare keys must never fire while a dialog, dropdown, or the chat
                 side panel owns the interaction. Quoteless attribute selectors
                 are load-bearing: this sits inside a double-quoted x-data
                 attribute. getComputedStyle (not offsetParent, which is always
                 null for position:fixed) detects the panel's display:none. --}}
            if (e.target?.closest?.('[role=dialog], [role=menu], [role=listbox], .fi-modal')) return;
            const sidePanel = document.querySelector('[data-chat-side-panel]');
            if (sidePanel && getComputedStyle(sidePanel).display !== 'none') return;
            if (document.querySelector('.fi-modal-open')) return;

            if (e.key === 'n') {
                e.preventDefault();
                window.Livewire?.navigate
                    ? window.Livewire.navigate(@js(\App\Filament\Pages\Dashboard::getUrl()))
                    : (window.location = @js(\App\Filament\Pages\Dashboard::getUrl()));
                return;
            }

            if (e.key !== 'j' && e.key !== 'k') return;

            const chats = $el.querySelectorAll('a[wire\\:navigate]');
            if (chats.length === 0) return;
            const active = $el.querySelector('.fi-active a[wire\\:navigate]');
            const list = Array.from(chats);
            const idx = active ? list.indexOf(active) : -1;
            const next = e.key === 'j'
                ? Math.min(list.length - 1, idx + 1)
                : Math.max(0, idx - 1);
            e.preventDefault();
            list[next]?.click();
        }
    }"
    @keydown.window="onKeydown($event)"
    data-group-label="{{ __('Chats') }}"
    x-bind:class="{ 'fi-collapsed': $store.sidebar.groupIsCollapsed(label) }"
    {{-- -ml-2 matches Filament's .fi-sidebar-nav-groups list which has margin-left: -8px;
         this hook renders as a sibling of that list inside .fi-sidebar-nav, so without
         the offset our chat icons sit 8px to the right of Home/People/Companies/etc. --}}
    class="fi-sidebar-group fi-collapsible -ml-2"
>
    {{-- Group header --}}
    <div
        x-on:click="$store.sidebar.toggleCollapsedGroup(label)"
        x-show="$store.sidebar.isOpen"
        x-transition:enter="fi-transition-enter"
        x-transition:enter-start="fi-transition-enter-start"
        x-transition:enter-end="fi-transition-enter-end"
        class="fi-sidebar-group-btn"
    >
        <span class="fi-sidebar-group-label">{{ __('Chats') }}</span>

        <x-filament::icon-button
            color="gray"
            :icon="\Filament\Support\Icons\Heroicon::ChevronUp"
            label="{{ __('Chats') }}"
            x-bind:aria-expanded="! $store.sidebar.groupIsCollapsed(label)"
            x-on:click.stop="$store.sidebar.toggleCollapsedGroup(label)"
            class="fi-sidebar-group-collapse-btn"
        />
    </div>

    {{-- Conversation items --}}
    <ul
        x-show="$store.sidebar.isOpen ? ! $store.sidebar.groupIsCollapsed(label) : true"
        x-collapse.duration.200ms
        x-transition:enter="fi-transition-enter"
        x-transition:enter-start="fi-transition-enter-start"
        x-transition:enter-end="fi-transition-enter-end"
        class="fi-sidebar-group-items"
    >
        @if($conversations->isEmpty())
            <li
                x-show="$store.sidebar.isOpen"
                class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400"
                role="status"
            >
                {{ __("No chats yet. Ask about a deal, a contact, or what's overdue.") }}
            </li>
        @else
            @foreach($conversations as $conversation)
                @php
                    $chatUrl = \App\Filament\Pages\ChatConversation::getUrl(['conversationId' => $conversation->id]);
                    $isActive = request()->url() === $chatUrl;
                    $renameUrl = route('chat.rename', ['conversationId' => $conversation->id]);
                    $displayTitle = \Illuminate\Support\Str::limit($conversation->title ?: __('Untitled chat'), 30);
                    $rawTitle = $conversation->title ?: __('Untitled chat');
                @endphp
                <li
                    x-data="{
                        editing: false,
                        renamed: '',
                        saving: false,
                        {{-- Blur commits (Notion/Linear convention): Escape is the
                             only cancel. The guards make the Enter-then-unmount blur
                             and the Escape-then-blur sequences single-shot no-ops. --}}
                        async save() {
                            if (!this.editing || this.saving) return;
                            const text = this.renamed.trim();
                            if (!text || text === @js($rawTitle)) { this.editing = false; return; }
                            this.saving = true;
                            try {
                                const res = await fetch(@js($renameUrl), {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'Accept': 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name=\'csrf-token\']')?.getAttribute('content') || '',
                                    },
                                    body: JSON.stringify({ title: text }),
                                });
                                if (res.ok) {
                                    const body = await res.json();
                                    const titleEl = $el.querySelector('[data-title]');
                                    if (titleEl) titleEl.textContent = body.title;

                                    // Notify the conversation page H1 (Alpine listener).
                                    window.dispatchEvent(new CustomEvent('chat:renamed', {
                                        detail: {
                                            conversationId: body.conversation_id,
                                            title: body.title,
                                        },
                                    }));

                                    // Notify the Livewire parent so its $refresh sees fresh state on the next render.
                                    if (window.Livewire?.dispatch) {
                                        window.Livewire.dispatch('chat:conversation-renamed', {
                                            conversationId: body.conversation_id,
                                            title: body.title,
                                        });
                                    }
                                }
                            } catch (_) { /* network errors silently abort */ }
                            this.saving = false;
                            this.editing = false;
                        },
                        startEdit() {
                            this.renamed = @js($rawTitle);
                            this.editing = true;
                        }
                    }"
                    x-show="$store.sidebar.isOpen"
                    @class([
                        'fi-sidebar-item group/chat-item relative',
                        'fi-active' => $isActive,
                    ])
                >
                    <template x-if="!editing">
                        <a
                            href="{{ $chatUrl }}"
                            wire:navigate
                            class="fi-sidebar-item-btn pe-16"
                        >
                            <x-heroicon-o-chat-bubble-left class="fi-icon fi-size-lg fi-sidebar-item-icon" />
                            <span
                                data-title
                                x-show="$store.sidebar.isOpen"
                                x-transition:enter="fi-transition-enter"
                                x-transition:enter-start="fi-transition-enter-start"
                                x-transition:enter-end="fi-transition-enter-end"
                                title="{{ $rawTitle }}"
                                class="fi-sidebar-item-label truncate"
                            >
                                {{ $displayTitle }}
                            </span>
                        </a>
                    </template>

                    <template x-if="editing">
                        <form
                            @submit.prevent="save()"
                            class="flex items-center gap-2 px-3 py-1.5"
                        >
                            <input
                                type="text"
                                x-model="renamed"
                                @keydown.escape.prevent="editing = false"
                                @click.stop
                                @blur="save()"
                                x-init="$nextTick(() => { $el.focus(); $el.select(); })"
                                maxlength="255"
                                aria-label="{{ __('Rename chat') }}"
                                class="w-full rounded-md border border-gray-200 bg-white px-2 py-1 text-sm text-gray-900 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            />
                        </form>
                    </template>

                    <button
                        type="button"
                        @click.stop.prevent="startEdit()"
                        x-show="$store.sidebar.isOpen && !editing"
                        aria-label="{{ __('Rename chat') }}"
                        title="{{ __('Rename chat') }}"
                        class="absolute inset-y-0 end-7 my-auto flex h-6 w-6 items-center justify-center rounded-md text-gray-400 opacity-0 transition hover:bg-gray-100 hover:text-primary-600 focus:opacity-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 group-hover/chat-item:opacity-100 dark:hover:bg-white/5 dark:hover:text-primary-400"
                    >
                        <x-heroicon-o-pencil-square class="h-4 w-4" />
                    </button>

                    <button
                        type="button"
                        wire:click="deleteConversation(@js($conversation->id))"
                        wire:confirm="{{ __('Delete this chat? Messages and any pending actions will be removed.') }}"
                        x-show="$store.sidebar.isOpen && !editing"
                        aria-label="{{ __('Delete chat') }}"
                        title="{{ __('Delete chat') }}"
                        class="absolute inset-y-0 end-1 my-auto flex h-6 w-6 items-center justify-center rounded-md text-gray-400 opacity-0 transition hover:bg-gray-100 hover:text-danger-600 focus:opacity-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 group-hover/chat-item:opacity-100 dark:hover:bg-white/5 dark:hover:text-danger-400"
                    >
                        <x-heroicon-o-trash class="h-4 w-4" />
                    </button>
                </li>
            @endforeach

            @if($hasMore)
                <li
                    x-show="$store.sidebar.isOpen"
                    class="fi-sidebar-item"
                >
                    <button
                        type="button"
                        @click="window.dispatchEvent(new CustomEvent('chat:open-all-chats'))"
                        class="fi-sidebar-item-btn w-full text-start opacity-60 transition hover:opacity-100"
                        aria-label="{{ __('Open all chats') }}"
                    >
                        <x-heroicon-o-ellipsis-horizontal class="fi-icon fi-size-lg fi-sidebar-item-icon" />
                        <span
                            x-show="$store.sidebar.isOpen"
                            x-transition:enter="fi-transition-enter"
                            x-transition:enter-start="fi-transition-enter-start"
                            x-transition:enter-end="fi-transition-enter-end"
                            class="fi-sidebar-item-label"
                        >
                            {{ __('All chats') }}
                        </span>
                    </button>
                </li>
            @endif
        @endif
    </ul>
</li>
