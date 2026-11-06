@php
    $buttonClass = 'flex min-w-0 flex-1 flex-col items-center gap-0.5 rounded-lg px-2 py-1.5 text-[11px] font-medium text-gray-700 transition hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 @2xl:flex-none @2xl:flex-row @2xl:gap-1.5 @2xl:text-sm dark:text-gray-200 dark:hover:bg-white/10';
    $actions = [
        ['verb' => 'markRead', 'icon' => 'ri-mail-open-line', 'label' => 'mark_read', 'shortcut' => null, 'view' => null],
        ['verb' => 'markUnread', 'icon' => 'ri-mail-unread-line', 'label' => 'mark_unread', 'shortcut' => 'markUnread', 'view' => null],
        ['verb' => 'markDone', 'icon' => 'ri-check-line', 'label' => 'done', 'shortcut' => 'done', 'view' => false],
        ['verb' => 'reopen', 'icon' => 'ri-inbox-unarchive-line', 'label' => 'reopen', 'shortcut' => 'done', 'view' => true],
    ];
@endphp

{{-- Spans the list beside any open reader, so the bar never sits under the drawer. --}}
<div
    x-show="selected.length > 0 && ! barFrame.covered"
    x-cloak
    x-transition.opacity
    x-bind:style="{ left: barFrame.left + 'px', width: barFrame.width + 'px' }"
    class="@container pointer-events-none fixed bottom-4 z-40 flex justify-center px-4"
>
    <div
        role="toolbar"
        aria-label="{{ __('filament/pages/email-inbox.triage.selection.toolbar') }}"
        data-triage-bar
        class="pointer-events-auto flex w-full max-w-xl min-w-0 items-center gap-1 rounded-xl bg-white p-1.5 shadow-lg ring-1 ring-gray-950/10 @2xl:w-auto dark:bg-gray-900 dark:ring-white/10"
    >
        <p
            class="shrink-0 px-2 text-sm font-semibold tabular-nums text-gray-950 dark:text-white"
            data-triage-selected
            x-text="@js(__('filament/pages/email-inbox.triage.selection.count')).replace(':count', selected.length)"
        ></p>

        @foreach ($actions as $action)
            <button
                type="button"
                data-triage-bulk="{{ $action['verb'] }}"
                x-on:click="bulk(@js($action['verb']))"
                @if ($action['view'] !== null)
                    x-show="(view === 'done') === @js($action['view'])"
                @endif
                @if ($action['shortcut'] !== null)
                    x-bind:title="$store.shortcuts?.hint(@js($action['shortcut']), @js(__('filament/pages/email-inbox.triage.actions.'.$action['label'])))"
                    x-bind:aria-keyshortcuts="$store.shortcuts?.keysFor(@js($action['shortcut']))"
                @endif
                class="{{ $buttonClass }}"
            >
                <x-filament::icon :icon="$action['icon']" class="size-4 shrink-0" />
                <span class="truncate">{{ __('filament/pages/email-inbox.triage.actions.'.$action['label']) }}</span>
            </button>
        @endforeach

        <button
            type="button"
            data-triage-clear
            x-on:click="clearSelection()"
            class="{{ $buttonClass }}"
        >
            <x-heroicon-m-x-mark class="size-4 shrink-0" />
            <span class="truncate">{{ __('filament/pages/email-inbox.triage.selection.clear') }}</span>
        </button>
    </div>
</div>
