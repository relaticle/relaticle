<div
    class="relative h-full"
    x-data="{
        open: false,
        place() {
            const gap = 8
            const edge = 16
            const popover = $refs.popover
            const anchor = $refs.plus.getBoundingClientRect()
            const height = document.documentElement.clientHeight
            const width = document.documentElement.clientWidth

            popover.style.setProperty('--menu-room', '100dvh')

            const below = height - anchor.bottom - gap - edge
            const above = anchor.top - gap - edge
            const flip = below < popover.offsetHeight && above > below

            popover.style.setProperty('--menu-room', `${Math.max(flip ? above : below, 0)}px`)
            popover.style.top = flip ? 'auto' : `${anchor.bottom + gap}px`
            popover.style.bottom = flip ? `${height - anchor.top + gap}px` : 'auto'
            popover.style.left = `${Math.max(edge, Math.min(anchor.left, width - popover.offsetWidth - edge))}px`
        },
    }"
    x-on:resize.window="open && place()"
    x-on:scroll.window.capture="open && place()"
    x-on:keydown.escape.stop="open = false; $refs.trigger.focus()"
    x-on:click.outside="open = false"
>
    <button
        type="button"
        x-ref="trigger"
        x-on:click="open = ! open; if (open) $nextTick(() => { place(); $refs.search.focus({ preventScroll: true }) })"
        aria-haspopup="true"
        x-bind:aria-expanded="open"
        class="flex h-full min-h-40 w-full flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-gray-300 p-4 text-sm font-medium text-gray-600 outline-none transition hover:border-gray-400 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-primary-500 dark:border-white/20 dark:text-gray-300 dark:hover:border-white/30 dark:hover:bg-white/5"
        data-merge-picker
    >
        <span x-ref="plus" class="flex size-9 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-300">
            <x-filament::icon icon="lucide-plus" class="size-5" />
        </span>
        {{ __('filament/record-page.merge.pick') }}
    </button>

    <div
        x-ref="popover"
        x-show="open"
        x-cloak
        class="fixed z-50 flex max-h-[min(var(--spacing-menu-max),var(--menu-room,100dvh))] w-[min(22rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-menu bg-white shadow-lg ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10"
        data-merge-popover
    >
        <div class="flex shrink-0 items-center gap-2 border-b border-gray-950/5 px-3 dark:border-white/10">
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
            class="min-h-0 max-h-[calc(var(--spacing-menu-row)*6+0.5rem)] overflow-y-auto p-1"
            x-on:keydown.arrow-down.prevent="$focus.wrap().next()"
            x-on:keydown.arrow-up.prevent="$focus.wrap().previous()"
        >
            @forelse ($results as $result)
                <li wire:key="merge-result-{{ $result['id'] }}" class="rounded-menu-item">
                    <button
                        type="button"
                        x-on:click="open = false; $wire.set(@js($statePath.'.search'), '', false); $wire.set(@js($statePath.'.duplicate_id'), @js($result['id']))"
                        class="flex min-h-menu-row w-full items-center gap-2 rounded-menu-item px-2 py-1.5 text-start text-sm text-gray-950 outline-none transition hover:bg-gray-50 focus-visible:bg-gray-50 dark:text-white dark:hover:bg-white/5 dark:focus-visible:bg-white/5"
                    >
                        @if ($result['logo'] ?? null)
                            <img src="{{ $result['logo'] }}" alt="" class="size-5 shrink-0 rounded bg-white object-contain ring-1 ring-gray-950/10 dark:ring-white/15" />
                        @else
                            <span class="flex size-5 shrink-0 items-center justify-center rounded bg-gray-100 text-[0.625rem] font-semibold text-gray-600 ring-1 ring-gray-950/5 dark:bg-white/10 dark:text-gray-300 dark:ring-white/10" aria-hidden="true">{{ $result['initials'] ?? str($result['name'])->squish()->explode(' ')->take(2)->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))->implode('') }}</span>
                        @endif

                        <span class="truncate font-medium">{{ $result['name'] }}</span>

                        @if ($result['detail'])
                            <span class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $result['detail'] }}</span>
                        @endif
                    </button>
                </li>
            @empty
                <li class="px-3 py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('filament/record-page.merge.no_results') }}</li>
            @endforelse
        </ul>
    </div>
</div>
