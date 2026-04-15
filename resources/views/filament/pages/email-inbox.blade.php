<x-filament-panels::page class="!pb-0">
    <div class="flex overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm h-[80vh]">

        {{-- ── Left panel: folder tabs + search + email list ─────────── --}}
        <div class="flex w-80 shrink-0 flex-col border-r border-gray-200 dark:border-gray-700">

            <div class="flex shrink-0 border-b border-gray-200 dark:border-gray-700">
                <x-emails.folder-tab folder="all"   :active="$folder->value === 'all'"   icon="heroicon-o-squares-2x2"   label="All" />
                <x-emails.folder-tab folder="inbox" :active="$folder->value === 'inbox'" icon="heroicon-o-inbox"          label="Inbox" :badge="$this->inboxUnreadCount" />
                <x-emails.folder-tab folder="sent"  :active="$folder->value === 'sent'"  icon="heroicon-o-paper-airplane" label="Sent" />
            </div>

            <x-emails.search-bar :search="$search" />

            <div class="flex-1 overflow-y-scroll divide-y divide-gray-100 dark:divide-gray-800">
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

        </div>

        {{-- ── Right panel: email detail ───────────────────────────────── --}}
        <div class="relative flex flex-1 flex-col overflow-y-auto">

            {{-- Loading overlay while switching emails --}}
            <div wire:loading wire:target="selectEmail" class="absolute inset-0 z-10 flex items-center justify-center bg-white/60 dark:bg-gray-900/60">
                <svg class="h-8 w-8 animate-spin text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
            </div>

            <div wire:loading.class="opacity-0" wire:target="selectEmail">
                @if ($this->selectedEmail !== null)
                    <x-emails.detail-action-bar :email="$this->selectedEmail" />

                    @php
                        $authUser = auth()->user();
                        $isEmailOwner = $this->selectedEmail->user_id === $authUser->getKey();
                        $pendingRequests = $isEmailOwner
                            ? \Relaticle\EmailIntegration\Models\EmailAccessRequest::query()
                                ->with('requester')
                                ->where('email_id', $this->selectedEmail->id)
                                ->where('status', \Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus::PENDING)
                                ->get()
                            : collect();
                    @endphp

                    @if ($pendingRequests->isNotEmpty())
                        <div class="shrink-0 border-b border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 px-4 py-3">
                            <div class="flex items-center gap-2 mb-2">
                                <x-heroicon-o-key class="h-4 w-4 text-amber-600 dark:text-amber-400 shrink-0" />
                                <span class="text-xs font-semibold text-amber-800 dark:text-amber-300">
                                    {{ $pendingRequests->count() === 1 ? '1 pending access request' : $pendingRequests->count().' pending access requests' }}
                                </span>
                            </div>
                            <div class="space-y-1.5">
                                @foreach ($pendingRequests as $accessRequest)
                                    <div class="flex items-center justify-between gap-3 rounded-lg bg-white dark:bg-gray-900 border border-amber-200 dark:border-amber-800 px-3 py-2">
                                        <div class="flex items-center gap-2 min-w-0">
                                            <span class="text-xs font-medium text-gray-900 dark:text-gray-100 truncate">
                                                {{ $accessRequest->requester?->name ?? 'Unknown user' }}
                                            </span>
                                            <span class="shrink-0 inline-flex items-center rounded-full bg-amber-100 dark:bg-amber-900/50 px-2 py-0.5 text-[10px] font-medium text-amber-700 dark:text-amber-300">
                                                {{ \Relaticle\EmailIntegration\Enums\EmailPrivacyTier::from($accessRequest->tier_requested)->getLabel() }}
                                            </span>
                                        </div>
                                        <div class="flex items-center gap-1.5 shrink-0">
                                            <button
                                                wire:click="mountAction('approveAccessRequest', { requestId: '{{ $accessRequest->id }}' })"
                                                type="button"
                                                class="inline-flex items-center gap-1 rounded-md bg-success-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-success-700 transition-colors"
                                            >
                                                <x-heroicon-o-check class="h-3 w-3" />
                                                Approve
                                            </button>
                                            <button
                                                wire:click="mountAction('denyAccessRequest', { requestId: '{{ $accessRequest->id }}' })"
                                                type="button"
                                                class="inline-flex items-center gap-1 rounded-md bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 px-2.5 py-1 text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                                            >
                                                <x-heroicon-o-x-mark class="h-3 w-3" />
                                                Deny
                                            </button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <x-emails.email-view :record="$this->selectedEmail" />
                @else
                    <div class="flex flex-col items-center justify-center gap-3 px-8 py-16 text-center h-full">
                        <div class="flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                            <x-heroicon-o-envelope-open class="h-8 w-8 text-gray-400 dark:text-gray-500" />
                        </div>
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Select an email to read</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500">Choose a message from the list on the left</p>
                    </div>
                @endif
            </div>

        </div>

    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
