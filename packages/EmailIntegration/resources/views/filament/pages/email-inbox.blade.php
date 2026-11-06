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
            data-rendered="{{ hrtime(true) }}"
            x-data="{
                view: @js($this->activeView->value),
                cursor: @js($conversationsCursor),
                overrides: {},
                pending: 0,
                renders: 0,
                renderObserver: null,
                layoutObserver: null,
                observedDrawer: null,
                focusedKey: null,
                hoveredKey: null,
                selected: [],
                anchorKey: null,
                barFrame: { left: 0, width: 0, covered: false },
                registrations: [],
                entries: @js(\Relaticle\EmailIntegration\Enums\EmailShortcut::forStore()),
                init() {
                    {{-- A server render shows the truth, so the optimistic overrides end there unless an action is still in flight. --}}
                    this.renderObserver = new MutationObserver(() => this.serverRendered())
                    this.renderObserver.observe(this.$root, { attributes: true, attributeFilter: ['data-rendered'] })
                    {{-- The sidebar collapsing and the reader resizing move the list without a window resize. --}}
                    this.layoutObserver = new ResizeObserver(() => requestAnimationFrame(() => this.placeBar()))
                    this.layoutObserver.observe(this.$root)
                    this.carrySelection()

                    const entries = this.entries
                    const reader = (action) => () => this.inReader(action)

                    this.registrations = [
                        this.$store.shortcuts?.register('email-list', {
                            scope: 'list',
                            entries,
                            handlers: {
                                next: () => this.move(1),
                                previous: () => this.move(-1),
                                open: () => this.open(),
                                done: () => this.toggleDone(),
                                markUnread: () => this.markUnread(),
                                select: () => this.selectTarget(),
                                close: (event) => this.escapeSelection(event),
                                focusSearch: () => this.focusSearch(),
                            },
                            isActive: () => ! document.activeElement?.closest('[data-email-reader]'),
                        }),
                        this.$store.shortcuts?.register('email-reader', {
                            scope: 'reader',
                            entries,
                            handlers: {
                                next: reader('next'),
                                previous: reader('previous'),
                                close: reader('close'),
                                done: () => ! this.selectionOwnsKeys() && (this.inReader('done') || this.inReader('reopen')),
                                markUnread: () => ! this.selectionOwnsKeys() && this.inReader('mark-unread'),
                                reply: () => this.reply('reply'),
                                replyAll: () => this.reply('reply_all'),
                                forward: () => this.reply('forward'),
                                link: reader('link'),
                            },
                            isActive: () => document.querySelector('[data-email-reader]') !== null,
                        }),
                    ]
                },
                destroy() {
                    this.renderObserver?.disconnect()
                    this.layoutObserver?.disconnect()
                    this.$store.shortcuts?.unregister('email-list', this.registrations[0])
                    this.$store.shortcuts?.unregister('email-reader', this.registrations[1])
                },
                row(key) {
                    return key ? this.$root.querySelector(`[data-conversation='${CSS.escape(key)}']`) : null
                },
                rows() {
                    return [...this.$root.querySelectorAll('[data-conversation]')].filter((row) => ! row.hidden)
                },
                focusedRow() {
                    const row = document.activeElement?.closest('[data-conversation]')

                    return row && this.$root.contains(row) ? row : null
                },
                targetRow() {
                    const hovered = this.row(this.hoveredKey)

                    return this.focusedRow() ?? (hovered && ! hovered.hidden ? hovered : null)
                },
                {{-- Reads the reactive overrides and render count, so the tab stop moves to a neighbour when its row is hidden or refetched away. --}}
                allKeys() {
                    return this.renders >= 0 ? [...this.$root.querySelectorAll('[data-conversation]')].map((row) => row.dataset.conversation) : []
                },
                visibleKeys() {
                    return this.allKeys().filter((key) => ! this.isHidden(key))
                },
                rovingKey() {
                    const keys = this.allKeys()
                    const visible = (key) => ! this.isHidden(key)
                    const index = keys.indexOf(this.focusedKey)

                    if (index === -1) {
                        return keys.find(visible)
                    }

                    return keys.slice(index).find(visible) ?? keys.slice(0, index).reverse().find(visible)
                },
                tabIndexFor(key) {
                    return this.rovingKey() === key ? 0 : -1
                },
                focusRow(row) {
                    row?.querySelector(':scope > button')?.focus()
                    row?.scrollIntoView({ block: 'nearest' })
                },
                async move(step) {
                    const rows = this.rows()
                    const index = rows.indexOf(this.focusedRow())
                    const page = document.querySelector(`[data-conversations-page='${step > 0 ? 'older' : 'newer'}']:not([disabled])`)

                    if (index === -1 || rows[index + step]) {
                        this.focusRow(rows[index === -1 ? 0 : index + step])

                        return
                    }

                    if (page) {
                        await (step > 0 ? this.$wire.nextConversationsPage() : this.$wire.previousConversationsPage())
                        const loaded = [...document.querySelectorAll('[data-conversation]')].filter((row) => ! row.hidden)
                        this.focusRow(step > 0 ? loaded[0] : loaded.at(-1))
                    }
                },
                open() {
                    const row = this.targetRow()
                    row?.querySelector(':scope > button')?.click()

                    return row !== null
                },
                isSelected(key) {
                    return this.selected.includes(key)
                },
                toggleSelect(key, event = null) {
                    const keys = this.visibleKeys()
                    const from = keys.indexOf(this.anchorKey)
                    const to = keys.indexOf(key)

                    if (event?.shiftKey && from !== -1 && to !== -1) {
                        this.selected = [...new Set([...this.selected, ...keys.slice(Math.min(from, to), Math.max(from, to) + 1)])]
                    } else {
                        this.selected = this.isSelected(key) ? this.selected.filter((selected) => selected !== key) : [...this.selected, key]
                    }

                    this.anchorKey = key
                },
                pageSelected() {
                    const keys = this.visibleKeys()

                    return keys.length > 0 && keys.every((key) => this.isSelected(key))
                },
                togglePage() {
                    this.selected = this.pageSelected() ? [] : this.visibleKeys()
                    this.anchorKey = null
                },
                clearSelection() {
                    this.selected = []
                    this.anchorKey = null
                },
                selectTarget() {
                    const row = this.targetRow()

                    if (! row) {
                        return false
                    }

                    this.toggleSelect(row.dataset.conversation)
                },
                {{-- With a selection and focus outside the reader, e and u act on the selection, never the open conversation. --}}
                selectionOwnsKeys() {
                    return this.selected.length > 0 && ! document.activeElement?.closest('[data-email-reader]')
                },
                {{-- A search or filter builds a new list on the same view and page; the selection follows the rows it still shows. Any other list starts empty. --}}
                carrySelection() {
                    const host = this.$root.closest('[wire\\:id]')
                    const carried = host?.emailListSelection
                    const remember = () => host && (host.emailListSelection = { view: this.view, cursor: this.cursor, selected: [...this.selected] })

                    if (carried?.view === this.view && carried?.cursor === this.cursor) {
                        this.selected = carried.selected.filter((key) => this.row(key))
                    }

                    remember()
                    this.$watch('selected', remember)
                },
                escapeSelection(event) {
                    if (this.selected.length === 0 || event.target?.matches?.('input:not([type=checkbox]), textarea, [contenteditable=true]')) {
                        return false
                    }

                    this.clearSelection()
                },
                {{-- Only rows not already in the target state are sent, so Undo is the exact inverse. --}}
                needsChange(key, verb) {
                    return {
                        markDone: () => this.flag(key, 'done') !== 'true',
                        reopen: () => this.flag(key, 'done') === 'true',
                        markRead: () => this.flag(key, 'unread') === 'true',
                        markUnread: () => this.flag(key, 'unread') !== 'true',
                    }[verb]()
                },
                async bulk(verb) {
                    const selection = [...this.selected]
                    const shown = selection.filter((key) => ! this.isHidden(key))
                    const keys = shown.filter((key) => this.needsChange(key, verb))

                    this.clearSelection()

                    if (keys.length === 0) {
                        return shown.length > 0 && this.$dispatch('email-triage-unchanged', { verb, count: shown.length })
                    }

                    if (! await this.act(verb, keys)) {
                        this.selected = selection.filter((key) => this.row(key))
                    }
                },
                placeBar() {
                    const drawer = document.querySelector('[data-email-reader]')
                    const list = this.$root.getBoundingClientRect()

                    if (drawer && drawer !== this.observedDrawer) {
                        this.observedDrawer && this.layoutObserver?.unobserve(this.observedDrawer)
                        this.layoutObserver?.observe(drawer)
                        this.observedDrawer = drawer
                    }
                    const right = drawer?.offsetWidth > 0 ? window.innerWidth - drawer.offsetWidth : window.innerWidth

                    this.barFrame = { left: list.left, width: Math.max(0, right - list.left), covered: right - list.left < 320 }
                    this.$dispatch('email-selection-bar', { shown: this.selected.length > 0 && ! this.barFrame.covered })
                },
                toggleDone() {
                    if (this.selected.length > 0) {
                        return this.bulk(this.view === 'done' ? 'reopen' : 'markDone')
                    }

                    const row = this.targetRow()

                    if (! row) {
                        return false
                    }

                    const verb = row.dataset.done === 'true' ? 'reopen' : 'markDone'
                    this.act(verb, [row.dataset.conversation], { advance: verb === 'markDone' && row === this.focusedRow() })
                },
                markUnread() {
                    if (this.selected.length > 0) {
                        return this.bulk('markUnread')
                    }

                    const row = this.targetRow()

                    if (! row) {
                        return false
                    }

                    this.act('markUnread', [row.dataset.conversation])
                },
                focusSearch() {
                    document.querySelector('[data-conversation-search] input')?.focus()
                },
                inReader(action) {
                    const button = document.querySelector(`[data-email-reader] [data-reader-action='${action}']`)

                    if (! button) {
                        return false
                    }

                    button.disabled || button.click()

                    return true
                },
                reply(mode) {
                    const emailId = document.querySelector('[data-email-reader][data-reply-target]')?.dataset.replyTarget

                    if (! emailId) {
                        return false
                    }

                    window.dispatchEvent(new CustomEvent('composer:reply', { detail: { emailId, mode } }))
                },
                serverRendered() {
                    if (this.pending === 0) {
                        this.overrides = {}
                    }

                    this.selected = this.selected.filter((key) => this.row(key))

                    this.renders++
                },
                isHidden(key) {
                    return this.renders >= 0 && this.overrides[key]?.hidden === true
                },
                flag(key, name) {
                    const server = this.renders >= 0 && this.row(key)?.getAttribute(`data-server-${name}`)

                    return String(this.overrides[key]?.[name] ?? server === 'true')
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
                    const rows = this.rows()
                    const index = rows.findIndex((row) => row.dataset.conversation === key)

                    this.focusRow(index === -1 ? null : (rows[index + 1] ?? rows[index - 1]))
                },
                async act(verb, keys, options = {}) {
                    const effect = this.effect(verb)
                    keys = keys.filter((key) => typeof key === 'string')

                    if (! effect || keys.length === 0) {
                        return keys.length === 0
                    }

                    const previous = Object.fromEntries(keys.map((key) => [key, this.overrides[key]]))
                    {{-- Done holds back only the inbound this row showed; Undo replays what the original rows showed. --}}
                    const seen = Object.fromEntries(keys.map((key) => [key, options.seen && Object.hasOwn(options.seen, key) ? options.seen[key] : (this.row(key)?.dataset.lastInbound || null)]))

                    if (options.advance) {
                        this.advance(keys.at(-1))
                    }

                    keys.forEach((key) => this.overrides[key] = { ...this.overrides[key], ...effect })
                    this.pending++

                    let outcome

                    try {
                        outcome = await this.$wire.call(verb, verb === 'markDone' ? seen : keys)
                    } catch (error) {
                        outcome = null
                    } finally {
                        this.pending--
                    }

                    if (! outcome?.applied) {
                        keys.forEach((key) => this.restore(key, previous[key]))

                        return false
                    }

                    const applied = new Set(outcome.applied)
                    keys.filter((key) => ! applied.has(key)).forEach((key) => this.restore(key, previous[key]))

                    if (! options.undoing) {
                        this.$dispatch('email-triage-done', { verb, outcome, seen })
                    }

                    {{-- The write has landed, so what follows renders from the server and never restores the rows. --}}
                    if (options.undoing && keys.some((key) => ! this.row(key))) {
                        await this.$wire.refreshConversations()
                    }

                    await options.then?.()

                    return true
                },
            }"
            x-on:email-triage.window="act($event.detail.verb, $event.detail.keys, $event.detail)"
            x-on:focusin="focusedKey = $event.target.closest('[data-conversation]')?.dataset.conversation ?? focusedKey"
            x-on:mouseover="hoveredKey = $event.target.closest('[data-conversation]')?.dataset.conversation ?? null"
            x-on:mouseleave="hoveredKey = null"
            x-bind:data-triage-pending="pending > 0 ? 'true' : null"
            x-bind:class="selected.length > 0 && 'pb-24'"
            x-effect="renders; selected.length; placeBar()"
        >
            @if ($this->conversations->rows !== [])
                <label class="flex items-center gap-2 border-b border-gray-200/60 px-4 py-1.5 text-xs text-gray-500 sm:px-6 dark:border-white/10 dark:text-gray-400">
                    <input
                        type="checkbox"
                        data-select-page
                        x-bind:checked="pageSelected()"
                        x-effect="$el.indeterminate = selected.length > 0 && ! pageSelected()"
                        x-on:change="togglePage()"
                        class="fi-checkbox-input"
                    />
                    {{ __('filament/pages/email-inbox.triage.selection.select_page') }}
                </label>
            @endif

            <div class="divide-y divide-gray-200/60 dark:divide-white/10">
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
                        data-conversations-page="newer"
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
                        data-conversations-page="older"
                        wire:loading.attr="disabled"
                        @disabled($this->conversations->nextCursor === null)
                        class="flex items-center gap-1 rounded px-2 py-1 text-xs text-gray-500 hover:bg-gray-100 disabled:pointer-events-none disabled:opacity-40 dark:text-gray-400 dark:hover:bg-gray-800"
                    >
                        {{ __('filament/pages/email-inbox.views.older') }}
                        <x-heroicon-o-chevron-right class="h-3.5 w-3.5" />
                    </button>
                </div>
            @endif

            <x-email-integration::triage-bar />
        </div>

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
