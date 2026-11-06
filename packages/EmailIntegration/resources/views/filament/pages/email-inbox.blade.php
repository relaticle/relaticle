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

    @if ($this->showsConversations)
        <x-email-integration::live-conversations :user-id="auth()->id()" :workspace-id="filament()->getTenant()?->getKey()" />
    @endif

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
        <div class="flex flex-col gap-2 border-b border-gray-200/60 px-4 py-3 sm:flex-row sm:items-center lg:px-6 dark:border-white/10">
            <form
                wire:submit="submitSearch"
                x-data
                x-on:keydown.slash.window="
                    if ($event.ctrlKey || $event.metaKey || $event.altKey || $event.target.closest('input, textarea, select, [contenteditable], [role=dialog]')) return
                    $event.preventDefault()
                    $el.querySelector('input').focus()
                "
                class="w-full sm:max-w-sm sm:shrink-0"
                data-conversation-search
            >
                <x-email-integration::search-bar :search="$search" :framed="false" />
            </form>

            <div class="min-w-0 flex-1">
                <x-email-integration::filter-chip-bar
                    :chips="$this->filterChips"
                    :editable="$this->editableFilters()"
                    :actions="[
                        'mailbox' => $this->mailboxFilterAction,
                        'sent_at' => $this->sentAtFilterAction,
                        'record' => $this->recordFilterAction,
                        'category' => $this->categoryFilterAction,
                    ]"
                />
            </div>
        </div>

        @if ($this->filterError !== null)
            <p role="alert" class="border-b border-gray-200/60 px-4 py-2 text-sm text-danger-600 lg:px-6 dark:border-white/10 dark:text-danger-400" data-filter-error>
                {{ $this->filterError }}
            </p>
        @endif

        <div class="divide-y divide-gray-200/60 dark:divide-white/10">
            @forelse ($this->conversations->rows as $row)
                <x-email-integration::conversation-row :row="$row" selectable :current="$row->conversationKey === $this->selectedEmail?->conversation_key" wire:key="conversation-{{ $row->conversationKey }}" />
            @empty
                <x-filament::empty-state
                    :heading="$this->isFiltering() ? __('filament/pages/email-inbox.views.no_match') : __('filament/pages/email-inbox.views.empty.'.$this->activeView->value)"
                    :description="$this->isFiltering() ? __('filament/pages/email-inbox.views.no_match_hint') : null"
                    :icon="$this->isFiltering() ? 'heroicon-o-magnifying-glass' : 'heroicon-o-envelope'"
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
    @else
        @livewire($pageTabShown->livewireComponent(), $pageTabShown->livewireParameters(), key($pageTabShown->value.'-table'))
    @endif

    @if ($this->showsConversations)
        <x-email-integration::reader-drawer
            :email="$this->selectedEmail"
            :pending-access-requests="$this->pendingAccessRequests"
            :thread="$this->thread"
            :bodies="$this->expandedBodies"
            :expanded="[...$expandedEmailIds, $selectedEmailId]"
            :row="$this->selectedConversationRow"
            :adjacent="$this->adjacentConversations"
            :unavailable="$this->selectedEmailUnavailable"
        />
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
