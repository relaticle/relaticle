@use('Illuminate\Support\Js')

<x-filament::dropdown
    placement="bottom-start"
    width="xs"
    shift
    size
    class="fi-view-switcher"
>
    <x-slot name="trigger">
        <x-filament::button
            color="gray"
            size="sm"
            class="fi-view-switcher-trigger"
            :aria-label="__('filament/panel.tables.views.switch', ['name' => $activeName])"
            :data-active-view="$activeName"
        >
            <span class="fi-view-tile fi-view-tile-{{ $activeTone }}"><x-filament::icon :icon="$activeIcon" /></span>
            <span class="fi-view-switcher-label" title="{{ $activeName }}">{{ $activeName }}</span>
            <x-filament::icon icon="lucide-chevron-down" class="fi-view-switcher-chevron" />
        </x-filament::button>
    </x-slot>

    <div
        class="fi-ta-views-panel"
        x-on:keydown.escape.window="focusTriggerOnEscape()"
        x-init="$el.closest('.fi-dropdown-panel')?.addEventListener('dropdown-opened', () => { query = ''; window.matchMedia('(width >= 40rem) and (pointer: fine)').matches && $refs.search.focus() })"
        x-data="{
            query: '',
            rowView: null,
            rows() {
                return [...this.$refs.list.querySelectorAll('[data-view-key]')]
            },
            keys() {
                return this.rows().map((row) => row.dataset.viewKey)
            },
            matches(row) {
                return ! this.query || row.dataset.viewName.includes(this.query.toLowerCase())
            },
            hasMatch() {
                return this.rows().some((row) => this.matches(row))
            },
            focusTriggerOnEscape() {
                const panel = this.$el.closest('.fi-dropdown-panel')

                if (panel.offsetParent !== null) {
                    this.$nextTick(() => panel.closest('.fi-dropdown').querySelector('.fi-dropdown-trigger button').focus())
                }
            },
            reorder() {
                this.$wire.reorderViews(this.keys())
            },
            move(key, step) {
                const rows = this.rows()
                const index = rows.findIndex((row) => row.dataset.viewKey === key)
                const target = rows[index + step]

                if (! target) {
                    return
                }

                step < 0 ? target.before(rows[index]) : target.after(rows[index])
                this.reorder()
                this.closeRowMenu()
                this.$nextTick(() => rows[index].querySelector('[data-view-actions]')?.focus())
            },
            closeRowMenu() {
                window.Alpine.$data(this.$refs.rowMenu.querySelector('.fi-dropdown')).close()
            },
            openRowMenu(trigger, event, view) {
                const panel = document.querySelector('.fi-ta-views-row-menu')

                if (panel._x_isShown && panel.trigger === trigger) {
                    return
                }

                const keys = this.keys()

                this.rowView = { ...view, isFirst: keys[0] === view.key, isLast: keys.at(-1) === view.key }

                setTimeout(() => {
                    panel.open(trigger)

                    if (event.detail === 0) {
                        this.$nextTick(() => [...panel.querySelectorAll('.fi-dropdown-list-item')].find((item) => item.offsetParent !== null && ! item.disabled)?.focus())
                    }
                })
            },
        }"
    >
        <label class="fi-ta-views-search">
            <x-filament::icon icon="lucide-search" />
            <input type="search" x-ref="search" x-model="query" x-on:search="query = $el.value" x-on:keydown.stop placeholder="{{ __('filament/panel.tables.views.search') }}" aria-label="{{ __('filament/panel.tables.views.search') }}" />
        </label>

        <div class="fi-ta-views-scroll">
            <div class="fi-ta-views-list" x-ref="list" x-sort="reorder" x-sort:config="{ draggable: '.fi-ta-views-draggable', filter: '.fi-ta-views-row-actions', preventOnFilter: false, delay: 200, delayOnTouchOnly: true, animation: 150 }">
                @foreach ($entries as $entry)
                    <div
                        class="fi-ta-views-row"
                        x-sort:item="{{ Js::from($entry['key']) }}"
                        data-view-key="{{ $entry['key'] }}"
                        data-view-kind="{{ $entry['kind'] }}"
                        data-view-name="{{ mb_strtolower($entry['label']) }}"
                        x-show="matches($el)"
                        x-bind:class="! query && 'fi-ta-views-draggable'"
                        wire:key="view-switcher.{{ $entry['key'] }}"
                    >
                        @if ($entry['action'])
                            <x-filament::dropdown.list.item
                                color="gray"
                                :aria-current="$entry['active'] ? 'true' : null"
                                class="min-w-0 flex-1"
                                wire:click="{{ $entry['action'] }}"
                            >
                                @include('filament.app.view-switcher-entry', ['entry' => $entry])
                            </x-filament::dropdown.list.item>
                        @else
                            <x-filament::dropdown.list.item
                                tag="a"
                                :href="$entry['url']"
                                :spa-mode="true"
                                color="gray"
                                :aria-current="$entry['active'] ? 'page' : null"
                                class="min-w-0 flex-1"
                            >
                                @include('filament.app.view-switcher-entry', ['entry' => $entry])
                            </x-filament::dropdown.list.item>
                        @endif

                        <div class="fi-ta-views-row-actions">
                            <x-filament::icon-button
                                icon="lucide-ellipsis-vertical"
                                color="gray"
                                size="sm"
                                :label="__('filament/panel.tables.views.actions', ['name' => $entry['label']])"
                                data-view-actions
                                x-on:click="openRowMenu($el, $event, {{ Js::from(['key' => $entry['key'], 'isView' => $canApply && $entry['kind'] === 'view', 'createdLabel' => $entry['createdLabel']]) }})"
                            />
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="fi-ta-views-empty" x-show="query && ! hasMatch()" x-cloak>{{ __('filament/panel.tables.views.no_match') }}</p>
        </div>

        <div x-ref="rowMenu">
            <x-filament::dropdown placement="right-start" :offset="16" width="fi-ta-views-row-menu" shift teleport>
                <x-slot name="trigger">
                    <span hidden></span>
                </x-slot>

                <x-filament::dropdown.list>
                    <x-filament::dropdown.list.item icon="lucide-arrow-up" x-bind:disabled="!! (rowView?.isFirst || query)" x-on:click.stop="move(rowView.key, -1)">
                        {{ __('filament/panel.tables.views.move_up') }}
                    </x-filament::dropdown.list.item>

                    <x-filament::dropdown.list.item icon="lucide-arrow-down" x-bind:disabled="!! (rowView?.isLast || query)" x-on:click.stop="move(rowView.key, 1)">
                        {{ __('filament/panel.tables.views.move_down') }}
                    </x-filament::dropdown.list.item>
                </x-filament::dropdown.list>

                @if ($canApply)
                    <x-filament::dropdown.list x-show="rowView?.isView">
                        <x-filament::dropdown.list.item icon="lucide-pencil" x-on:click="$wire.mountAction('renameSavedView', { view: rowView.key })">
                            {{ __('filament/panel.tables.views.rename') }}
                        </x-filament::dropdown.list.item>

                        <x-filament::dropdown.list.item icon="lucide-copy" x-on:click="$wire.duplicateSavedView(rowView.key)">
                            {{ __('filament/panel.tables.views.duplicate') }}
                        </x-filament::dropdown.list.item>
                    </x-filament::dropdown.list>

                    <x-filament::dropdown.list x-show="rowView?.isView">
                        <x-filament::dropdown.list.item icon="lucide-trash-2" color="danger" x-on:click="$wire.mountAction('deleteSavedView', { view: rowView.key })">
                            {{ __('filament/panel.tables.views.delete') }}
                        </x-filament::dropdown.list.item>
                    </x-filament::dropdown.list>

                    <p class="fi-ta-views-row-created" x-show="rowView?.isView" x-text="rowView?.createdLabel"></p>
                @endif
            </x-filament::dropdown>
        </div>

        @if ($canApply)
            <x-filament::dropdown.list>
                <x-filament::dropdown.list.item icon="lucide-plus" class="fi-ta-views-save-current" wire:click="mountAction('saveSavedViewAs')">
                    {{ __('filament/panel.tables.views.save_current') }}
                </x-filament::dropdown.list.item>
            </x-filament::dropdown.list>
        @endif
    </div>
</x-filament::dropdown>
