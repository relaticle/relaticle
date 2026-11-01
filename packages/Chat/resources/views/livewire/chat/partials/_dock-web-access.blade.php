{{-- The web access request: the whole dock while it is the only step. Expects $step, $proposal and $metersCredits.
     Cmd/Ctrl+Enter allows it here only; the plan shortcut never approves an own-approval step. --}}
@php
    $reason = is_string($proposal->display_data['reason'] ?? null) ? $proposal->display_data['reason'] : '';
@endphp

<div
    class="px-4 pb-2 pt-3"
    wire:key="web-access-{{ $step['id'] }}"
    data-web-access-dock
    x-data="{
        allowsFromKeyboard(event) {
            if (! ((event.metaKey || event.ctrlKey) && event.key === 'Enter') || event.repeat || this.$el.offsetParent === null) return false;

            const root = this.$el.closest('[x-data^=chatInterface]');
            const chat = root ? Alpine.$data(root) : null;

            return chat !== null && ! chat.isStreaming && chat.visiblePendingActions().length === 1 && chat.input.trim().length === 0;
        },
    }"
    x-on:keydown.window="if (allowsFromKeyboard($event)) { $event.preventDefault(); $event.stopImmediatePropagation(); $wire.approveStep(@js($step['id'])) }"
>
    <div class="flex items-start gap-2.5">
        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400" aria-hidden="true">
            {{ svg('ri-global-line', 'h-3.5 w-3.5') }}
        </span>

        <div class="min-w-0 flex-1">
            <p class="break-words text-sm leading-6 text-gray-900 dark:text-white">
                <span class="font-semibold">{{ __('Search web:') }}</span>
                <span data-web-access-query>{{ $step['summary'] }}</span>
            </p>
            <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-300" data-web-access-note>
                {{ __(':name wants to search the web for this.', ['name' => config('chat.assistant_name')]) }}
                @if ($metersCredits)
                    {{ __('Each search uses :count credits.', ['count' => config('chat.web_search_credit_cost')]) }}
                @endif
            </p>
            @if ($reason !== '')
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500" data-web-access-reason>{{ $reason }}</p>
            @endif
        </div>
    </div>
</div>

@error('resolve')
    <p class="mx-4 mb-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-400/10 dark:text-red-400" role="alert">
        {{ $message }}
    </p>
@enderror

<div class="flex shrink-0 items-center justify-end gap-2 border-t border-gray-100 px-4 py-2 dark:border-white/5">
    <button
        type="button"
        wire:click="rejectStep(@js($step['id']))"
        wire:loading.attr="disabled"
        class="inline-flex h-7 items-center rounded-md px-2.5 text-xs font-medium text-gray-600 transition hover:bg-gray-100 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 motion-safe:active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white"
    >
        {{ __('Deny') }}
    </button>

    <button
        type="button"
        wire:click="approveAlways(@js($step['id']))"
        wire:loading.attr="disabled"
        class="inline-flex h-7 items-center rounded-md bg-white px-2.5 text-xs font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 motion-safe:active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60 dark:bg-white/5 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-white/10"
    >
        {{ __('Always allow') }}
    </button>

    <button
        type="button"
        wire:click="approveStep(@js($step['id']))"
        wire:loading.attr="disabled"
        class="inline-flex h-7 items-center gap-1.5 rounded-md bg-primary-600 px-2.5 text-xs font-medium text-white shadow-sm transition hover:bg-primary-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 motion-safe:active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60"
    >
        <x-heroicon-o-arrow-path class="h-3 w-3 motion-safe:animate-spin" wire:loading wire:target="approveStep" aria-hidden="true" />
        <span>{{ __('Allow') }}</span>
        <kbd
            x-data
            x-text="/Mac|iP/.test(navigator.platform) ? '⌘⏎' : 'Ctrl+⏎'"
            class="hidden rounded bg-white/20 px-1 py-0.5 font-sans text-[length:var(--text-pico)] font-medium sm:inline"
        ></kbd>
    </button>
</div>
