@use('Illuminate\Support\Js')
@use('Relaticle\Flowforge\Support\ColorResolver')
@php use function Filament\Support\get_color_css_variables; @endphp

<x-filament::dropdown :menu="false" placement="bottom-start" width="xs" shift size class="fi-view-settings">
    <x-slot name="trigger">
        <x-filament::button color="gray" size="sm" class="fi-view-settings-trigger">
            <x-filament::icon icon="lucide-sliders-horizontal" class="fi-view-settings-icon" />
            <span class="fi-view-settings-text">{{ __('filament/panel.tables.views.settings') }}</span>
            <x-filament::icon icon="lucide-chevron-down" class="fi-view-switcher-chevron" />
        </x-filament::button>
    </x-slot>

    <div
        class="fi-ta-views-panel"
        data-view-settings
        data-board-view-settings
        role="group"
        aria-label="{{ __('filament/pages/boards.view_settings.panel') }}"
        x-on:dropdown-escape="rowMenuShown() ? ($event.preventDefault(), closeRowMenu(), escapeRowMenu()) : panel !== 'main' && ($event.preventDefault(), panel = 'main')"
        x-data="{
            panel: 'main',
            rowStage: null,
            rowTrigger: null,
            rows() {
                return [...this.$refs.list.querySelectorAll('[data-stage-id]')]
            },
            ids() {
                return this.rows().map((row) => row.dataset.stageId)
            },
            reorder() {
                return this.$wire.reorderBoardStages(this.ids())
            },
            move(id, step) {
                const rows = this.rows()
                const index = rows.findIndex((row) => row.dataset.stageId === id)
                const target = rows[index + step]

                if (! target) {
                    return
                }

                step < 0 ? target.before(rows[index]) : target.after(rows[index])
                const saved = this.reorder()
                const focusRow = () => rows[index].querySelector('[data-stage-actions]')?.focus()

                this.closeRowMenu()
                this.$nextTick(focusRow)
                saved.then(focusRow, focusRow)
            },
            closeRowMenu() {
                window.Alpine.$data(this.$refs.rowMenu.querySelector('.fi-dropdown')).close()
            },
            rowMenuShown() {
                return !! document.querySelector('.fi-board-stages-row-menu')?._x_isShown
            },
            escapeRowMenu() {
                this.$nextTick(() => requestAnimationFrame(() => this.rowTrigger?.focus()))
            },
            openRowMenu(trigger, event, stage) {
                this.rowTrigger = trigger

                const panel = document.querySelector('.fi-board-stages-row-menu')

                if (panel._x_isShown && panel.trigger === trigger) {
                    return
                }

                const ids = this.ids()

                this.rowStage = { ...stage, isFirst: ids[0] === stage.id, isLast: ids.at(-1) === stage.id }

                setTimeout(() => {
                    panel.open(trigger)

                    if (event.detail === 0) {
                        this.$nextTick(() => [...panel.querySelectorAll('.fi-dropdown-list-item')].find((item) => item.offsetParent !== null && ! item.disabled)?.focus())
                    }
                })
            },
        }"
    >
        <div class="fi-view-settings-view" x-show="panel === 'main'">
            <div class="fi-dropdown-list">
                <x-filament::dropdown.list.item
                    icon="lucide-columns-3"
                    role="button"
                    tabindex="0"
                    data-board-column-field-trigger
                    x-bind:aria-expanded="(panel === 'grouping').toString()"
                    x-on:click="panel = 'grouping'"
                >
                    <span class="fi-view-settings-add">
                        {{ __('filament/pages/boards.view_settings.grouped_by') }}
                        <span class="fi-board-settings-value">
                            <span class="fi-board-settings-value-text">{{ $columnFieldName }}</span>
                            <x-filament::icon icon="lucide-chevron-right" class="fi-view-settings-add-chevron" />
                        </span>
                    </span>
                </x-filament::dropdown.list.item>

                <x-filament::dropdown.list.item
                    icon="lucide-rows-3"
                    role="button"
                    tabindex="0"
                    data-board-lanes-trigger
                    x-bind:aria-expanded="(panel === 'lanes').toString()"
                    x-on:click="panel = 'lanes'"
                >
                    <span class="fi-view-settings-add">
                        {{ __('filament/pages/boards.view_settings.lanes') }}
                        <span class="fi-board-settings-value">
                            <span class="fi-board-settings-value-text">{{ collect($groupings)->firstWhere('id', $grouping)['label'] ?? __('filament/pages/boards.view_settings.no_lanes_short') }}</span>
                            <x-filament::icon icon="lucide-chevron-right" class="fi-view-settings-add-chevron" />
                        </span>
                    </span>
                </x-filament::dropdown.list.item>
            </div>

            <p class="fi-board-stages-heading">{{ $heading }}</p>

            <div class="fi-ta-views-scroll">
                <div class="fi-ta-views-list" x-ref="list" x-sort="reorder" x-sort:config="{ draggable: '.fi-ta-views-draggable', filter: '.fi-ta-views-row-actions', preventOnFilter: false, delay: 200, delayOnTouchOnly: true, animation: 150 }">
                    @foreach ($stages as $stage)
                        @php $dotColor = ColorResolver::resolve($stage['color']); @endphp

                        <div
                            class="fi-ta-views-row fi-ta-views-draggable"
                            x-sort:item="{{ Js::from((string) $stage['id']) }}"
                            data-stage-id="{{ $stage['id'] }}"
                            wire:key="board-stage.{{ $stage['id'] }}"
                        >
                            <span class="fi-board-stage">
                                <span class="fi-board-stage-mark">
                                    @if ($dotColor)
                                        <span class="fi-board-stage-dot" style="{{ get_color_css_variables($dotColor, shades: [500]) }}"></span>
                                    @endif
                                </span>
                                <span class="fi-board-stage-name" title="{{ $stage['name'] }}">{{ $stage['name'] }}</span>
                            </span>

                            <div class="fi-ta-views-row-actions">
                                <x-filament::icon-button
                                    icon="lucide-ellipsis-vertical"
                                    color="gray"
                                    size="sm"
                                    :label="__('filament/pages/boards.view_settings.stage_actions', ['stage' => $stage['name']])"
                                    data-stage-actions
                                    x-on:click="openRowMenu($el, $event, {{ Js::from(['id' => (string) $stage['id']]) }})"
                                />
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="fi-view-settings-view" x-show="panel === 'grouping'" x-cloak data-board-panel="grouping">
            <div class="fi-view-settings-search">
                <x-filament::icon-button
                    icon="lucide-chevron-left"
                    color="gray"
                    size="sm"
                    :label="__('filament/panel.tables.views.back')"
                    data-view-settings-back
                    x-on:click="panel = 'main'"
                />
                <p class="fi-board-panel-title">{{ __('filament/pages/boards.view_settings.grouped_by_heading') }}</p>
            </div>

            <div class="fi-view-settings-scroll">
                <div class="fi-dropdown-list" role="menu" aria-label="{{ __('filament/pages/boards.view_settings.grouped_by_heading') }}">
                    @foreach ($columnFields as $candidate)
                        <button
                            type="button"
                            data-board-column-field="{{ $candidate['code'] }}"
                            role="menuitemradio"
                            aria-checked="{{ $columnField === $candidate['code'] ? 'true' : 'false' }}"
                            class="fi-dropdown-list-item"
                            wire:key="board-column-field.{{ $candidate['code'] }}"
                            x-on:click="$wire.setBoardColumnField({{ Js::from($candidate['code']) }})"
                        >
                            <svg class="size-menu-icon shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $candidate['icon'] }}"></path></svg>
                            <span class="fi-dropdown-list-item-label">{{ $candidate['name'] }}</span>
                            @if ($columnField === $candidate['code'])
                                <x-filament::icon icon="lucide-check" class="fi-menu-check" />
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="fi-view-settings-view" x-show="panel === 'lanes'" x-cloak data-board-panel="lanes">
            <div class="fi-view-settings-search">
                <x-filament::icon-button
                    icon="lucide-chevron-left"
                    color="gray"
                    size="sm"
                    :label="__('filament/panel.tables.views.back')"
                    data-view-settings-back
                    x-on:click="panel = 'main'"
                />
                <p class="fi-board-panel-title" id="board-lanes-heading">{{ __('filament/pages/boards.view_settings.lanes') }}</p>
            </div>

            <div class="fi-view-settings-scroll">
                <div class="fi-dropdown-list" data-board-lanes role="radiogroup" aria-labelledby="board-lanes-heading">
                    <button
                        type="button"
                        role="radio"
                        aria-checked="{{ $grouping === null ? 'true' : 'false' }}"
                        class="fi-dropdown-list-item"
                        data-board-grouping="none"
                        x-on:click="$wire.setBoardGrouping(null)"
                    >
                        <x-filament::icon icon="lucide-ban" />
                        <span class="fi-dropdown-list-item-label">{{ __('filament/pages/boards.view_settings.no_lanes') }}</span>
                        @if ($grouping === null)
                            <x-filament::icon icon="lucide-check" class="fi-menu-check" />
                        @endif
                    </button>

                    @foreach ($groupings as $group)
                        <button
                            type="button"
                            role="radio"
                            aria-checked="{{ $grouping === $group['id'] ? 'true' : 'false' }}"
                            class="fi-dropdown-list-item"
                            data-board-grouping="{{ $group['id'] }}"
                            wire:key="board-grouping.{{ $group['id'] }}"
                            x-on:click="$wire.setBoardGrouping({{ Js::from($group['id']) }})"
                        >
                            @if ($group['icon'])
                                <svg class="size-menu-icon shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $group['icon'] }}"></path></svg>
                            @endif
                            <span class="fi-dropdown-list-item-label">{{ $group['label'] }}</span>
                            @if ($grouping === $group['id'])
                                <x-filament::icon icon="lucide-check" class="fi-menu-check" />
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        <div x-ref="rowMenu">
            <x-filament::dropdown placement="right-start" :offset="16" width="fi-board-stages-row-menu" shift teleport>
                <x-slot name="trigger">
                    <span hidden></span>
                </x-slot>

                <x-filament::dropdown.list>
                    <x-filament::dropdown.list.item icon="lucide-arrow-up" x-bind:disabled="!! rowStage?.isFirst" x-on:click.stop="move(rowStage.id, -1)">
                        {{ __('filament/panel.tables.views.move_up') }}
                    </x-filament::dropdown.list.item>

                    <x-filament::dropdown.list.item icon="lucide-arrow-down" x-bind:disabled="!! rowStage?.isLast" x-on:click.stop="move(rowStage.id, 1)">
                        {{ __('filament/panel.tables.views.move_down') }}
                    </x-filament::dropdown.list.item>
                </x-filament::dropdown.list>
            </x-filament::dropdown>
        </div>
    </div>
</x-filament::dropdown>
