@php
    $pollingInterval = $this->getPollingInterval();
    $hasNotifications = collect($groups)->flatten(1)->isNotEmpty();
@endphp

<div
    class="fi-inbox"
    @if ($pollingInterval) wire:poll.{{ $pollingInterval }} @endif
    x-data="{
        open: false,
        tab: 'notifications',
        left: 8,
        toggle() {
            this.open ? this.hide() : this.show()
        },
        show() {
            this.measure()
            this.open = true
            this.$nextTick(() => this.open && this.$refs['tab-' + this.tab]?.focus())
        },
        hide(returnFocus = true) {
            if (! this.open) {
                return
            }

            this.open = false

            if (Object.keys(this.$wire.decidedRequests).length) {
                this.$wire.forgetDecidedRequests()
            }

            if (returnFocus) {
                this.$nextTick(() => this.$refs.trigger?.focus())
            }
        },
        measure() {
            const sidebar = document.querySelector('.fi-sidebar')

            this.left = sidebar ? Math.round(sidebar.getBoundingClientRect().right) + 8 : 8
        },
        async act(call, el, selector) {
            const panel = el.closest('.fi-inbox-panel')
            const key = this.neighbourKey(el, selector)

            try {
                await call()
            } catch {
                return
            }

            requestAnimationFrame(() => {
                const row = key && panel.querySelector(`li[data-inbox-key='${key}']`)
                const target = row?.querySelector(selector)
                    ?? row?.querySelector('a[href], button:not([disabled])')
                    ?? panel.querySelector('[role=tab][aria-selected=true]')

                target?.focus()
            })
        },
        neighbourKey(el, selector) {
            const row = el.closest('li')
            const rows = [...el.closest('[role=tabpanel]').querySelectorAll('li[data-inbox-key]')]
            const index = rows.indexOf(row)
            const candidates = [...rows.slice(index + 1), ...rows.slice(0, index).reverse()]

            return (candidates.find((candidate) => candidate.querySelector(selector)) ?? candidates[0])?.dataset.inboxKey
        },
        async markAllRead() {
            const tab = this.$refs['tab-' + this.tab]

            try {
                await this.$wire.markAllNotificationsAsRead()
            } catch {
                return
            }

            requestAnimationFrame(() => tab.focus())
        },
        focusTab(name) {
            this.tab = name
            this.$nextTick(() => this.$refs['tab-' + name]?.focus())
        },
    }"
    x-on:resize.window.debounce.150ms="open && measure()"
    x-on:inbox:close.window="hide(false)"
