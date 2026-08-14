<x-filament-panels::page class="[&_.fi-page-header-main-ctn]:!pb-0">
    @if ($this->showConnectPrompt)
        <div class="flex items-center justify-center overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm h-[80vh]">
            <x-emails.not-connected
                :heading="__('filament/pages/email-accounts.not_connected.inbox.heading')"
                :description="__('filament/pages/email-accounts.not_connected.inbox.description')"
            />
        </div>
    @else
    {{-- ── Page tabs: the mail reader plus its sibling lists. ───────────── --}}
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
        <div class="flex h-[calc(100dvh-11rem)] min-h-[30rem] flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm ring-1 ring-gray-950/5 dark:border-gray-800 dark:bg-gray-950 dark:ring-white/10">
            <div class="flex shrink-0 flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-gray-200 bg-gray-50/80 px-4 py-3 dark:border-gray-800 dark:bg-gray-900 sm:px-6">
                <div class="flex min-w-0 flex-1 items-center gap-3">
                    @if ($this->showAccountSwitcher)
                        <label class="flex h-9 min-w-48 shrink-0 items-center gap-2 rounded-lg bg-white px-3 ring-1 ring-gray-950/10 dark:bg-gray-950 dark:ring-white/10">
                            <x-ri-mail-line class="h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500" />
                            <span class="sr-only">{{ __('filament/pages/email-inbox.account_filter.label') }}</span>
                            <select
                                wire:model.live="accountId"
                                aria-label="{{ __('filament/pages/email-inbox.account_filter.label') }}"
                                class="w-full min-w-0 cursor-pointer truncate border-0 bg-transparent py-0 pl-0 pr-7 text-sm font-medium text-gray-700 focus:ring-0 dark:text-gray-200"
                            >
                                @foreach ($this->accountFilterOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif

                    <div class="flex shrink-0 items-center gap-1 rounded-lg bg-gray-100 p-1 ring-1 ring-gray-950/5 dark:bg-gray-950 dark:ring-white/10">
                        <x-emails.folder-tab :grow="false" folder="all"   :active="$folder->value === 'all'"   icon="heroicon-o-squares-2x2"   :label="__('filament/pages/email-inbox.folders.all')" />
                        <x-emails.folder-tab :grow="false" folder="inbox" :active="$folder->value === 'inbox'" icon="heroicon-o-inbox"          :label="__('filament/pages/email-inbox.folders.inbox')" :badge="$this->inboxUnreadCount" />
                        <x-emails.folder-tab :grow="false" folder="sent"  :active="$folder->value === 'sent'"  icon="heroicon-o-paper-airplane" :label="__('filament/pages/email-inbox.folders.sent')" />
                    </div>

                    <div class="min-w-[10rem] max-w-sm flex-1">
                        <x-emails.search-bar :search="$search" :framed="false" />
                    </div>
                </div>

                @if ($this->inboxUnreadCount > 0)
                    <button
                        wire:click="markAllAsRead"
                        wire:loading.attr="disabled"
                        wire:target="markAllAsRead"
                        type="button"
                        class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg px-2.5 text-sm font-medium text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 disabled:pointer-events-none disabled:opacity-50 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                    >
                        <x-ri-check-double-line class="h-4 w-4" />
                        <span class="hidden lg:inline">{{ __('filament/pages/email-inbox.mark_all_read.label') }}</span>
                    </button>
                @endif
            </div>

            <div class="flex-1 divide-y divide-gray-100 overflow-y-auto bg-white dark:divide-gray-800 dark:bg-gray-950">
                @forelse ($this->emails as $email)
                    <x-emails.list-row-wide :email="$email" :folder="$folder" :own-addresses="$this->ownEmailAddresses" />
                @empty
                    <x-emails.list-empty :search="$search" :folder="$folder" />
                @endforelse
            </div>

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
        </div>

        @if ($this->selectedEmail !== null)
            <div
                x-data="{
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
                            <div class="shrink-0 border-b border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-800 dark:bg-amber-900/20">
                                <div class="mb-2 flex items-center gap-2">
                                    <x-heroicon-o-key class="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                                    <span class="text-xs font-semibold text-amber-800 dark:text-amber-300">
                                        {{ trans_choice('filament/pages/email-inbox.pending_access.heading', $this->pendingAccessRequests->count(), ['count' => $this->pendingAccessRequests->count()]) }}
                                    </span>
                                </div>
                                <div class="space-y-1.5">
                                    @foreach ($this->pendingAccessRequests as $accessRequest)
                                        <div class="flex items-center justify-between gap-3 rounded-lg border border-amber-200 bg-white px-3 py-2 dark:border-amber-800 dark:bg-gray-900">
                                            <div class="flex min-w-0 items-center gap-2">
                                                <span class="truncate text-xs font-medium text-gray-900 dark:text-gray-100">
                                                    {{ $accessRequest->requester?->name ?? __('filament/pages/email-inbox.pending_access.unknown_user') }}
                                                </span>
                                                <span class="inline-flex shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-900/50 dark:text-amber-300">
                                                    {{ \Relaticle\EmailIntegration\Enums\EmailPrivacyTier::from($accessRequest->tier_requested)->getLabel() }}
                                                </span>
                                            </div>
                                            <div class="flex shrink-0 items-center gap-1.5">
                                                <button
                                                    wire:click="mountAction('approveAccessRequest', { requestId: '{{ $accessRequest->id }}' })"
                                                    type="button"
                                                    class="inline-flex items-center gap-1 rounded-md bg-success-600 px-2.5 py-1 text-xs font-medium text-white transition-colors hover:bg-success-700"
                                                >
                                                    <x-heroicon-o-check class="h-3 w-3" />
                                                    {{ __('filament/pages/email-inbox.pending_access.approve') }}
                                                </button>
                                                <button
                                                    wire:click="mountAction('denyAccessRequest', { requestId: '{{ $accessRequest->id }}' })"
                                                    type="button"
                                                    class="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
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
