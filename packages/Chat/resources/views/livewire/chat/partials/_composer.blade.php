{{-- Input area --}}
<div class="border-t border-[var(--surface-shell-divider)] bg-[var(--surface-shell-bg)] px-4 py-4">
    <div class="mx-auto max-w-3xl">
        {{-- Docked pending proposal: a nested Livewire component hosts the active proposal so it can
             render real Filament field editors in place (Phase C). Alpine stays the source of truth for
             whether a proposal is pending and pushes the active id to the card via `proposal:set-active`. --}}
        <div
            x-show="hasPendingProposal"
            x-effect="syncActiveProposal()"
            x-transition:enter="motion-safe:transition motion-safe:duration-[var(--duration-base)] motion-safe:ease-[var(--ease-out-expo)]"
            x-transition:enter-start="motion-safe:translate-y-2 motion-safe:opacity-0"
            x-transition:enter-end="motion-safe:translate-y-0 motion-safe:opacity-100"
            x-transition:leave="motion-safe:transition motion-safe:duration-[var(--duration-fast)] motion-safe:ease-in"
            x-transition:leave-start="motion-safe:translate-y-0 motion-safe:opacity-100"
            x-transition:leave-end="motion-safe:translate-y-1 motion-safe:opacity-0"
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
            <span class="inline-flex max-w-full items-center gap-1.5 rounded-full bg-primary-50 px-2 py-0.5 text-[length:var(--text-micro)] font-medium text-primary-700 ring-1 ring-inset ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-300 dark:ring-primary-400/30">
                <span class="shrink-0" x-html="pageContextIcon()"></span>

                <span class="truncate" x-text="pageContextLabel"></span>

                <button
                    type="button"
                    x-on:click="pageContextDismissed = true"
                    x-bind:aria-label="@js(__('Stop referring to :label')).replace(':label', pageContextLabel)"
                    class="-me-0.5 shrink-0 rounded p-0.5 transition hover:bg-primary-600/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary-500 dark:hover:bg-primary-400/20"
                >
                    <x-heroicon-m-x-mark class="h-3.5 w-3.5" aria-hidden="true" />
                </button>
            </span>
        </div>

        <form x-show="!hasPendingProposal" x-on:submit.prevent="sendMessage()">
            <div
                x-data="chatEditor({
                    placeholder: @js(__('Ask anything...')),
                    autofocus: @js(($context ?? 'conversation') !== 'side-panel'),
                    mentionTexts: {
                        listLabel: @js(__('Mention suggestions')),
                        searching: @js(__('Searching…')),
                        loadFailed: @js(__("Couldn't load suggestions.")),
                        noMatches: @js(__('No matches for ":query".')),
                        typeLabels: @js([
                            'company' => __('Company'),
                            'people' => __('Person'),
                            'opportunity' => __('Deal'),
                            'task' => __('Task'),
                            'note' => __('Note'),
                        ]),
                    },
                    onSubmit: () => $root.dispatchEvent(new CustomEvent('chat:editor-submit', { bubbles: true })),
                    onChange: ({ document, text }) => {
                        $root.dispatchEvent(new CustomEvent('chat:editor-change', { bubbles: true, detail: { document, text } }));
                    },
                    onArrowUp: () => $root.dispatchEvent(new CustomEvent('chat:editor-arrow-up', { bubbles: true, detail: { context: @js($context ?? 'conversation') } })),
                })"
                x-on:chat:editor-submit.window="sendMessage()"
                x-on:chat:editor-change.window="input = $event.detail.text; saveDraft()"
                {{-- No global setter needed — chatInterface uses localEditor() to scope-resolve. --}}
                data-chat-context="{{ $context ?? 'conversation' }}"
            >
                @include('chat::livewire.chat.partials._composer-bar', [
                    'sendDisabled' => 'text.trim().length === 0 || text.length > 5000 || rateLimit !== null',
                ])
            </div>
        </form>
    </div>
</div>
