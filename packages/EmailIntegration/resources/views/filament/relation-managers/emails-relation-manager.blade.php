<div class="fi-resource-relation-manager ei-emails-relation-manager">
    @if ($this->hidesRecordMailbox)
        <div class="flex min-h-[30rem] items-center justify-center">
            <x-email-integration::protected-mailbox
                :heading="$this->recordMailboxHiddenCopy['heading']"
                :description="$this->recordMailboxHiddenCopy['description']"
            />
        </div>
    @elseif ($this->showConnectPrompt)
        <div class="flex min-h-[30rem] items-center justify-center">
            <x-email-integration::not-connected
                :heading="__('filament/pages/email-accounts.not_connected.record.heading')"
                :description="__('filament/pages/email-accounts.not_connected.record.description')"
                :action="$this->connectMailboxAction"
            />
        </div>
    @else
        <div @class([
            'flex flex-col',
            'invisible' => $this->selectedEmail !== null,
        ])>
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-4 sm:px-6">
                <div class="min-w-[10rem] max-w-sm flex-1">
                    <x-email-integration::search-bar :search="$search" :framed="false" />
                </div>

                {{ $this->composeEmailAction }}
            </div>

            <div class="flex min-h-[20rem] flex-col divide-y divide-gray-100 border-y border-gray-200 dark:divide-white/5 dark:border-white/10">
                @forelse ($this->emails as $email)
                    <x-email-integration::list-row-wide :email="$email" wire:key="email-list-row-{{ $email->id }}" />
                @empty
                    <x-email-integration::list-empty
                        class="flex-1"
                        :search="$search"
                        :can-compose="$this->hasActiveConnectedAccount"
                    />
                @endforelse
            </div>

            @if ($this->emails->hasPages())
                <div class="flex items-center justify-between px-4 py-3 sm:px-6">
                    <button
                        wire:click="previousPage(@js($this->getTablePaginationPageName()))"
                        wire:loading.attr="disabled"
                        @disabled($this->emails->onFirstPage())
                        class="flex items-center gap-1 rounded px-2 py-1 text-xs text-gray-500 hover:bg-gray-100 disabled:pointer-events-none disabled:opacity-40 dark:text-gray-400 dark:hover:bg-gray-800"
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
                        wire:click="nextPage(@js($this->getTablePaginationPageName()))"
                        wire:loading.attr="disabled"
                        @disabled($this->emails->onLastPage())
                        class="flex items-center gap-1 rounded px-2 py-1 text-xs text-gray-500 hover:bg-gray-100 disabled:pointer-events-none disabled:opacity-40 dark:text-gray-400 dark:hover:bg-gray-800"
                    >
                        {{ __('filament/pages/email-inbox.pagination.next') }}
                        <x-heroicon-o-chevron-right class="h-3.5 w-3.5" />
                    </button>
                </div>
            @endif
        </div>

        {{-- A hand-rolled overlay rather than <x-filament::modal>: that component owns its
             open state in Alpine and races a Livewire response that renders and opens it at once. --}}
        <x-email-integration::reader-overlay
            :email="$this->selectedEmail"
            :pending-access-requests="$this->pendingAccessRequests"
            layer="z-50"
        />
    @endif

    <x-filament-panels::unsaved-action-changes-alert />

    <x-filament-actions::modals />
</div>
