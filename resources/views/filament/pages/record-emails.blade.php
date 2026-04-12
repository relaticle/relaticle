<x-filament-panels::page class="!pb-0">
    <div
        class="flex overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm h-[80vh]">

        {{-- ── Left panel: folder tabs + search + email list ─────────── --}}
        <div class="flex w-80 shrink-0 flex-col border-r border-gray-200 dark:border-gray-700">

            {{-- Folder tabs --}}
            <div class="flex shrink-0 border-b border-gray-200 dark:border-gray-700">
                <button wire:click="setFolder('all')" wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-not-allowed" @class([
                        'flex flex-1 items-center justify-center gap-1.5 py-3 text-sm font-medium transition-colors focus:outline-none',
                        'border-b-2 border-primary-500 text-primary-600 dark:text-primary-400' =>
                            $folder->value === 'all',
                        'border-b-2 border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' =>
                            $folder->value !== 'all',
                    ])>
                    <x-heroicon-o-squares-2x2 class="h-4 w-4" wire:loading.remove wire:target="setFolder('all')" />
                    <svg wire:loading wire:target="setFolder('all')" class="h-4 w-4 animate-spin"
                        xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                            stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z">
                        </path>
                    </svg>
                    All
                </button>
                <button wire:click="setFolder('inbox')" wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-not-allowed" @class([
                        'flex flex-1 items-center justify-center gap-1.5 py-3 text-sm font-medium transition-colors focus:outline-none',
                        'border-b-2 border-primary-500 text-primary-600 dark:text-primary-400' =>
                            $folder->value === 'inbox',
                        'border-b-2 border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' =>
                            $folder->value !== 'inbox',
                    ])>
                    <x-heroicon-o-inbox class="h-4 w-4" wire:loading.remove wire:target="setFolder('inbox')" />
                    <svg wire:loading wire:target="setFolder('inbox')" class="h-4 w-4 animate-spin"
                        xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                            stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z">
                        </path>
                    </svg>
                    Inbox
                </button>
                <button wire:click="setFolder('sent')" wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-not-allowed" @class([
                        'flex flex-1 items-center justify-center gap-1.5 py-3 text-sm font-medium transition-colors focus:outline-none',
                        'border-b-2 border-primary-500 text-primary-600 dark:text-primary-400' =>
                            $folder->value === 'sent',
                        'border-b-2 border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' =>
                            $folder->value !== 'sent',
                    ])>
                    <x-heroicon-o-paper-airplane class="h-4 w-4" wire:loading.remove wire:target="setFolder('sent')" />
                    <svg wire:loading wire:target="setFolder('sent')" class="h-4 w-4 animate-spin"
                        xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                            stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z">
                        </path>
                    </svg>
                    Sent
                </button>
            </div>

            {{-- Search --}}
            <div class="shrink-0 border-b border-gray-200 dark:border-gray-700 p-3">
                <div class="relative">
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                        <x-heroicon-o-magnifying-glass class="h-4 w-4 text-gray-400 dark:text-gray-500"
                            wire:loading.remove wire:target="search" />
                        <svg wire:loading wire:target="search" class="h-4 w-4 animate-spin text-primary-500"
                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                    </div>
                    <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search emails…"
                        class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 py-2 pl-9 pr-3 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:border-primary-500 dark:focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500" />
                    @if (filled($search))
                        <button wire:click="$set('search', '')"
                            class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                            <x-heroicon-o-x-mark class="h-4 w-4" />
                        </button>
                    @endif
                </div>
            </div>

            {{-- Email list --}}
            <div class="flex-1 overflow-y-scroll divide-y divide-gray-100 dark:divide-gray-800">

                @forelse($this->emails as $email)
                    @php
                        $isSelected = $selectedEmailId === $email->id;
                        $from = $email->from->first();
                        $senderName = $from?->name ?: $from?->email_address ?: '?';
                        $authUser = auth()->user();
                        $canViewSubject = $authUser->can('viewSubject', $email);
                        $isOwner = $email->user_id === $authUser->getKey();
                        $canSummarize = filled($email->thread_id) && $canViewSubject;
                        $canRequestAccess = $authUser->cannot('viewBody', $email) && $authUser->can('requestAccess', $email);
                        $hasActions = $isOwner || $canSummarize || $canRequestAccess;
                    @endphp

                    <div x-data="{ actionsOpen: false }" class="relative group">
                        <button wire:click="selectEmail('{{ $email->id }}')" @class([
                            'w-full text-left px-4 py-3.5 pr-10 transition-colors focus:outline-none',
                            'bg-primary-50 dark:bg-primary-900/20 border-l-2 border-primary-500' => $isSelected,
                            'hover:bg-gray-50 dark:hover:bg-gray-800/50 border-l-2 border-transparent' => !$isSelected,
                        ])>
                            <div class="flex items-baseline justify-between gap-2 mb-0.5">
                                <span @class([
                                    'truncate text-sm',
                                    'font-semibold text-gray-900 dark:text-white' => $isSelected,
                                    'font-normal text-gray-700 dark:text-gray-300' => !$isSelected,
                                ])>
                                    {{ $senderName }}
                                </span>
                                <div class="flex shrink-0 items-center gap-1.5">
                                    @if ($folder->value === 'all')
                                        @if ($email->direction === \Relaticle\EmailIntegration\Enums\EmailDirection::OUTBOUND)
                                            <span class="inline-flex items-center rounded-full bg-blue-50 dark:bg-blue-900/20 px-1.5 py-0.5 text-[10px] font-medium text-blue-600 dark:text-blue-400">
                                                Sent
                                            </span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-green-50 dark:bg-green-900/20 px-1.5 py-0.5 text-[10px] font-medium text-green-600 dark:text-green-400">
                                                Inbox
                                            </span>
                                        @endif
                                    @endif
                                    <span class="text-xs text-gray-400 dark:text-gray-500">
                                        {{ $email->sent_at?->diffForHumans() }}
                                    </span>
                                </div>
                            </div>
                            <p @class([
                                'truncate text-xs',
                                'font-medium text-gray-800 dark:text-gray-100' => $isSelected,
                                'font-normal text-gray-600 dark:text-gray-400' => !$isSelected,
                            ])>
                                {{ $canViewSubject ? ($email->subject ?: '(no subject)') : '(subject hidden)' }}
                            </p>
                            @if ($email->snippet)
                                <p class="mt-0.5 truncate text-xs text-gray-400 dark:text-gray-500">
                                    {{ $email->snippet }}
                                </p>
                            @endif
                            @if ($email->labels->isNotEmpty())
                                <div class="mt-1.5 flex gap-1">
                                    @foreach ($email->labels->take(2) as $label)
                                        <span
                                            class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400">
                                            {{ $label->label }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </button>

                        {{-- Per-email actions dropdown --}}
                        @if ($hasActions)
                            <div class="absolute right-2 top-3">
                                <button
                                    @click.stop="actionsOpen = !actionsOpen"
                                    type="button"
                                    class="flex h-6 w-6 items-center justify-center rounded-md text-gray-400 opacity-0 group-hover:opacity-100 transition-opacity hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700"
                                    :class="{ 'opacity-100 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300': actionsOpen }"
                                >
                                    <x-heroicon-o-ellipsis-horizontal class="h-4 w-4" />
                                </button>

                                <div
                                    x-show="actionsOpen"
                                    @click.outside="actionsOpen = false"
                                    x-cloak
                                    class="absolute right-0 top-7 z-50 min-w-[11rem] rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-lg py-1"
                                >
                                    @if ($isOwner)
                                        <button
                                            @click.stop="actionsOpen = false; $wire.mountAction('manageSharing', { emailId: '{{ $email->id }}' })"
                                            type="button"
                                            class="flex w-full items-center gap-2.5 px-3 py-1.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/50"
                                        >
                                            <x-heroicon-o-lock-open class="h-4 w-4 shrink-0 text-gray-400" />
                                            Sharing
                                        </button>
                                    @endif

                                    @if ($canSummarize)
                                        <button
                                            @click.stop="actionsOpen = false; $wire.mountAction('summarizeThread', { emailId: '{{ $email->id }}' })"
                                            type="button"
                                            class="flex w-full items-center gap-2.5 px-3 py-1.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/50"
                                        >
                                            <x-heroicon-o-sparkles class="h-4 w-4 shrink-0 text-gray-400" />
                                            Summarize Thread
                                        </button>
                                    @endif

                                    @if ($canRequestAccess)
                                        <button
                                            @click.stop="actionsOpen = false; $wire.mountAction('requestAccess', { emailId: '{{ $email->id }}' })"
                                            type="button"
                                            class="flex w-full items-center gap-2.5 px-3 py-1.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/50"
                                        >
                                            <x-heroicon-o-key class="h-4 w-4 shrink-0 text-gray-400" />
                                            Request Access
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="flex flex-col items-center justify-center gap-2 px-4 py-16 text-center">
                        @if (filled($search))
                            <x-heroicon-o-magnifying-glass class="h-8 w-8 text-gray-300 dark:text-gray-600" />
                            <p class="text-sm text-gray-400 dark:text-gray-500">No results for "{{ $search }}"</p>
                        @else
                            <x-heroicon-o-envelope class="h-8 w-8 text-gray-300 dark:text-gray-600" />
                            <p class="text-sm text-gray-400 dark:text-gray-500">
                                @if ($folder->value === 'all')
                                    No emails
                                @elseif ($folder->value === 'sent')
                                    No sent emails
                                @else
                                    No received emails
                                @endif
                            </p>
                        @endif
                    </div>
                @endforelse
            </div>
        </div>

        {{-- ── Right panel: email detail ───────────────────────────────── --}}
        <div class="relative flex flex-1 flex-col overflow-y-auto">

            {{-- Email loading indicator --}}
            <div wire:loading wire:target="selectEmail" class="absolute inset-0 z-10 flex items-center justify-center bg-white/60 dark:bg-gray-900/60">
                <svg class="h-8 w-8 animate-spin text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none"
                    viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                        stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
            </div>

            <div wire:loading.class="opacity-0" wire:target="selectEmail">
                @if ($this->selectedEmail !== null)
                    @php
                        $detailEmail   = $this->selectedEmail;
                        $detailUser    = auth()->user();
                        $detailIsOwner = $detailEmail->user_id === $detailUser->getKey();
                        $detailCanSummarize    = filled($detailEmail->thread_id) && $detailUser->can('viewSubject', $detailEmail);
                        $detailCanRequestAccess = $detailUser->cannot('viewBody', $detailEmail) && $detailUser->can('requestAccess', $detailEmail);
                    @endphp

                    {{-- ── Detail action bar ────────────────────────────────── --}}
                    @if ($detailIsOwner || $detailCanSummarize || $detailCanRequestAccess)
                        <div class="flex shrink-0 items-center justify-end gap-1 border-b border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-900 px-4 py-2">
                            @if ($detailIsOwner)
                                <button
                                    x-on:click="$wire.mountAction('manageSharing', { emailId: '{{ $detailEmail->id }}' })"
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-gray-200 transition-colors"
                                >
                                    <x-heroicon-o-lock-open class="h-3.5 w-3.5" />
                                    Sharing
                                </button>
                            @endif

                            @if ($detailCanSummarize)
                                <button
                                    x-on:click="$wire.mountAction('summarizeThread', { emailId: '{{ $detailEmail->id }}' })"
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-gray-200 transition-colors"
                                >
                                    <x-heroicon-o-sparkles class="h-3.5 w-3.5" />
                                    Summarize Thread
                                </button>
                            @endif

                            @if ($detailCanRequestAccess)
                                <button
                                    x-on:click="$wire.mountAction('requestAccess', { emailId: '{{ $detailEmail->id }}' })"
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-gray-200 transition-colors"
                                >
                                    <x-heroicon-o-key class="h-3.5 w-3.5" />
                                    Request Access
                                </button>
                            @endif
                        </div>
                    @endif

                    @include('filament.emails.email-view', [
                        'record' => $detailEmail,
                    ])
                @else
                    <div class="flex flex-col items-center justify-center gap-3 px-8 py-16 text-center h-full">
                        <div
                            class="flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                            <x-heroicon-o-envelope-open class="h-8 w-8 text-gray-400 dark:text-gray-500" />
                        </div>
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Select an email to read</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500">Choose a message from the list on the left
                        </p>
                    </div>
                @endif
            </div>

        </div>

    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
