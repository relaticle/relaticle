{{--
    Driven by the shell's Alpine state (searchOpen, query, records, results),
    so the header button, the mobile icon, and the hub's search field all open
    the same dialog.
--}}
<nav x-cloak x-show="searchOpen" class="fixed inset-0 z-[60]" aria-label="{{ __('Search the docs') }}">
    <div x-show="searchOpen"
         x-transition.opacity.duration.150ms
         class="fixed inset-0 bg-gray-950/40 backdrop-blur-[2px] dark:bg-black/60"
         x-on:click="closeSearch()"></div>

    <div x-show="searchOpen"
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="scale-[0.98] opacity-0"
         x-transition:enter-end="scale-100 opacity-100"
         class="relative mx-auto mt-[12vh] w-[calc(100%-2rem)] max-w-xl overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl shadow-gray-950/10 dark:border-white/[0.08] dark:bg-gray-900 dark:shadow-black/40">
        <div class="flex items-center gap-3 border-b border-gray-200/70 px-4 dark:border-white/[0.06]">
            <x-ri-search-line class="h-4 w-4 shrink-0 text-gray-400" />
            <input x-ref="searchInput"
                   x-model="query"
                   x-on:input="active = 0"
                   x-on:keydown.arrow-down.prevent="move(1)"
                   x-on:keydown.arrow-up.prevent="move(-1)"
                   x-on:keydown.enter.prevent="go()"
                   type="text"
                   role="combobox"
                   aria-controls="docs-search-results"
                   :aria-expanded="results.length > 0"
                   :aria-activedescendant="results.length > 0 ? 'docs-search-result-' + active : null"
                   placeholder="{{ __('Search help articles and guides') }}"
                   aria-label="{{ __('Search the docs') }}"
                   autocomplete="off"
                   class="w-full border-0 bg-transparent py-3.5 text-sm text-gray-900 placeholder-gray-400 focus:ring-0 focus:outline-none dark:text-white dark:placeholder-gray-500">
            <kbd class="shrink-0 rounded bg-gray-100 px-1.5 py-0.5 text-pico font-medium text-gray-500 dark:bg-white/10 dark:text-gray-400">esc</kbd>
        </div>

        <span class="sr-only" aria-live="polite"
              x-text="query.trim() === '' || records === null ? '' : results.length + ' {{ __('results') }}'"></span>

        <ul x-ref="searchResults"
            id="docs-search-results"
            x-show="results.length > 0"
            role="listbox"
            class="docs-scroll max-h-[22rem] overflow-y-auto p-2">
            <template x-for="(result, index) in results" :key="result.id">
                <li role="option" :id="'docs-search-result-' + index" :aria-selected="index === active">
                    <a :href="result.url"
                       x-on:mouseenter="active = index"
                       :class="index === active ? 'bg-primary-50 dark:bg-primary-500/10' : ''"
                       class="block rounded-lg px-3 py-2.5 transition-colors">
                        <span class="block text-pico font-semibold tracking-[0.06em] text-gray-400 uppercase dark:text-gray-500">
                            <span x-text="result.crumb"></span>
                            <template x-if="result.section">
                                <span> › <span x-text="result.title"></span></span>
                            </template>
                        </span>
                        <span class="mt-0.5 block text-[13px] font-medium text-gray-900 dark:text-white"
                              x-text="result.section || result.title"></span>
                        <span class="mt-0.5 block truncate text-xs text-gray-500 dark:text-gray-400">
                            <template x-for="(segment, part) in result.snippet" :key="part">
                                <span x-text="segment.text" :class="segment.mark && 'docs-search-mark'"></span>
                            </template>
                        </span>
                    </a>
                </li>
            </template>
        </ul>

        <p x-show="records === null" class="px-4 py-10 text-center text-[13px] text-gray-500 dark:text-gray-400">
            {{ __('Loading the index…') }}
        </p>

        <p x-show="records !== null && query.trim() !== '' && results.length === 0" class="px-4 py-10 text-center text-[13px] text-gray-500 dark:text-gray-400">
            {{ __('Nothing matched that.') }}
            <a href="{{ route('discord') }}" target="_blank" rel="noopener noreferrer"
               class="font-medium text-primary-600 underline underline-offset-4 dark:text-primary-400">{{ __('Ask in Discord') }}</a>
        </p>

        <div class="flex items-center gap-4 border-t border-gray-200/70 px-4 py-2.5 text-pico text-gray-400 dark:border-white/[0.06] dark:text-gray-500">
            <span class="flex items-center gap-1">
                <kbd class="rounded bg-gray-100 px-1 py-0.5 font-medium dark:bg-white/10">↑</kbd>
                <kbd class="rounded bg-gray-100 px-1 py-0.5 font-medium dark:bg-white/10">↓</kbd>
                {{ __('to navigate') }}
            </span>
            <span class="flex items-center gap-1">
                <kbd class="rounded bg-gray-100 px-1 py-0.5 font-medium dark:bg-white/10">↵</kbd>
                {{ __('to open') }}
            </span>
            <span class="ml-auto hidden sm:block">{{ __('Searching help and developer docs') }}</span>
        </div>
    </div>
</nav>