>
    {{ $this->getTrigger()->with(['unreadNotificationsCount' => $unreadCount, 'pendingRequestsCount' => $pendingCount]) }}

    <div class="sr-only" role="status" aria-live="polite" aria-atomic="true">
        {{ $announcement }}
    </div>

    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            x-ref="panel"
            x-trap.noreturn.noautofocus="open"
            x-bind:style="{ '--inbox-left': left + 'px' }"
            x-on:click.outside="if (! $refs.trigger.contains($event.target)) hide(false)"
            x-on:keydown.escape.window="hide()"
            x-transition:enter="motion-safe:transition motion-safe:duration-150 motion-safe:ease-out"
            x-transition:enter-start="motion-safe:opacity-0 motion-safe:scale-95"
            x-transition:enter-end="motion-safe:opacity-100 motion-safe:scale-100"
            x-transition:leave="motion-safe:transition motion-safe:duration-100 motion-safe:ease-in"
            x-transition:leave-start="motion-safe:opacity-100"
            x-transition:leave-end="motion-safe:opacity-0"
            role="dialog"
            aria-modal="true"
            aria-labelledby="inbox-heading"
            tabindex="-1"
            class="fi-inbox-panel"
        >
            <div class="fi-inbox-header">
                <div class="flex items-center gap-2 px-4 pt-3">
                    <h2 id="inbox-heading" class="text-sm font-semibold text-gray-950 dark:text-white">
                        {{ __('notifications.panel.heading') }}
                    </h2>

                    <div class="ms-auto flex items-center gap-1">
                        @if ($settingsUrl)
                            <a
                                {{ \Filament\Support\generate_href_html($settingsUrl) }}
                                x-on:click="hide(false)"
                                class="fi-inbox-icon-btn"
                                aria-label="{{ __('notifications.panel.settings') }}"
                                title="{{ __('notifications.panel.settings') }}"
                            >
                                <x-filament::icon icon="lucide-settings" class="size-4" />
                            </a>
                        @endif

                        <button
                            type="button"
                            x-on:click="hide()"
                            class="fi-inbox-icon-btn lg:hidden"
                            aria-label="{{ __('notifications.panel.close') }}"
                            title="{{ __('notifications.panel.close') }}"
                        >
                            <x-filament::icon icon="lucide-x" class="size-4" />
                        </button>
                    </div>
                </div>

                <div class="mt-2 flex items-end justify-between gap-3 px-4">
                    <div
                        role="tablist"
                        aria-label="{{ __('notifications.panel.tabs.label') }}"
                        class="-mb-px flex gap-4"
                        x-on:keydown.arrow-right.prevent="focusTab(tab === 'notifications' ? 'requests' : 'notifications')"
                        x-on:keydown.arrow-left.prevent="focusTab(tab === 'notifications' ? 'requests' : 'notifications')"
                    >
                        @foreach (['notifications' => $unreadCount, 'requests' => $pendingCount] as $name => $count)
                            <button
                                type="button"
                                role="tab"
                                id="inbox-tab-{{ $name }}"
                                x-ref="tab-{{ $name }}"
                                aria-controls="inbox-tabpanel-{{ $name }}"
                                x-bind:aria-selected="tab === '{{ $name }}'"
                                x-bind:tabindex="tab === '{{ $name }}' ? 0 : -1"
                                x-on:click="tab = '{{ $name }}'"
                                class="fi-inbox-tab"
                                x-bind:class="tab === '{{ $name }}' && 'fi-inbox-tab-active'"
                            >
                                {{ __("notifications.panel.tabs.{$name}") }}

                                @if ($count)
                                    <span class="fi-inbox-tab-count">{{ $count }}</span>
                                @endif
                            </button>
                        @endforeach
                    </div>

                    @if ($unreadCount)
                        <button
                            type="button"
                            x-show="tab === 'notifications'"
                            x-on:click="markAllRead()"
                            class="mb-2 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                        >
                            {{ __('notifications.panel.mark_all_read') }}
                        </button>
                    @endif
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain">
                <div
                    role="tabpanel"
                    id="inbox-tabpanel-notifications"
                    aria-labelledby="inbox-tab-notifications"
                    x-show="tab === 'notifications'"
                >
                    @if ($hasNotifications)
                        @foreach ($groups as $group => $rows)
                            @continue($rows === [])

                            <section aria-labelledby="inbox-group-{{ $group }}">
                                <h3 id="inbox-group-{{ $group }}" class="fi-inbox-group-heading">
                                    {{ __("notifications.panel.groups.{$group}") }}
                                </h3>

                                <ul role="list">
                                    @foreach ($rows as $row)
                                        @include('livewire.app.notifications.panel-notification-row')
                                    @endforeach
                                </ul>
                            </section>
                        @endforeach
                        @if ($hasOlderNotifications)
                            <p class="px-4 py-3 text-center text-xs text-gray-500 dark:text-gray-400">
                                {{ __('notifications.panel.footer', ['count' => $pageSize]) }}
                            </p>
                        @endif
                    @else
                        @include('livewire.app.notifications.panel-empty-state', [
                            'icon' => 'lucide-bell',
                            'heading' => __('notifications.panel.empty.notifications.heading'),
                            'description' => __('notifications.panel.empty.notifications.description'),
                        ])
                    @endif
                </div>

                <div
                    role="tabpanel"
                    id="inbox-tabpanel-requests"
                    aria-labelledby="inbox-tab-requests"
                    x-show="tab === 'requests'"
                    x-cloak
                >
                    @if ($requests !== [])
                        <ul role="list">
                            @foreach ($requests as $request)
                                @include('livewire.app.notifications.panel-request-row')
                            @endforeach
                        </ul>
                    @else
                        @include('livewire.app.notifications.panel-empty-state', [
                            'icon' => 'lucide-inbox',
                            'heading' => __('notifications.panel.empty.requests.heading'),
                            'description' => __('notifications.panel.empty.requests.description'),
                        ])
                    @endif
                </div>
            </div>
        </div>
    </template>

    @if ($broadcastChannel = $this->getBroadcastChannel())
        @script
            <script>
                setUpFilamentBroadcastNotifications({
                    $wire,
                    broadcastChannel: @js($broadcastChannel),
                    event: '.database-notifications.sent',
                    onNotification: () => $wire.call('$refresh'),
                })
            </script>
        @endscript
    @endif
</div>
