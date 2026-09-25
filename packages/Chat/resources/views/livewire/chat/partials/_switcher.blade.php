{{-- Conversation switcher overlay: Cmd+O / Ctrl+O (see onChatRootKeydown() in
     transcript.js). Full-page chat only: the side panel has its own history
     dropdown. Stays a normal (non-teleported) descendant of the chat root so
     keydown events inside it keep bubbling to the root's own x-on:keydown
     listener, which is what drives arrow-key navigation and Esc-to-close. --}}
<div
    x-show="switcherOpen"
    {{-- aria-modal tells assistive tech the background is inert, so Tab must not
         walk out of the panel into the transcript behind the backdrop. noreturn and
         noautofocus leave focus placement to the component, which already moves it
         to the input on open and restores it on close. --}}
    x-trap.noscroll.noreturn.noautofocus="switcherOpen"
    x-cloak
    x-on:click.self="closeSwitcher()"
    class="fixed inset-0 z-[60] flex items-start justify-center bg-gray-500/40 px-4 pt-[15vh] backdrop-blur-sm dark:bg-gray-950/60"
    role="dialog"
    aria-modal="true"
    aria-label="{{ __('Switch conversation') }}"
    x-transition:enter="motion-safe:transition motion-safe:ease-out motion-safe:duration-150"
    x-transition:enter-start="motion-safe:opacity-0"
    x-transition:enter-end="motion-safe:opacity-100"
    x-transition:leave="motion-safe:transition motion-safe:ease-in motion-safe:duration-100"
    x-transition:leave-start="motion-safe:opacity-100"
    x-transition:leave-end="motion-safe:opacity-0"
>
    <div class="w-full max-w-lg overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900">
        <div class="flex items-center gap-2 border-b border-gray-200 px-3 py-2.5 dark:border-gray-700">
            <x-heroicon-o-magnifying-glass class="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
            <input
                x-ref="switcherSearch"
                x-model="switcherQuery"
                x-on:input="switcherActiveIndex = 0"
                type="search"
                role="combobox"
                aria-expanded="true"
                aria-controls="chat-switcher-options"
                :aria-activedescendant="filteredSwitcherItems().length > 0 ? ('chat-switcher-option-' + switcherActiveIndex) : null"
                placeholder="{{ __('Search conversations...') }}"
                aria-label="{{ __('Search conversations') }}"
                class="w-full border-0 bg-transparent p-0 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-0 dark:text-white"
            />
            <kbd class="hidden shrink-0 rounded border border-gray-200 px-1.5 py-0.5 font-sans text-[length:var(--text-pico)] font-medium text-gray-400 sm:inline dark:border-gray-600">Esc</kbd>
        </div>

        <div class="max-h-80 overflow-y-auto py-1">
            {{-- Status rows live OUTSIDE the listbox: role=status children are
                 invalid listbox content and corrupt the announced option count. --}}
            <template x-if="switcherLoading">
                <p class="px-3 py-3 text-xs text-gray-500 dark:text-gray-400" role="status">{{ __('Loading…') }}</p>
            </template>

            <template x-if="!switcherLoading && switcherError">
                <p class="px-3 py-3 text-xs text-danger-600 dark:text-danger-400" role="status">
                    {{ __('Could not load your chats. Try again.') }}
                </p>
            </template>

            <template x-if="!switcherLoading && !switcherError && filteredSwitcherItems().length === 0">
                <p class="px-3 py-3 text-xs text-gray-500 dark:text-gray-400" role="status">
                    <span x-show="switcherQuery.trim()">{{ __('No matches.') }}</span>
                    <span x-show="!switcherQuery.trim()">{{ __("No chats yet. Ask about a deal, a contact, or what's overdue.") }}</span>
                </p>
            </template>

            <div id="chat-switcher-options" role="listbox" aria-label="{{ __('Conversations') }}">
                <template x-for="(item, idx) in filteredSwitcherItems()" :key="item.id">
                    <button
                        type="button"
                        role="option"
                        :id="'chat-switcher-option-' + idx"
                        x-on:click="openSwitcherItem(item)"
                        x-on:mouseenter="switcherActiveIndex = idx"
                        :aria-selected="idx === switcherActiveIndex"
                        class="flex w-full items-center gap-2 px-3 py-2 text-start text-sm text-gray-700 transition focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-500 dark:text-gray-200"
                        :class="idx === switcherActiveIndex ? 'bg-gray-50 dark:bg-white/5' : ''"
                    >
                        <x-heroicon-o-chat-bubble-left class="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
                        <span class="truncate" :title="item.title || @js(__('Untitled chat'))" x-text="item.title || @js(__('Untitled chat'))"></span>
                    </button>
                </template>
            </div>
        </div>
    </div>
</div>
