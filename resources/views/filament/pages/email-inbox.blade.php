<x-filament-panels::page class="[&_.fi-page-header-main-ctn]:!pb-0">
    @if ($this->showConnectPrompt)
        <div class="flex items-center justify-center overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm h-[80vh]">
            <x-emails.not-connected
                :heading="__('filament/pages/email-accounts.not_connected.inbox.heading')"
                :description="__('filament/pages/email-accounts.not_connected.inbox.description')"
            />
        </div>
    @else
    {{-- ── Page tabs: the mail reader plus its sibling lists ────────── --}}
    <div class="flex items-center gap-1 border-b border-gray-200 pb-2 dark:border-gray-700">
        @foreach (\Relaticle\EmailIntegration\Enums\EmailPageTab::cases() as $pageTab)
            <x-emails.page-tab
                :tab="$pageTab"
                :active="$tab === $pageTab"
                :badge="$this->tabCounts[$pageTab->value] ?? null"
            />
        @endforeach
    </div>

    @if ($tab !== \Relaticle\EmailIntegration\Enums\EmailPageTab::EMAILS)
        {{-- No wrapper: the Filament table renders its own card. --}}
        @livewire($tab->livewireComponent(), key($tab->value.'-table'))
    @else
    {{-- ── Email list: full width, one line per email ────────────────────
         The card owns the rest of the viewport so the rows scroll inside it and the
         pagination bar stays pinned to the bottom of the screen. --}}
    <div class="flex h-[calc(100dvh-17rem)] min-h-[24rem] flex-col overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm">

        <div class="flex shrink-0 flex-wrap items-center gap-x-4 gap-y-2 border-b border-gray-200 dark:border-gray-700 px-4 py-2 sm:px-6">
            <div class="flex shrink-0 items-center">
                <x-emails.folder-tab :grow="false" folder="all"   :active="$folder->value === 'all'"   icon="heroicon-o-squares-2x2"   :label="__('filament/pages/email-inbox.folders.all')" />
                <x-emails.folder-tab :grow="false" folder="inbox" :active="$folder->value === 'inbox'" icon="heroicon-o-inbox"          :label="__('filament/pages/email-inbox.folders.inbox')" :badge="$this->inboxUnreadCount" />
                <x-emails.folder-tab :grow="false" folder="sent"  :active="$folder->value === 'sent'"  icon="heroicon-o-paper-airplane" :label="__('filament/pages/email-inbox.folders.sent')" />
            </div>

            <div class="min-w-[12rem] flex-1">
                <x-emails.search-bar :search="$search" />
            </div>

            @if ($this->showAccountSwitcher)
                <select
                    wire:model.live="accountId"
                    aria-label="{{ __('filament/pages/email-inbox.account_filter.label') }}"
                    class="w-44 shrink-0 truncate cursor-pointer rounded-lg border-gray-200 dark:border-gray-700 bg-transparent py-1.5 text-sm text-gray-700 dark:text-gray-200 focus:border-primary-500 focus:ring-0"
                >
                    @foreach ($this->accountFilterOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            @endif

            @if ($this->inboxUnreadCount > 0)
                <div class="flex shrink-0 items-center gap-3">
                    <span class="text-xs text-gray-400 dark:text-gray-500">
                        {{ __('filament/pages/email-inbox.unread_label', ['count' => $this->inboxUnreadCount]) }}
                    </span>
                    <button
                        wire:click="markAllAsRead"
                        wire:loading.attr="disabled"
                        wire:target="markAllAsRead"
                        type="button"
                        class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-primary-600 dark:text-primary-400 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:pointer-events-none disabled:opacity-50"
                    >
                        <x-ri-check-double-line class="h-3.5 w-3.5" />
                        {{ __('filament/pages/email-inbox.mark_all_read.label') }}
                    </button>
                </div>
            @endif

            {{-- Compose belongs to the board, not the page: it acts on mail, so it
                 lives with the folders and search and appears only here — the Drafts,
                 Outbox and Templates tabs render their own tables instead of this. --}}
            <div class="shrink-0">
                {{ ($this->composeEmailAction)([]) }}
            </div>
        </div>

        <div class="flex-1 divide-y divide-gray-100 dark:divide-gray-800 overflow-y-auto">
            @forelse ($this->emails as $email)
                <x-emails.list-row-wide :email="$email" :folder="$folder" />
            @empty
                <x-emails.list-empty :search="$search" :folder="$folder" />
            @endforelse
        </div>

        <div class="flex shrink-0 items-center justify-between border-t border-gray-200 dark:border-gray-700 px-4 py-2 sm:px-6">
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
    </div>

    {{-- ── Reader ──────────────────────────────────────────────────────────
         A hand-rolled overlay rather than <x-filament::modal>. That component owns
         its open state in Alpine and sets window visibility from its own $nextTick,
         which races a Livewire response that renders and opens it in one go — it
         ends up `isOpen` with the window still display:none. Driving the state here
         also keeps the chrome slim: Filament only renders its close button inside a
         heading block, and that block is far heavier than a mail reader wants.

         Closing always routes through close(), which plays the leave transition and
         then deselects; deselecting dismisses the reply dock, and the dock saves
         whatever was typed as a draft. --}}
    @if ($this->selectedEmail !== null)
        {{-- Animated in CSS rather than with x-show. Alpine visibility state does not
             survive Livewire morphing this element — the state flips to shown while a
             stale inline `display: none` stays behind, leaving an invisible overlay.
             A mount animation has no state to fall out of sync. --}}
        <div
            x-data="{
                /**
                 * Save any reply in progress before the reader goes away. Closing
                 * removes the docked composer along with the reader, so an event asking
                 * it to save its draft would arrive after it no longer exists — the
                 * draft has to be persisted first, and awaited.
                 */
                async closeReader() {
                    const dock = document.querySelector('[data-inline-composer][wire\\:id]')

                    if (dock) {
                        await window.Livewire.find(dock.getAttribute('wire:id')).call('close')
                    }

                    $wire.deselectEmail()
                },
            }"
            wire:key="email-reader"
            x-on:keydown.escape.window="closeReader()"
            class="fixed inset-0 z-30 flex items-center justify-center p-4 sm:p-6"
        >
            <div
                x-on:click="closeReader()"
                class="fi-email-reader-backdrop absolute inset-0 bg-gray-950/50"
            ></div>

            <div class="fi-email-reader-panel relative flex h-[85vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10">
                {{-- Slim title bar --}}
                <div class="flex h-11 shrink-0 items-center justify-between gap-2 border-b border-gray-200 px-4 dark:border-gray-700">
                    <span class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                        <x-heroicon-m-envelope class="h-4 w-4 text-gray-400" />
                        {{ __('filament/pages/email-inbox.reader.heading') }}
                    </span>
                    <button
                        x-on:click="closeReader()"
                        type="button"
                        aria-label="{{ __('filament/emails/composer.actions.close') }}"
                        class="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                    >
                        <x-heroicon-m-x-mark class="h-4 w-4" />
                    </button>
                </div>

                <div class="flex min-h-0 flex-1 flex-col">
                    @if ($this->pendingAccessRequests->isNotEmpty())
                        <div class="shrink-0 border-b border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 px-4 py-3">
                            <div class="flex items-center gap-2 mb-2">
                                <x-heroicon-o-key class="h-4 w-4 text-amber-600 dark:text-amber-400 shrink-0" />
                                <span class="text-xs font-semibold text-amber-800 dark:text-amber-300">
                                    {{ trans_choice('filament/pages/email-inbox.pending_access.heading', $this->pendingAccessRequests->count(), ['count' => $this->pendingAccessRequests->count()]) }}
                                </span>
                            </div>
                            <div class="space-y-1.5">
                                @foreach ($this->pendingAccessRequests as $accessRequest)
                                    <div class="flex items-center justify-between gap-3 rounded-lg bg-white dark:bg-gray-900 border border-amber-200 dark:border-amber-800 px-3 py-2">
                                        <div class="flex items-center gap-2 min-w-0">
                                            <span class="text-xs font-medium text-gray-900 dark:text-gray-100 truncate">
                                                {{ $accessRequest->requester?->name ?? __('filament/pages/email-inbox.pending_access.unknown_user') }}
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
                                                {{ __('filament/pages/email-inbox.pending_access.approve') }}
                                            </button>
                                            <button
                                                wire:click="mountAction('denyAccessRequest', { requestId: '{{ $accessRequest->id }}' })"
                                                type="button"
                                                class="inline-flex items-center gap-1 rounded-md bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 px-2.5 py-1 text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                                            >
                                                <x-heroicon-o-x-mark class="h-3 w-3" />
                                                {{ __('filament/pages/email-inbox.pending_access.deny') }}
                                            </button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <x-emails.email-view :record="$this->selectedEmail" />
                </div>
            </div>
        </div>
    @endif

    @endif
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
