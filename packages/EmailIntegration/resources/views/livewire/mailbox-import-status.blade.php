<div
    wire:key="mailbox-import-{{ $placement }}"
    x-data="{
        storageKey: 'relaticle.mailbox-import-status',
        mailboxIds: @js(array_column($mailboxes, 'id')),
        hiddenIds: (() => {
            try {
                const stored = JSON.parse(sessionStorage.getItem('relaticle.mailbox-import-status') || 'null')

                return Array.isArray(stored?.dismissed) ? stored.dismissed : []
            } catch (e) {
                return []
            }
        })(),
        init() {
            let stored = null

            try {
                stored = JSON.parse(sessionStorage.getItem(this.storageKey) || 'null')
            } catch (e) {
                stored = null
            }

            if (stored === null) {
                return
            }

            if ($wire.dismissedAccountIds.length === 0 && Array.isArray(stored.dismissed)) {
                $wire.dismissedAccountIds = stored.dismissed
            }

            if ($wire.seenImportingIds.length === 0 && Array.isArray(stored.seen)) {
                $wire.seenImportingIds = stored.seen
            }

            this.persist()
        },
        cardVisible() {
            return this.mailboxIds.some((id) => ! this.hiddenIds.includes(id))
        },
        persist() {
            sessionStorage.setItem(this.storageKey, JSON.stringify({
                dismissed: $wire.dismissedAccountIds,
                seen: $wire.seenImportingIds,
            }))
        },
        async dismissIds(ids) {
            this.hiddenIds = [...new Set([...this.hiddenIds, ...ids])]

            for (const id of ids) {
                await $wire.dismiss(id)
            }

            this.persist()
        },
    }"
>
    @island(name: 'mailbox-import-status', always: true)
        @php
            $mailboxes = $this->visibleMailboxes();
            $anyImporting = array_any($mailboxes, fn (array $row): bool => $row['importing']);
            $shouldPoll = $this->shouldPoll();
            $placement = $this->placement;
        @endphp

        <div
            @if ($shouldPoll) wire:poll.5s="refreshStatus" @endif
            data-mailbox-import="{{ $placement }}"
            class="mt-10 overflow-hidden rounded-xl border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)]"
            x-cloak
            x-show="cardVisible()"
        >
            <div class="flex items-start justify-between gap-2 px-4 pt-4">
                <div class="flex min-w-0 items-center gap-1.5">
                    @if ($anyImporting)
                        <x-filament::icon
                            icon="heroicon-m-arrow-path"
                            class="h-4 w-4 shrink-0 text-primary-600 motion-safe:animate-spin dark:text-primary-400"
                        />
                    @endif
                    <p class="text-sm font-medium text-gray-950 dark:text-white">
                        {{ $anyImporting
                            ? __('filament/pages/email-accounts.sync_status.title_syncing')
                            : __('filament/pages/email-accounts.sync_status.title_complete') }}
                    </p>
                </div>
                <button
                    type="button"
                    class="rounded p-0.5 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                    x-on:click="dismissIds(@js(array_column($mailboxes, 'id')))"
                >
                    <x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4" />
                    <span class="sr-only">{{ __('filament/pages/email-accounts.sync_status.close') }}</span>
                </button>
            </div>

            <div class="mt-2">
                @foreach ($mailboxes as $mailbox)
                    <div
                        wire:key="mailbox-import-{{ $placement }}-row-{{ $mailbox['id'] }}"
                        x-show="! hiddenIds.includes(@js($mailbox['id']))"
                    >
                        <a
                            href="{{ $mailbox['settings_url'] }}"
                            class="block px-4 py-3 hover:bg-gray-50 dark:hover:bg-white/5"
                            title="{{ __('filament/pages/email-accounts.sync_status.open_settings') }}"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <p class="truncate text-sm text-gray-700 dark:text-gray-300">{{ $mailbox['email'] }}</p>
                                <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">
                                    {{ __('filament/pages/email-accounts.importing_percent', ['percent' => $mailbox['percent']]) }}
                                </span>
                            </div>
                            @if ($mailbox['hasCalendar'])
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ trans_choice('filament/pages/email-accounts.sync_status.meetings_processed', $mailbox['meetingsImported'], ['count' => $mailbox['meetingsImported']]) }}
                                </p>
                            @endif
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ trans_choice('filament/pages/email-accounts.sync_status.emails_processed', $mailbox['imported'], ['count' => $mailbox['imported']]) }}
                            </p>
                            <div
                                class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10"
                                role="progressbar"
                                aria-valuenow="{{ $mailbox['percent'] }}"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-label="{{ __('filament/pages/email-accounts.importing') }}"
                            >
                                <div class="h-full rounded-full bg-primary-600" style="width: {{ $mailbox['percent'] }}%"></div>
                            </div>
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    @endisland
</div>
