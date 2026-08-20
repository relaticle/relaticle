{{-- Shared input bar for every composer surface (full-page chat, side panel,
     dashboard). Renders the rounded container, the TipTap mount, and the
     trailing controls row (char counter, model picker, reserved mic slot,
     send/stop). The caller owns the surrounding `x-data="chatEditor(...)"`
     wrapper (and its `x-on:*` listeners + `data-chat-context`): this
     partial only needs `text`, `isStreaming`, `rateLimit`/`submitting` etc.
     to already be reachable up the Alpine scope chain, exactly as the editor
     mount below relies on `x-ref="editor"` resolving to that same wrapper's
     scope.

     $showStopButton (bool, default true): dashboard has no streaming
     concept: it navigates to the conversation page instead, so it passes
     false and the send button never hides behind a stop control.
     $sendDisabled (string, default below): the Alpine boolean expression fed
     to the send button's :disabled, unescaped since it is an
     author-supplied expression, never request data. --}}
@php
    $showStopButton ??= true;
    $sendDisabled ??= 'text.trim().length === 0 || text.length > 5000';
@endphp

<div class="relative rounded-2xl border border-[var(--surface-input-border)] bg-[var(--surface-input-bg)] transition focus-within:border-primary-500">
    {{-- wire:ignore: TipTap mounts into this node imperatively; without it
         Livewire's morphdom wipes the editor on every chat-interface re-render
         (e.g. after the first message), leaving an empty, unusable composer. --}}
    <div x-ref="editor" class="relative" wire:ignore></div>

    <div class="flex items-center justify-between gap-2 px-3 pb-2">
        <span
            x-show="text.length > 4000"
            x-cloak
            x-text="`${text.length.toLocaleString()} / 5,000`"
            :class="{
                'text-gray-500 dark:text-gray-400': text.length <= 4900,
                'text-amber-600 dark:text-amber-400': text.length > 4900 && text.length <= 5000,
                'text-red-600 dark:text-red-400': text.length > 5000,
            }"
            class="text-[length:var(--text-micro)]"
            aria-live="polite"
        ></span>
        <div x-show="text.length <= 4000" class="flex-1"></div>

        <div class="flex items-center gap-1.5">
            @include('chat::livewire.chat.partials._model-picker')

            {{-- Reserved mic slot: voice input ships in a later task. Present
                 and inert now so the bar's final layout doesn't shift when it
                 lands. --}}
            <button
                type="button"
                disabled
                title="{{ __('Voice input coming soon') }}"
                aria-label="{{ __('Voice input coming soon') }}"
                class="flex h-7 w-7 shrink-0 cursor-not-allowed items-center justify-center rounded-lg text-gray-300 dark:text-gray-600"
            >
                <x-heroicon-o-microphone class="h-4 w-4" aria-hidden="true" />
            </button>

            <button
                @if ($showStopButton) x-show="!isStreaming" @endif
                type="submit"
                class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-600 text-white transition hover:bg-primary-700 disabled:bg-primary-200 disabled:text-white dark:disabled:bg-primary-900/40 dark:disabled:text-primary-300"
                :disabled="{!! $sendDisabled !!}"
                aria-label="{{ __('Send message') }}"
            >
                <x-heroicon-s-arrow-up class="h-4 w-4" />
            </button>

            @if ($showStopButton)
                <button
                    x-show="isStreaming"
                    type="button"
                    x-on:click="cancelStream()"
                    class="flex h-7 w-7 items-center justify-center rounded-lg bg-gray-900 text-white transition hover:bg-gray-700 dark:bg-gray-200 dark:text-gray-900 dark:hover:bg-gray-300"
                    aria-label="{{ __('Stop generation') }}"
                >
                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <rect x="6" y="6" width="12" height="12" rx="2"/>
                    </svg>
                </button>
            @endif
        </div>
    </div>
</div>
