{{-- Input area --}}
<div class="border-t border-gray-200 bg-white px-4 py-4 dark:border-gray-700 dark:bg-gray-900">
    <div class="mx-auto max-w-3xl">
        {{-- Docked pending proposal: a nested Livewire component hosts the active proposal so it can
             render real Filament field editors in place (Phase C). Alpine stays the source of truth for
             whether a proposal is pending and pushes the active id to the card via `proposal:set-active`. --}}
        <div
            x-show="hasPendingProposal"
            x-effect="syncActiveProposal()"
            x-transition:enter="transition duration-300 ease-[cubic-bezier(0.16,1,0.3,1)]"
            x-transition:enter-start="translate-y-2 opacity-0"
            x-transition:enter-end="translate-y-0 opacity-100"
            x-transition:leave="transition duration-150 ease-in"
            x-transition:leave-start="translate-y-0 opacity-100"
            x-transition:leave-end="translate-y-1 opacity-0"
            class="mb-3"
        >
            <div class="mb-2 flex items-center gap-1.5 text-xs font-medium text-gray-500 dark:text-gray-400">
                <x-heroicon-o-sparkles class="h-3.5 w-3.5 text-primary-500 dark:text-primary-400" aria-hidden="true" />
                <span>{{ __('Review before continuing') }}</span>
            </div>
            <div class="max-h-[55vh] overflow-y-auto">
                <livewire:chat.proposal-card :context="$context ?? 'conversation'" wire:key="proposal-dock-{{ $context ?? 'conversation' }}" />
            </div>
        </div>

        @include('chat::livewire.chat.partials._banners')

        {{-- Ambient context: the record the assistant treats as "this". Dismissible —
             the record is only sent while this is visible. --}}
        <div
            x-show="!hasPendingProposal && pageContext && pageContextLabel && !pageContextDismissed && !pageContextConsumed"
            x-cloak
            class="mb-2 flex items-center gap-1.5 text-xs"
        >
            <span class="inline-flex max-w-full items-center gap-1.5 rounded-md bg-primary-50 px-2 py-1 font-medium text-primary-700 ring-1 ring-inset ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-300 dark:ring-primary-400/30">
                <span class="shrink-0" x-html="pageContextIcon()"></span>

                <span class="truncate" x-text="pageContextLabel"></span>

                <button
                    type="button"
                    x-on:click="pageContextDismissed = true"
                    x-bind:aria-label="'Stop referring to ' + pageContextLabel"
                    class="-me-0.5 shrink-0 rounded p-0.5 transition hover:bg-primary-600/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary-500 dark:hover:bg-primary-400/20"
                >
                    <x-heroicon-m-x-mark class="h-3.5 w-3.5" aria-hidden="true" />
                </button>
            </span>
        </div>

        <form x-show="!hasPendingProposal" x-on:submit.prevent="sendMessage()">
            <div
                x-data="chatEditor({
                    initialDocument: { type: 'doc', content: [] },
                    placeholder: 'Ask anything...',
                    autofocus: @js(($context ?? 'conversation') !== 'side-panel'),
                    onSubmit: () => $root.dispatchEvent(new CustomEvent('chat:editor-submit', { bubbles: true })),
                    onChange: ({ document, text }) => {
                        $root.dispatchEvent(new CustomEvent('chat:editor-change', { bubbles: true, detail: { document, text } }));
                    },
                })"
                x-on:chat:editor-submit.window="sendMessage()"
                x-on:chat:editor-change.window="input = $event.detail.text; saveDraft()"
                {{-- No global setter needed — chatInterface uses localEditor() to scope-resolve. --}}
                data-chat-context="{{ $context ?? 'conversation' }}"
                class="relative rounded-2xl border border-gray-200 bg-white transition focus-within:border-primary-500 dark:border-gray-700 dark:bg-gray-800"
            >
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
                        class="text-[11px]"
                        aria-live="polite"
                    ></span>
                    <div x-show="text.length <= 4000" class="flex-1"></div>

                    <div class="flex items-center gap-2">
                        @include('chat::livewire.chat.partials._model-picker')

                        <button
                            x-show="!isStreaming"
                            type="submit"
                            class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-600 text-white transition hover:bg-primary-700 disabled:bg-primary-200 disabled:text-white dark:disabled:bg-primary-900/40 dark:disabled:text-primary-300"
                            :disabled="text.trim().length === 0 || text.length > 5000 || rateLimit !== null"
                            aria-label="Send message"
                        >
                            <x-heroicon-s-arrow-up class="h-4 w-4" />
                        </button>
                        <button
                            x-show="isStreaming"
                            type="button"
                            x-on:click="cancelStream()"
                            class="flex h-7 w-7 items-center justify-center rounded-lg bg-gray-900 text-white transition hover:bg-gray-700 dark:bg-gray-200 dark:text-gray-900 dark:hover:bg-gray-300"
                            aria-label="Stop generation"
                        >
                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <rect x="6" y="6" width="12" height="12" rx="2"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
