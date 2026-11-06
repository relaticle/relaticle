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

        {{-- Keyed by what the list shows, so a new view, page or filter starts without the previous list's overrides. --}}
        <div
            wire:key="conversation-list-{{ $this->activeView->value }}-{{ $conversationsCursor }}-{{ hash('xxh128', serialize($this->conversationFilters)) }}"
            x-data="{
                view: @js($this->activeView->value),
                overrides: {},
                pending: 0,
                row(key) {
                    return this.$root.querySelector(`[data-conversation='${CSS.escape(key)}']`)
                },
                isHidden(key) {
                    return this.overrides[key]?.hidden === true
                },
                flag(key, name, server) {
                    return String(this.overrides[key]?.[name] ?? server)
                },
                effect(verb) {
                    return {
                        markDone: { done: true, hidden: this.view === 'needs_reply' },
                        reopen: { done: false, hidden: this.view === 'done' },
                        markRead: { unread: false },
                        markUnread: { unread: true },
                    }[verb]
                },
                restore(key, previous) {
                    if (previous === undefined) {
                        delete this.overrides[key]
                    } else {
                        this.overrides[key] = previous
                    }
                },
                advance(key) {
                    const rows = [...this.$root.querySelectorAll('[data-conversation]')].filter((row) => ! row.hidden)
                    const index = rows.findIndex((row) => row.dataset.conversation === key)
                    const target = index === -1 ? null : (rows[index + 1] ?? rows[index - 1])

                    target?.querySelector(':scope > button')?.focus()
                },
                async act(verb, keys, options = {}) {
                    const effect = this.effect(verb)
                    keys = keys.filter((key) => typeof key === 'string')

                    if (! effect || keys.length === 0) {
                        return
                    }

                    const previous = Object.fromEntries(keys.map((key) => [key, this.overrides[key]]))

                    if (options.advance) {
                        this.advance(keys.at(-1))
                    }

                    keys.forEach((key) => this.overrides[key] = { ...this.overrides[key], ...effect })
                    this.pending++

                    try {
                        const outcome = await this.$wire.call(verb, keys)
                        const applied = new Set(outcome.applied)

                        keys.filter((key) => ! applied.has(key)).forEach((key) => this.restore(key, previous[key]))

                        if (options.undoing && keys.some((key) => ! this.row(key))) {
                            await this.$wire.refreshConversations()
                        }

                        if (! options.undoing) {
                            this.$dispatch('email-triage-done', { verb, outcome })
                        }

                        await options.then?.()
                    } catch (error) {
                        keys.forEach((key) => this.restore(key, previous[key]))
                    } finally {
                        this.pending--
                    }
                },
            }"
            x-on:email-triage.window="act($event.detail.verb, $event.detail.keys, $event.detail)"
            x-bind:data-triage-pending="pending > 0 ? 'true' : null"
            class="divide-y divide-gray-200/60 dark:divide-white/10"
        >
            @forelse ($this->conversations->rows as $row)
                <x-email-integration::conversation-row :row="$row" selectable triage :current="$row->conversationKey === $this->selectedEmail?->conversation_key" wire:key="conversation-{{ $row->conversationKey }}" />
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
            triage
        />

        <x-email-integration::undo-toast />
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
