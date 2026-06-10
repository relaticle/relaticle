<div>
    <div
        x-data="{
            open: @entangle('isOpen'),
            init() {
                this.keydownHandler = (e) => {
                    if (e.key === 'Escape' && this.open) {
                        e.preventDefault();
                        $wire.close();
                    }
                };
                window.addEventListener('keydown', this.keydownHandler);
            },
            destroy() {
                window.removeEventListener('keydown', this.keydownHandler);
            }
        }"
        x-effect="if (open) $nextTick(() => $el.querySelector('input[type=search]')?.focus())"
        x-show="open"
        x-cloak
        role="dialog"
        aria-modal="false"
        aria-label="All chats"
        tabindex="-1"
        class="fi-chat-all-chats-panel fixed inset-y-0 left-[var(--fi-sidebar-width,_280px)] z-40 flex w-[360px] max-w-full"
        data-chat-all-chats-panel
    >
        {{-- Panel body --}}
        <div
            @click.outside="if (open) $wire.close()"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="-translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="-translate-x-full"
            class="relative flex flex-1 flex-col overflow-hidden border-r border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-900"
        >
            {{-- Header --}}
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Chats</h3>

                <div class="flex items-center gap-1">
                    <a
                        href="{{ $newChatUrl }}"
                        wire:navigate
                        @click="$wire.close()"
                        aria-label="New chat"
                        title="New chat"
                        class="flex h-7 w-7 items-center justify-center rounded-md text-gray-500 transition hover:bg-gray-100 hover:text-primary-600 dark:hover:bg-white/5 dark:hover:text-primary-400"
                    >
                        <x-heroicon-o-plus class="h-4 w-4" />
                    </a>

                    <button
                        type="button"
                        @click="$wire.close()"
                        aria-label="Close all chats panel"
                        class="flex h-7 w-7 items-center justify-center rounded-md text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/5 dark:hover:text-gray-200"
                    >
                        <x-heroicon-o-x-mark class="h-4 w-4" />
                    </button>
                </div>
            </div>

            {{-- Search --}}
            <div class="border-b border-gray-200 px-3 py-2 dark:border-gray-700">
                <input
                    type="search"
                    wire:model.live.debounce.250ms="search"
                    placeholder="Search chats..."
                    aria-label="Search chats"
                    class="w-full rounded-md border border-gray-200 bg-white px-2 py-1.5 text-sm text-gray-900 placeholder-gray-400 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                />
            </div>

            {{-- List --}}
            <ul class="flex-1 overflow-y-auto py-1">
                @if($conversations->isEmpty())
                    <li class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400" role="status">
                        @if($isSearching)
                            No matches.
                        @else
                            No chats yet. Start one with the + button above.
                        @endif
                    </li>
                @else
                    @foreach($conversations as $conversation)
                        @php
                            $chatUrl = \App\Filament\Pages\ChatConversation::getUrl(['conversationId' => $conversation->id]);
                            $renameUrl = route('chat.rename', ['conversationId' => $conversation->id]);
                            $displayTitle = \Illuminate\Support\Str::limit($conversation->title ?: 'Untitled chat', 40);
                            $rawTitle = $conversation->title ?: 'Untitled chat';
                        @endphp
                        <li
                            x-data="{
                                editing: false,
                                renamed: '',
                                async save() {
                                    const text = this.renamed.trim();
                                    if (!text) { this.editing = false; return; }
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
                                        }
                                    } catch (_) { /* network errors silently abort */ }
                                    this.editing = false;
                                },
                                startEdit() {
                                    this.renamed = @js($rawTitle);
                                    this.editing = true;
                                }
                            }"
                            class="group/chat-item relative"
                        >
                            <template x-if="!editing">
                                <a
                                    href="{{ $chatUrl }}"
                                    wire:navigate
                                    @click="$wire.close()"
                                    class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
                                >
                                    <x-heroicon-o-chat-bubble-left class="h-4 w-4 text-gray-400" />
                                    <span data-title class="truncate pe-16">{{ $displayTitle }}</span>
                                </a>
                            </template>

                            <template x-if="editing">
                                <form
                                    @submit.prevent="save()"
                                    class="flex items-center gap-2 px-4 py-1.5"
                                >
                                    <input
                                        type="text"
                                        x-model="renamed"
                                        @keydown.escape.prevent="editing = false"
                                        @click.stop
                                        @blur="editing = false"
                                        x-init="$nextTick(() => { $el.focus(); $el.select(); })"
                                        maxlength="255"
                                        aria-label="Rename chat"
                                        class="w-full rounded border border-gray-300 px-2 py-1 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                    />
                                </form>
                            </template>

                            <button
                                type="button"
                                @click.stop.prevent="startEdit()"
                                x-show="!editing"
                                aria-label="Rename chat"
                                title="Rename chat"
                                class="absolute inset-y-0 end-9 my-auto flex h-6 w-6 items-center justify-center rounded-md text-gray-400 opacity-0 transition hover:bg-gray-100 hover:text-primary-600 focus:opacity-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 group-hover/chat-item:opacity-100 dark:hover:bg-white/5 dark:hover:text-primary-400"
                            >
                                <x-heroicon-o-pencil-square class="h-4 w-4" />
                            </button>

                            <button
                                type="button"
                                wire:click="deleteConversation(@js($conversation->id))"
                                wire:confirm="Delete this chat? Messages and any pending actions will be removed."
                                x-show="!editing"
                                aria-label="Delete chat"
                                title="Delete chat"
                                class="absolute inset-y-0 end-3 my-auto flex h-6 w-6 items-center justify-center rounded-md text-gray-400 opacity-0 transition hover:bg-gray-100 hover:text-danger-600 focus:opacity-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 group-hover/chat-item:opacity-100 dark:hover:bg-white/5 dark:hover:text-danger-400"
                            >
                                <x-heroicon-o-trash class="h-4 w-4" />
                            </button>
                        </li>
                    @endforeach
                @endif
            </ul>
        </div>
    </div>
</div>
