{{-- In-conversation search overlay: Cmd+F / Ctrl+F (see onChatRootKeydown() in
     transcript.js). Deliberately identical in shape and interaction to
     _switcher.blade.php (same panel chrome, same Esc kbd, same listbox
     semantics, arrows + Enter) so the two overlays read as one pattern. Also
     a normal, non-teleported descendant of the chat root for the same reason:
     keydowns inside it must keep bubbling to the root's x-on:keydown. --}}
<div
    x-show="searchOpen"
    x-cloak
    x-on:click.self="closeMessageSearch()"
    class="fixed inset-0 z-[60] flex items-start justify-center bg-gray-500/40 px-4 pt-[15vh] backdrop-blur-sm dark:bg-gray-950/60"
    role="dialog"
    aria-modal="true"
    aria-label="{{ __('Search this conversation') }}"
    x-transition:enter="transition ease-out duration-150"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition ease-in duration-100"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
>
    <div class="w-full max-w-lg overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900">
        <div class="flex items-center gap-2 border-b border-gray-200 px-3 py-2.5 dark:border-gray-700">
            <x-heroicon-o-magnifying-glass class="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
            <input
                x-ref="messageSearchInput"
                x-model="searchQuery"
                x-on:input="onMessageSearchInput()"
                type="search"
                placeholder="{{ __('Search this conversation...') }}"
                aria-label="{{ __('Search this conversation') }}"
                class="w-full border-0 bg-transparent p-0 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-0 dark:text-white"
            />
            <kbd class="hidden shrink-0 rounded border border-gray-200 px-1.5 py-0.5 font-sans text-[length:var(--text-pico)] font-medium text-gray-400 sm:inline dark:border-gray-600">Esc</kbd>
        </div>

        <div class="max-h-80 overflow-y-auto py-1" role="listbox" aria-label="{{ __('Matching messages') }}">
            {{-- Dead end for the hit the user picked: either it is gone, or the
                 history walk that was fetching it never came back. Either way
                 the remaining rows below stay listed and clickable. --}}
            <template x-if="messageSearchNotice()">
                <p class="px-3 py-3 text-xs text-gray-500 dark:text-gray-400" role="status" x-text="messageSearchNotice()"></p>
            </template>

            <template x-if="!messageSearchNotice() && searchLoading">
                <p class="px-3 py-3 text-xs text-gray-500 dark:text-gray-400" role="status">{{ __('Loading…') }}</p>
            </template>

            <template x-if="!messageSearchNotice() && !searchLoading && searchError">
                <p class="px-3 py-3 text-xs text-danger-600 dark:text-danger-400" role="status">
                    {{ __('Could not search this conversation. Try again.') }}
                </p>
            </template>

            <template x-if="!messageSearchNotice() && !searchLoading && !searchError && searchResults.length === 0">
                <p class="px-3 py-3 text-xs text-gray-500 dark:text-gray-400" role="status">
                    <span x-show="searchQuery.trim().length >= 2">{{ __('No matches.') }}</span>
                    <span x-show="searchQuery.trim().length < 2">{{ __('Type at least two characters to search this conversation.') }}</span>
                </p>
            </template>

            <template x-for="(match, idx) in searchResults" :key="match.message_id">
                <button
                    type="button"
                    role="option"
                    x-on:click="jumpToMessage(match.message_id)"
                    x-on:mouseenter="searchActiveIndex = idx"
                    :aria-selected="idx === searchActiveIndex"
                    class="flex w-full items-start gap-2 px-3 py-2 text-start text-sm text-gray-700 transition dark:text-gray-200"
                    :class="idx === searchActiveIndex ? 'bg-gray-50 dark:bg-white/5' : ''"
                >
                    <x-heroicon-o-chat-bubble-left class="mt-0.5 h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
                    <span class="line-clamp-2 [overflow-wrap:anywhere]" x-text="match.snippet"></span>
                </button>
            </template>
        </div>
    </div>
</div>

{{-- Shown while the jump walks earlier pages: the overlay is already gone by
     then, so without this a long history load looks like a dead click. --}}
<div
    x-show="searchJumping"
    x-cloak
    class="pointer-events-none fixed inset-x-0 top-4 z-[60] flex justify-center"
    role="status"
    aria-live="polite"
>
    <span class="inline-flex items-center gap-2 rounded-full border border-[var(--surface-card-border)] bg-[var(--surface-card-bg)] px-3 py-1.5 text-xs font-medium text-gray-700 shadow-lg backdrop-blur-sm dark:text-gray-200">
        <x-heroicon-o-arrow-path class="h-3.5 w-3.5 motion-safe:animate-spin" aria-hidden="true" />
        {{ __('Loading earlier messages…') }}
    </span>
</div>
