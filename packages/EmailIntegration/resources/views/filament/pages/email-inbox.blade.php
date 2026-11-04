<x-filament-panels::page class="fi-edge-to-edge-page">
    @unless ($this->hasConnectedMailbox)
        <x-email-integration::not-connected
            compact
            :heading="__('filament/pages/email-accounts.not_connected.inbox.heading')"
            :description="__('filament/pages/email-accounts.not_connected.inbox.description')"
            :action="$this->connectMailboxAction"
            :secondary-action="$this->connectAzureAction"
        />
    @else
        <x-email-integration::mailbox-health-banner
            :mailboxes="$this->unhealthyMailboxes"
            class="border-b border-gray-200/60 dark:border-white/10"
        />
    @endunless

    @php
        $pageTabShown = $this->showsConversations ? $tab : ($tab ?? \Relaticle\EmailIntegration\Enums\EmailPageTab::DRAFTS);
    @endphp

    {{-- ── Page tabs: the drafts, outbox, failed and template lists, each a nested
         Livewire component also hosted by a standalone page. ────────────── --}}
    <div data-reveal-active-tab class="flex items-center gap-1 overflow-x-auto border-b border-gray-200/60 px-4 py-3 lg:px-6 dark:border-white/10">
        @if ($this->showsConversations)
            @foreach (\Relaticle\EmailIntegration\Enums\ConversationView::cases() as $conversationView)
                @if ($conversationView->isPrimary())
                    <x-email-integration::view-tab
                        :view="$conversationView"
                        :active="$tab === null && $this->activeView === $conversationView"
                        :badge="$this->viewCounts[$conversationView->value] ?? null"
                    />
                @endif
            @endforeach

            <span class="ms-auto"></span>

            @foreach (\Relaticle\EmailIntegration\Enums\ConversationView::cases() as $conversationView)
                @unless ($conversationView->isPrimary())
                    <x-email-integration::view-tab
                        :view="$conversationView"
                        :active="$tab === null && $this->activeView === $conversationView"
                        :badge="$this->viewCounts[$conversationView->value] ?? null"
                    />
                @endunless
            @endforeach

            <span class="mx-1 h-5 w-px shrink-0 bg-gray-200 dark:bg-white/10" aria-hidden="true"></span>
        @endif

        @foreach (\Relaticle\EmailIntegration\Enums\EmailPageTab::cases() as $pageTab)
            <x-email-integration::page-tab
                :tab="$pageTab"
                :active="$pageTabShown === $pageTab"
                :badge="$this->tabCounts[$pageTab->value] ?? null"
            />
        @endforeach
    </div>

    @if ($pageTabShown === null)
        <div class="divide-y divide-gray-200/60 dark:divide-white/10">
            @forelse ($this->conversations->rows as $row)
                <x-email-integration::conversation-row :row="$row" selectable wire:key="conversation-{{ $row->conversationKey }}" />
            @empty
                <x-filament::empty-state
                    :heading="__('filament/pages/email-inbox.views.empty.'.$this->activeView->value)"
                    icon="heroicon-o-envelope"
                    icon-color="gray"
                    :contained="false"
                />
            @endforelse
        </div>

        @if ($this->conversations->previousCursor !== null || $this->conversations->nextCursor !== null)
            <div class="flex items-center justify-between border-t border-gray-200/60 px-4 py-3 sm:px-6 dark:border-white/10">
                <button
                    type="button"
                    wire:click="previousConversationsPage"
                    wire:loading.attr="disabled"
                    @disabled($this->conversations->previousCursor === null)
                    class="flex items-center gap-1 rounded px-2 py-1 text-xs text-gray-500 hover:bg-gray-100 disabled:pointer-events-none disabled:opacity-40 dark:text-gray-400 dark:hover:bg-gray-800"
                >
                    <x-heroicon-o-chevron-left class="h-3.5 w-3.5" />
                    {{ __('filament/pages/email-inbox.views.newer') }}
                </button>
                <button
                    type="button"
                    wire:click="nextConversationsPage"
                    wire:loading.attr="disabled"
                    @disabled($this->conversations->nextCursor === null)
                    class="flex items-center gap-1 rounded px-2 py-1 text-xs text-gray-500 hover:bg-gray-100 disabled:pointer-events-none disabled:opacity-40 dark:text-gray-400 dark:hover:bg-gray-800"
                >
                    {{ __('filament/pages/email-inbox.views.older') }}
                    <x-heroicon-o-chevron-right class="h-3.5 w-3.5" />
                </button>
            </div>
        @endif

        <x-email-integration::reader-overlay
            :email="$this->selectedEmail"
            :pending-access-requests="$this->pendingAccessRequests"
            layer="z-50"
        />
    @else
        @livewire($pageTabShown->livewireComponent(), $pageTabShown->livewireParameters(), key($pageTabShown->value.'-table'))
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
