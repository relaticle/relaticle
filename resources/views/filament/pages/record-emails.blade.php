<x-filament-panels::page class="!pb-0">
    {{-- Rendered here rather than left to the page header: the app panel turns
         breadcrumbs off globally (AppPanelProvider::breadcrumbs(false)), so the stock
         header would drop them. The crumbs themselves are Filament's: resource index,
         the record, then this page. --}}
    <x-filament::breadcrumbs :breadcrumbs="$this->getBreadcrumbs()" class="-mb-2" />

    @if ($this->hidesRecordMailbox)
        <div class="flex h-[80vh] items-center justify-center overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <x-emails.protected-mailbox
                :heading="$this->recordMailboxHiddenCopy['heading']"
                :description="$this->recordMailboxHiddenCopy['description']"
            />
        </div>
    @elseif ($this->showConnectPrompt)
        <div class="flex h-[80vh] items-center justify-center overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <x-emails.not-connected
                :heading="__('filament/pages/email-accounts.not_connected.record.heading')"
                :description="__('filament/pages/email-accounts.not_connected.record.description')"
            />
        </div>
    @else
    {{-- ── Email list: full width, one line per email ────────────────────
         Sized in CSS, not JS: an inline height set by Alpine is wiped by every
         Livewire re-render (x-init does not re-run after a morph), which drops the
         pane back to its min-height mid-interaction. The subtraction is the chrome
         above it: topbar, record header and the resource tabs. --}}
    <div @class([
        'flex h-[calc(100dvh-15rem)] min-h-[30rem] flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm ring-1 ring-gray-950/5 dark:border-gray-800 dark:bg-gray-950 dark:ring-white/10',
        'invisible' => $this->selectedEmail !== null,
    ])>

        {{-- Toolbar: what you are looking at on the left, what you can do with it on
             the right, one control height throughout. Compose is the header action,
             which opens the global floating composer. --}}
        <div class="flex shrink-0 flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-gray-200 bg-gray-50/80 px-4 py-3 dark:border-gray-800 dark:bg-gray-900 sm:px-6">

            <div class="flex min-w-0 flex-1 items-center gap-3">
                <div class="flex shrink-0 items-center gap-1 rounded-lg bg-gray-100 p-1 ring-1 ring-gray-950/5 dark:bg-gray-950 dark:ring-white/10">
                    <x-emails.folder-tab :grow="false" folder="all"   :active="$folder->value === 'all'"   icon="heroicon-o-squares-2x2"   :label="__('filament/pages/email-inbox.folders.all')" />
                    <x-emails.folder-tab :grow="false" folder="inbox" :active="$folder->value === 'inbox'" icon="heroicon-o-inbox"          :label="__('filament/pages/email-inbox.folders.inbox')" />
                    <x-emails.folder-tab :grow="false" folder="sent"  :active="$folder->value === 'sent'"  icon="heroicon-o-paper-airplane" :label="__('filament/pages/email-inbox.folders.sent')" />
                </div>

                <div class="min-w-[10rem] max-w-sm flex-1">
                    <x-emails.search-bar :search="$search" :framed="false" />
                </div>
            </div>
        </div>

        <div class="flex flex-1 flex-col divide-y divide-gray-100 overflow-y-auto bg-white dark:divide-gray-800 dark:bg-gray-950">
            @forelse ($this->emails as $email)
                <x-emails.list-row-wide :email="$email" wire:key="email-list-row-{{ $email->id }}" />
            @empty
                <x-emails.list-empty
                    class="flex-1"
                    :search="$search"
                    :folder="$folder"
                    :can-compose="$this->hasActiveConnectedAccount"
                />
            @endforelse
        </div>

        @if ($this->emails->isNotEmpty())
        <div class="flex shrink-0 items-center justify-between border-t border-gray-200 bg-gray-50/80 px-4 py-2 dark:border-gray-800 dark:bg-gray-900 sm:px-6">
            <button
                wire:click="previousPage"
                wire:loading.attr="disabled"
                @disabled($this->emails->onFirstPage())
                class="flex items-center gap-1 rounded px-2 py-1 text-xs text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:pointer-events-none disabled:opacity-40"
            >
                <x-heroicon-o-chevron-left class="h-3.5 w-3.5" />
                {{ __('filament/pages/email-inbox.pagination.previous') }}
            </button>
            <span class="text-xs text-gray-400 dark:text-gray-500">
                {{ __('filament/pages/email-inbox.pagination.range', [
                    'first' => $this->emails->firstItem() ?? 0,
                    'last' => $this->emails->lastItem() ?? 0,
                    'total' => $this->emails->total(),
                ]) }}
            </span>
            <button
                wire:click="nextPage"
                wire:loading.attr="disabled"
                @disabled($this->emails->onLastPage())
                class="flex items-center gap-1 rounded px-2 py-1 text-xs text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:pointer-events-none disabled:opacity-40"
            >
                {{ __('filament/pages/email-inbox.pagination.next') }}
                <x-heroicon-o-chevron-right class="h-3.5 w-3.5" />
            </button>
        </div>
        @endif
    </div>

    {{-- ── Reader ──────────────────────────────────────────────────────────
         A hand-rolled overlay rather than <x-filament::modal>. That component owns
         its open state in Alpine and sets window visibility from its own $nextTick,
         which races a Livewire response that renders and opens it in one go, so it
         ends up `isOpen` with the window still display:none. Driving the state here
         also keeps the chrome slim: Filament only renders its close button inside a
         heading block, and that block is far heavier than a mail reader wants.

         Closing always routes through closeReader(), which persists any docked
         reply draft and then deselects. The same overlay is mounted from access
         request notifications. --}}
    <x-emails.reader-overlay
        :email="$this->selectedEmail"
        :pending-access-requests="$this->pendingAccessRequests"
    />
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
