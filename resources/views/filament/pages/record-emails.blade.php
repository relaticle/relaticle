<x-filament-panels::page class="!pb-0">
    <div class="flex overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm h-[80vh]">

        {{-- ── Left panel: folder tabs + search + email list ─────────── --}}
        <div class="flex w-80 shrink-0 flex-col border-r border-gray-200 dark:border-gray-700">

            <div class="flex shrink-0 border-b border-gray-200 dark:border-gray-700">
                <x-emails.folder-tab folder="all"   :active="$folder->value === 'all'"   icon="heroicon-o-squares-2x2"   label="All" />
                <x-emails.folder-tab folder="inbox" :active="$folder->value === 'inbox'" icon="heroicon-o-inbox"          label="Inbox" />
                <x-emails.folder-tab folder="sent"  :active="$folder->value === 'sent'"  icon="heroicon-o-paper-airplane" label="Sent" />
            </div>

            <x-emails.search-bar :search="$search" />

            <div class="flex-1 overflow-y-scroll divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($this->emails as $email)
                    <x-emails.list-row :email="$email" :selected-email-id="$selectedEmailId" :folder="$folder" />
                @empty
                    <x-emails.list-empty :search="$search" :folder="$folder" />
                @endforelse
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
