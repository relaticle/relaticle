<div
    class="relative flex h-full min-h-56 items-center justify-center rounded-xl border border-dashed border-gray-300 dark:border-white/20"
    x-data="{
        open: false,
        active: 0,
        query: '',
        results: @js($searchRecords()),
        request: 0,
        async search() {
            const request = ++this.request
            const results = await $callSchemaComponentMethod('searchRecords', { search: this.query })

            if (request === this.request) {
                this.results = results
                this.active = 0
            }
        },
        options() {
            return [...$refs.popover.querySelectorAll('[role=option]')]
        },
        move(step) {
            const count = this.options().length

            if (count === 0) {
                return
            }

            this.active = (this.active + step + count) % count
            this.$nextTick(() => this.options()[this.active]?.scrollIntoView({ block: 'nearest' }))
        },
        pickActive() {
            const option = this.options()[this.active] ?? this.options()[0]

            if (option) {
                this.choose(option.dataset.id)
            }
        },
        async choose(id) {
            this.open = false

            await $wire.set(@js($getStatePath()), id)

            document.querySelector('[data-merge-dialog] [data-merge-clear]')?.focus()
        },
        show() {
            this.active = 0
            this.place()
            this.open = true
            this.$nextTick(() => this.$refs.search.focus({ preventScroll: true }))
        },
        place() {
            const gap = 8
            const edge = 16
            const popover = $refs.popover
            const anchor = $refs.trigger.getBoundingClientRect()
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
    x-on:keydown.escape="if (open) { $event.stopPropagation(); open = false; $refs.trigger.focus() }"
    x-on:keydown.tab="open = false"
    x-on:click.outside="open = false"
>
    <button
        type="button"
        x-ref="trigger"
        x-on:click="open ? open = false : show()"
        aria-haspopup="listbox"
        x-bind:aria-expanded="open"
        aria-label="{{ __('filament/record-page.merge.pick') }}"
        title="{{ __('filament/record-page.merge.pick') }}"
        class="flex size-9 items-center justify-center rounded-lg bg-white text-gray-600 shadow-xs ring-1 ring-gray-950/10 outline-none transition hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-primary-500 dark:bg-gray-800 dark:text-gray-200 dark:ring-white/15 dark:hover:bg-gray-700"
        data-merge-picker
    >
        <x-filament::icon icon="lucide-plus" class="size-menu-icon" />
    </button>

    {{-- Not x-float: it caps the list to the modal's height, and the list must float past the modal edge. --}}
    <div
        x-ref="popover"
        style="visibility: hidden"
        x-bind:style="{ visibility: open ? 'visible' : 'hidden' }"
        wire:ignore
        class="fi-merge-pop fixed z-50 flex max-h-[min(var(--spacing-menu-max),var(--menu-room,100dvh))] w-[min(22rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-menu bg-white shadow-lg ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10"
    >
        <div class="fi-merge-search gap-2 px-3">
            <x-filament::icon icon="lucide-search" />
            <input
                type="search"
                x-ref="search"
                x-model="query"
                x-on:input="active = 0"
                x-on:input.debounce.250ms="search()"
                x-on:keydown.arrow-down.prevent="move(1)"
                x-on:keydown.arrow-up.prevent="move(-1)"
                x-on:keydown.enter.prevent="pickActive()"
                role="combobox"
                aria-expanded="true"
                x-bind:aria-controls="results.length === 0 ? null : 'merge-listbox'"
                aria-autocomplete="list"
                x-bind:aria-activedescendant="results.length === 0 ? null : 'merge-option-' + active"
                placeholder="{{ $getSearchPlaceholder() }}"
                aria-label="{{ __('filament/record-page.merge.search') }}"
                autocomplete="off"
            />
        </div>

        <p x-show="results.length === 0" class="fi-menu-empty">{{ __('filament/record-page.merge.no_results') }}</p>

        <ul
            x-show="results.length > 0"
            id="merge-listbox"
            role="listbox"
            aria-label="{{ __('filament/record-page.merge.search') }}"
            class="min-h-0 max-h-[calc(var(--spacing-menu-row)*6+0.5rem)] overflow-y-auto p-1"
        >
            <template x-for="(result, index) in results" x-bind:key="result.id">
                <li
                    x-bind:id="'merge-option-' + index"
                    role="option"
                    x-bind:data-id="result.id"
                    x-bind:aria-selected="active === index"
                    x-bind:class="active === index && 'bg-gray-50 dark:bg-white/5'"
                    x-on:mouseenter="active = index"
                    x-on:click="choose(result.id)"
                    class="flex min-h-menu-row cursor-pointer items-center gap-2 rounded-menu-item px-2 py-1.5 text-sm text-gray-950 dark:text-white"
                >
                    <span
                        x-data="{ failed: false }"
                        class="flex size-5 shrink-0 items-center justify-center overflow-hidden rounded bg-gray-100 text-gray-500 ring-1 ring-gray-950/5 dark:bg-white/10 dark:text-gray-400 dark:ring-white/10"
                        aria-hidden="true"
                    >
                        <template x-if="result.logo">
                            <img x-bind:src="result.logo" alt="" x-show="! failed" x-on:error="failed = true" class="size-full bg-white object-contain" />
                        </template>

                        <x-filament::icon :icon="$getEntityIcon()" x-show="! result.logo || failed" class="size-menu-icon shrink-0" />
                    </span>

                    <span class="truncate font-medium" x-text="result.name"></span>

                    <span x-show="result.detail" class="truncate text-xs text-gray-500 dark:text-gray-400" x-text="result.detail"></span>
                </li>
            </template>
        </ul>
    </div>
</div>
