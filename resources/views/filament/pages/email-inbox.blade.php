<x-filament-panels::page class="!pb-0">
    <div
        class="flex overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm h-[78vh]"
    >

        {{-- ── Left sidebar: folder navigation ───────────────────────────── --}}
        <div class="flex w-56 shrink-0 flex-col border-r border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40">

            {{-- Compose button --}}
            @if ($this->hasActiveConnectedAccount())
                <div class="shrink-0 p-4">
                    <button
                        wire:click="mountAction('composeEmail')"
                        type="button"
                        class="flex w-full items-center justify-center gap-2 rounded-2xl bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-primary-700 active:bg-primary-800 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
                    >
                        <x-heroicon-o-pencil-square class="h-4 w-4" />
                        Compose
                    </button>
                </div>
            @else
                <div class="shrink-0 p-4">
                    <div class="flex w-full items-center justify-center gap-2 rounded-2xl bg-gray-200 dark:bg-gray-700 px-4 py-2.5 text-sm font-semibold text-gray-400 dark:text-gray-500 cursor-not-allowed">
                        <x-heroicon-o-pencil-square class="h-4 w-4" />
                        Compose
                    </div>
                </div>
            @endif

            {{-- Folder nav --}}
            <nav class="flex-1 overflow-y-auto px-2 pb-4 space-y-0.5">

                @php
                    $navItems = [
                        [
                            'folder' => 'inbox',
                            'label'  => 'Inbox',
                            'icon'   => 'heroicon-o-inbox',
                            'badge'  => $this->inboxUnreadCount > 0 ? $this->inboxUnreadCount : null,
                        ],
                        [
                            'folder' => 'sent',
                            'label'  => 'Sent',
                            'icon'   => 'heroicon-o-paper-airplane',
                            'badge'  => null,
                        ],
                        [
                            'folder' => 'all',
                            'label'  => 'All Mail',
                            'icon'   => 'heroicon-o-squares-2x2',
                            'badge'  => null,
                        ],
                        [
                            'folder' => 'drafts',
                            'label'  => 'Drafts',
                            'icon'   => 'heroicon-o-document',
                            'badge'  => null,
                        ],
                        [
                            'folder' => 'archive',
                            'label'  => 'Archive',
                            'icon'   => 'heroicon-o-archive-box',
                            'badge'  => null,
                        ],
                    ];
                @endphp

                @foreach ($navItems as $item)
                    @php $isActive = $folder->value === $item['folder']; @endphp
                    <button
                        wire:click="setFolder('{{ $item['folder'] }}')"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-60"
                        type="button"
                        @class([
                            'flex w-full items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-colors focus:outline-none',
                            'bg-primary-100 dark:bg-primary-900/40 text-primary-700 dark:text-primary-300' => $isActive,
                            'text-gray-600 dark:text-gray-400 hover:bg-gray-200/70 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-gray-100' => ! $isActive,
                        ])
                    >
                        <x-dynamic-component
                            :component="$item['icon']"
                            wire:loading.remove
                            wire:target="setFolder('{{ $item['folder'] }}')"
                            @class([
                                'h-5 w-5 shrink-0',
                                'text-primary-600 dark:text-primary-400' => $isActive,
                                'text-gray-400 dark:text-gray-500' => ! $isActive,
                            ])
                        />
                        <svg wire:loading wire:target="setFolder('{{ $item['folder'] }}')" class="h-5 w-5 shrink-0 animate-spin text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        <span class="flex-1 text-left">{{ $item['label'] }}</span>
                        @if ($item['badge'] !== null && $item['badge'] > 0)
                            <span class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-primary-600 px-1.5 py-0.5 text-[10px] font-bold leading-none text-white">
                                {{ $item['badge'] > 99 ? '99+' : $item['badge'] }}
                            </span>
                        @endif
                    </button>
                @endforeach

            </nav>

        </div>

        {{-- ── Main area: email list OR email detail ───────────────────────── --}}
        <div class="relative flex flex-1 flex-col min-w-0 overflow-hidden">

            @if ($selectedEmailId === null)

                {{-- ── Email list view ──────────────────────────────────────── --}}
                <x-emails.search-bar :search="$search" />

                <div class="flex-1 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($this->emails as $email)
                        <x-emails.list-row :email="$email" :selected-email-id="$selectedEmailId" :folder="$folder" />
                    @empty
                        <x-emails.list-empty :search="$search" :folder="$folder" />
                    @endforelse
                </div>

                <div class="shrink-0 border-t border-gray-200 dark:border-gray-700 px-3 py-2 flex items-center justify-between">
                    <button
                        wire:click="previousPage"
                        wire:loading.attr="disabled"
                        @disabled($this->emails->onFirstPage())
                        class="flex items-center gap-1 rounded px-2 py-1 text-xs text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:pointer-events-none disabled:opacity-40"
                    >
                        <x-heroicon-o-chevron-left class="h-3.5 w-3.5" />
                        Prev
                    </button>
                    <span class="text-xs text-gray-400 dark:text-gray-500">
                        {{ $this->emails->firstItem() ?? 0 }}–{{ $this->emails->lastItem() ?? 0 }} of {{ $this->emails->total() }}
                    </span>
                    <button
                        wire:click="nextPage"
                        wire:loading.attr="disabled"
                        @disabled($this->emails->onLastPage())
                        class="flex items-center gap-1 rounded px-2 py-1 text-xs text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:pointer-events-none disabled:opacity-40"
                    >
                        Next
                        <x-heroicon-o-chevron-right class="h-3.5 w-3.5" />
                    </button>
                </div>

            @else

                {{-- ── Email detail view ────────────────────────────────────── --}}

                {{-- Top bar: Back + folder crumb (left) · email actions (right) --}}
                <div class="shrink-0 flex items-center gap-2 border-b border-gray-200 dark:border-gray-700 px-4 py-2">

                    {{-- Left: back + breadcrumb --}}
                    <button
                        wire:click="deselectEmail"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-gray-100 transition-colors"
                    >
                        <x-heroicon-o-arrow-left class="h-4 w-4" />
                        Back
                    </button>
                    <span class="text-xs text-gray-400 dark:text-gray-500">{{ $folder->getLabel() }}</span>

                    <div class="flex-1"></div>

                    {{-- Right: contextual actions for the selected email --}}
                    @if ($this->selectedEmail !== null)
                        @php
                            $authUser         = auth()->user();
                            $isOwner          = $this->selectedEmail->user_id === $authUser->getKey();
                            $canSummarize     = filled($this->selectedEmail->thread_id) && $authUser->can('viewSubject', $this->selectedEmail);
                            $canRequestAccess = $authUser->cannot('viewBody', $this->selectedEmail) && $authUser->can('requestAccess', $this->selectedEmail);
                        @endphp

                        @if ($isOwner)
                            <button
                                x-on:click="$wire.mountAction('manageSharing', { emailId: '{{ $this->selectedEmail->id }}' })"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-gray-200 transition-colors"
                            >
                                <x-heroicon-o-lock-open class="h-3.5 w-3.5" />
                                Sharing
                            </button>
                        @endif

                        @if ($canSummarize)
                            <button
                                x-on:click="$wire.mountAction('summarizeThread', { emailId: '{{ $this->selectedEmail->id }}' })"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-gray-200 transition-colors"
                            >
                                <x-heroicon-o-sparkles class="h-3.5 w-3.5" />
                                Summarize Thread
                            </button>
                        @endif

                        @if ($canRequestAccess)
                            <button
                                x-on:click="$wire.mountAction('requestAccess', { emailId: '{{ $this->selectedEmail->id }}' })"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-gray-200 transition-colors"
                            >
                                <x-heroicon-o-key class="h-3.5 w-3.5" />
                                Request Access
                            </button>
                        @endif
                    @endif

                </div>

                {{-- Loading overlay --}}
                <div wire:loading wire:target="selectEmail" class="absolute inset-0 z-10 flex items-center justify-center bg-white/60 dark:bg-gray-900/60">
                    <svg class="h-8 w-8 animate-spin text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                </div>

                <div wire:loading.class="opacity-0" wire:target="selectEmail" class="flex flex-1 flex-col overflow-y-auto">
                    @if ($this->selectedEmail !== null)
                        <x-emails.email-view :record="$this->selectedEmail" />
                    @endif
                </div>

            @endif

        </div>

    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
