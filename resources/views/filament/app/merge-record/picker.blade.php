<div
    class="relative h-full"
    x-data="{ open: false }"
    x-on:keydown.escape.stop="open = false; $refs.trigger.focus()"
    x-on:click.outside="open = false"
>
    <button
        type="button"
        x-ref="trigger"
        x-on:click="open = ! open; if (open) $nextTick(() => $refs.search.focus())"
        aria-haspopup="true"
        x-bind:aria-expanded="open"
        class="flex h-full min-h-40 w-full flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-gray-300 p-4 text-sm font-medium text-gray-600 outline-none transition hover:border-gray-400 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-primary-500 dark:border-white/20 dark:text-gray-300 dark:hover:border-white/30 dark:hover:bg-white/5"
        data-merge-picker
    >
        <span class="flex size-9 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-300">
            <x-filament::icon icon="lucide-plus" class="size-5" />
        </span>
        {{ __('filament/record-page.merge.pick') }}
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.150ms
        class="absolute inset-x-0 top-full z-20 mt-2 min-w-64 overflow-hidden rounded-menu bg-white shadow-lg ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10"
        data-merge-popover
    >
        <div class="flex items-center gap-2 border-b border-gray-950/5 px-3 dark:border-white/10">
            <x-filament::icon icon="lucide-search" class="size-menu-icon shrink-0 text-gray-400" />
            <input
                type="search"
                x-ref="search"
                wire:model.live.debounce.250ms="{{ $statePath }}.search"
                x-on:keydown.arrow-down.prevent="$refs.list.querySelector('button')?.focus()"
                x-on:keydown.enter.prevent
                placeholder="{{ __('filament/record-page.merge.search_placeholder') }}"
                aria-label="{{ __('filament/record-page.merge.search') }}"
                autocomplete="off"
                class="h-10 w-full border-0 bg-transparent p-0 text-sm text-gray-950 outline-none ring-0 placeholder:text-gray-400 focus:ring-0 dark:text-white"
            />
        </div>

        <ul
            x-ref="list"
            class="max-h-menu-max overflow-y-auto p-1"
            x-on:keydown.arrow-down.prevent="$focus.wrap().next()"
            x-on:keydown.arrow-up.prevent="$focus.wrap().previous()"
        >
            @forelse ($results as $result)
                <li wire:key="merge-result-{{ $result['id'] }}">
                    <button
                        type="button"
                        x-on:click="open = false; $wire.set(@js($statePath.'.search'), '', false); $wire.set(@js($statePath.'.duplicate_id'), @js($result['id']))"
                        class="flex min-h-menu-row w-full items-center gap-2 rounded-menu-item px-2 py-1.5 text-start text-sm text-gray-950 outline-none transition hover:bg-gray-50 focus-visible:bg-gray-50 dark:text-white dark:hover:bg-white/5 dark:focus-visible:bg-white/5"
                    >
                        <span class="truncate font-medium">{{ $result['name'] }}</span>

                        @if ($result['domain'])
                            <span class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $result['domain'] }}</span>
                        @endif
                    </button>
                </li>
            @empty
                <li class="px-3 py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('filament/record-page.merge.no_results') }}</li>
            @endforelse
        </ul>
    </div>
</div>
