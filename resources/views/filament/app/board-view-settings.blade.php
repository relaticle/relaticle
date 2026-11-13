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
        x-on:dropdown-escape="rowMenuShown() ? ($event.preventDefault(), closeRowMenu(), escapeRowMenu()) : panel !== 'main' && ($event.preventDefault(), closePanel())"
        x-init="$el.closest('.fi-dropdown-panel')?.addEventListener('dropdown-opened', () => (panel = 'main', query = ''))"
        x-data="{
            panel: 'main',
            openPanel(name) {
                this.panel = name
                this.$nextTick(() => requestAnimationFrame(() => {
                    const view = this.$root.querySelector(`[data-board-panel='${name}']`)

                    if (name === 'add') {
                        return this.focusAddStart()
                    }

                    ;(view.querySelector('[aria-checked=true]') ?? view.querySelector('[data-view-settings-back]'))?.focus()
                }))
            },
            closePanel() {
                const name = this.panel

                this.panel = 'main'
                this.query = ''
                this.$nextTick(() => requestAnimationFrame(() => this.$root.querySelector(`[data-panel-trigger='${name}']`)?.focus()))
            },
            syncSwitch(wrapper) {
                const toggle = wrapper.querySelector('[data-visible]')

                if (toggle) {
                    window.Alpine.$data(toggle).state = toggle.dataset.visible === 'true'
                }
            },
            kinds: {
                stage: { list: 'list', row: '[data-stage-id]', key: 'stageId', trigger: '[data-stage-actions]' },
                attribute: { list: 'attributeList', row: '[data-card-attribute]', key: 'cardAttribute', trigger: '[data-attribute-actions]' },
            },
            query: '',
            pending: [],
            revision: 0,
            rowStage: null,
            rowTrigger: null,
            rows(kind) {
                return [...this.$refs[this.kinds[kind].list].querySelectorAll(this.kinds[kind].row)]
            },
            ids(kind) {
                return this.rows(kind).map((row) => row.dataset[this.kinds[kind].key])
            },
            reorder(kind) {
                return kind === 'stage' ? this.$wire.reorderBoardStages(this.ids(kind)) : this.$wire.reorderCardAttributes(this.ids(kind))
            },
            move(kind, id, step) {
                const rows = this.rows(kind)
                const index = rows.findIndex((row) => row.dataset[this.kinds[kind].key] === id)
                const target = rows[index + step]

                if (! target) {
                    return
                }

                step < 0 ? target.before(rows[index]) : target.after(rows[index])
                const saved = this.reorder(kind)
                const focusRow = () => rows[index].querySelector(this.kinds[kind].trigger)?.focus()

                this.closeRowMenu()
                this.$nextTick(focusRow)
                saved.then(focusRow, focusRow)
            },
            remove(id) {
                const rows = this.rows('attribute')
                const index = rows.findIndex((row) => row.dataset.cardAttribute === id)
                const neighbour = rows[index + 1] ?? rows[index - 1]
                const focusNext = () => (neighbour?.isConnected ? neighbour.querySelector(this.kinds.attribute.trigger) : this.$root.querySelector('[data-panel-trigger=add]'))?.focus()
                const saved = this.$wire.removeCardAttribute(id)

                this.closeRowMenu()
                saved.then(() => this.$nextTick(focusNext), focusNext)
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
            openRowMenu(trigger, event, kind, id) {
                this.rowTrigger = trigger

                const panel = document.querySelector('.fi-board-stages-row-menu')

                if (panel._x_isShown && panel.trigger === trigger) {
                    return
                }

                const ids = this.ids(kind)

                this.rowStage = { kind, id, isFirst: ids[0] === id, isLast: ids.at(-1) === id }

                setTimeout(() => {
                    panel.open(trigger)

                    if (event.detail === 0) {
                        this.$nextTick(() => [...panel.querySelectorAll('.fi-dropdown-list-item')].find((item) => item.offsetParent !== null && ! item.disabled)?.focus())
                    }
                })
            },
            matches(text) {
                return text.toLowerCase().includes(this.query.trim().toLowerCase())
            },
            isCandidate(item) {
                this.revision

                return ! this.pending.includes(item.dataset.boardAddItem) && this.matches(item.dataset.text)
            },
            addItems() {
                return [...this.$root.querySelectorAll('[data-board-add-item]')].filter((item) => this.isCandidate(item))
            },
            stepAdd(delta) {
                const items = [...this.addItems(), ...(this.$refs.createRow?.querySelectorAll('.fi-dropdown-list-item') ?? [])]

                if (items.length === 0) {
                    return
                }

                const index = items.indexOf(document.activeElement)
                const next = index === -1 ? (delta > 0 ? 0 : items.length - 1) : index + delta

                if (next < 0) {
                    this.$refs.addSearch.focus()
                } else {
                    items[Math.min(next, items.length - 1)].focus()
                }
            },
            pickFirst() {
                const first = this.pending.length === 0 ? this.addItems()[0] : null

                if (first) {
                    this.add(first.dataset.boardAddItem)
                }
            },
            focusAddStart(root = this.$root) {
                const compact = window.matchMedia('(width < 40rem), (pointer: coarse)').matches

                ;(compact ? root.querySelector('[data-board-panel=add] [data-view-settings-back]') : root.querySelector('[data-view-settings-search]')).focus()
            },
            async add(key) {
                if (this.pending.includes(key)) {
                    return
                }

                const root = this.$root

                this.pending.push(key)

                try {
                    await this.$wire.addCardAttribute(key)
                } finally {
                    this.pending = this.pending.filter((pending) => pending !== key)
                    this.revision++
                }

                this.$nextTick(() => requestAnimationFrame(() => this.focusAddStart(root)))
            },
        }"
    >
        <div class="fi-view-settings-view fi-board-main-view" x-show="panel === 'main'">
            <div class="fi-dropdown-list shrink-0">
                <x-filament::dropdown.list.item
                    icon="lucide-columns-3"
                    role="button"
                    tabindex="0"
                    data-board-column-field-trigger
                    data-panel-trigger="grouping"
                    x-bind:aria-expanded="(panel === 'grouping').toString()"
                    x-on:click="openPanel('grouping')"
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
                    icon="lucide-eye"
                    role="button"
                    tabindex="0"
                    data-board-columns-trigger
                    data-panel-trigger="columns"
                    x-bind:aria-expanded="(panel === 'columns').toString()"
                    x-on:click="openPanel('columns')"
                >
                    <span class="fi-view-settings-add">
                        {{ __('filament/pages/boards.view_settings.visible_columns') }}
                        <span class="fi-board-settings-value">
                            <span class="fi-board-settings-value-text">{{ $visibleCount }}</span>
                            <x-filament::icon icon="lucide-chevron-right" class="fi-view-settings-add-chevron" />
                        </span>
                    </span>
                </x-filament::dropdown.list.item>

                <x-filament::dropdown.list.item
                    icon="lucide-rows-3"
                    role="button"
                    tabindex="0"
                    data-board-lanes-trigger
                    data-panel-trigger="lanes"
                    x-bind:aria-expanded="(panel === 'lanes').toString()"
                    x-on:click="openPanel('lanes')"
                >
                    <span class="fi-view-settings-add">
                        {{ __('filament/pages/boards.view_settings.lanes') }}
                        <span class="fi-board-settings-value">
                            <span class="fi-board-settings-value-text">{{ collect($groupings)->firstWhere('id', $grouping)['label'] ?? __('filament/pages/boards.view_settings.no_lanes_short') }}</span>
                            <x-filament::icon icon="lucide-chevron-right" class="fi-view-settings-add-chevron" />
                        </span>
                    </span>
                </x-filament::dropdown.list.item>

                <div class="fi-board-switch-row" x-on:click="$wire.setCardLabels({{ $showLabels ? 'false' : 'true' }}).finally(() => syncSwitch($el))">
                    <x-filament::icon icon="lucide-tag" />
                    <span class="fi-board-switch-label">{{ __('filament/pages/boards.view_settings.show_labels') }}</span>
                    <span class="fi-board-stage-switch">
                        <x-filament::toggle
                            :state="$showLabels ? 'true' : 'false'"
                            :aria-label="__('filament/pages/boards.view_settings.show_labels')"
                            data-board-show-labels
                            :data-visible="$showLabels ? 'true' : 'false'"
                        />
                    </span>
                </div>

                <div class="fi-board-switch-row" x-on:click="$wire.setHideEmptyAttributes({{ $hideEmpty ? 'false' : 'true' }}).finally(() => syncSwitch($el))">
                    <x-filament::icon icon="lucide-eye" />
                    <span class="fi-board-switch-label">{{ __('filament/pages/boards.view_settings.hide_empty') }}</span>
                    <span class="fi-board-stage-switch">
                        <x-filament::toggle
                            :state="$hideEmpty ? 'true' : 'false'"
                            :aria-label="__('filament/pages/boards.view_settings.hide_empty')"
                            data-board-hide-empty
                            :data-visible="$hideEmpty ? 'true' : 'false'"
                        />
                    </span>
                </div>
            </div>

            <p class="fi-board-section-heading" id="board-card-attributes-heading">{{ __('filament/pages/boards.view_settings.visible_attributes') }}</p>

            <div class="fi-view-settings-scroll fi-board-attributes-scroll">
                <div class="fi-ta-views-list" x-ref="attributeList" role="group" aria-labelledby="board-card-attributes-heading" x-sort="reorder('attribute')" x-sort:config="{ draggable: '.fi-ta-views-draggable', filter: '.fi-ta-views-row-actions', preventOnFilter: false, delay: 200, delayOnTouchOnly: true, animation: 150 }">
                    @foreach ($cardAttributes as $attribute)
                        <div
                            class="fi-ta-views-row fi-ta-views-draggable fi-board-stage-row"
                            x-sort:item="{{ Js::from($attribute['key']) }}"
                            data-card-attribute="{{ $attribute['key'] }}"
                            wire:key="card-attribute.{{ $attribute['key'] }}"
                        >
                            <span class="fi-board-stage-grip" aria-hidden="true">
                                <x-filament::icon icon="lucide-grip-vertical" />
                            </span>

                            <span class="fi-board-stage">
                                <span class="fi-board-stage-mark">
                                    <x-filament::icon :icon="$attribute['icon']" />
                                </span>
                                <span class="fi-board-stage-name" title="{{ $attribute['label'] }}">{{ $attribute['label'] }}</span>
                            </span>

                            <div class="fi-ta-views-row-actions">
                                <x-filament::icon-button
                                    icon="lucide-ellipsis-vertical"
                                    color="gray"
                                    size="sm"
                                    :label="__('filament/pages/boards.view_settings.attribute_actions', ['attribute' => $attribute['label']])"
                                    data-attribute-actions
                                    x-on:click="openRowMenu($el, $event, 'attribute', {{ Js::from($attribute['key']) }})"
                                />
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="fi-dropdown-list fi-view-settings-footer">
                <x-filament::dropdown.list.item
                    icon="lucide-plus"
                    role="button"
                    tabindex="0"
                    data-board-add-attribute
                    data-panel-trigger="add"
                    x-bind:aria-expanded="(panel === 'add').toString()"
                    x-on:click="openPanel('add')"
                >
                    <span class="fi-view-settings-add">
                        {{ __('filament/pages/boards.view_settings.add_attribute') }}
                        <x-filament::icon icon="lucide-chevron-right" class="fi-view-settings-add-chevron" />
                    </span>
                </x-filament::dropdown.list.item>
            </div>
        </div>

        <div class="fi-view-settings-view" x-show="panel === 'columns'" x-cloak data-board-panel="columns">
            <div class="fi-view-settings-search">
                <x-filament::icon-button
                    icon="lucide-chevron-left"
                    color="gray"
                    size="sm"
                    :label="__('filament/panel.tables.views.back')"
                    data-view-settings-back
                    x-on:click="closePanel()"
                />
                <p class="fi-board-panel-title">{{ __('filament/pages/boards.view_settings.visible_columns') }}</p>
            </div>

            <div class="fi-ta-views-scroll">
                <div class="fi-ta-views-row fi-board-stage-row fi-board-no-value-row" data-board-no-value-toggle>
                    <span class="fi-board-stage-grip-space" aria-hidden="true"></span>

                    <span @class(['fi-board-stage', 'fi-board-stage-off' => ! $noValueColumn['on']])>
                        <span class="fi-board-stage-mark">
                            <span class="fi-board-stage-dot-empty"></span>
                        </span>
                        <span class="fi-board-stage-name" title="{{ $noValueColumn['label'] }}">{{ $noValueColumn['label'] }}</span>
                    </span>

                    <span
                        class="fi-board-stage-switch"
                        x-on:click="$wire.setBoardColumnVisible({{ Js::from(\App\Data\BoardStateData::NO_VALUE_COLUMN) }}, {{ $noValueColumn['on'] ? 'false' : 'true' }}).finally(() => syncSwitch($el))"
                    >
                        <x-filament::toggle
                            :state="$noValueColumn['on'] ? 'true' : 'false'"
                            :aria-label="__('filament/pages/boards.view_settings.show_column', ['column' => $noValueColumn['label']])"
                            :data-visible="$noValueColumn['on'] ? 'true' : 'false'"
                            data-board-column-toggle
                        />
                    </span>
                </div>

                <hr class="fi-board-stage-divider" />

                <div class="fi-ta-views-list" x-ref="list" x-sort="reorder('stage')" x-sort:config="{ draggable: '.fi-ta-views-draggable', filter: '.fi-ta-views-row-actions, .fi-board-stage-switch', preventOnFilter: false, delay: 200, delayOnTouchOnly: true, animation: 150 }">
                    @foreach ($columns as $column)
                        @php
                            $dotColor = ColorResolver::resolve($column['color']);
                            $isLastVisible = $column['visible'] && $visibleRealCount <= 1;
                        @endphp

                        <div
                            class="fi-ta-views-row fi-ta-views-draggable fi-board-stage-row"
                            x-sort:item="{{ Js::from((string) $column['id']) }}"
                            data-stage-id="{{ $column['id'] }}"
                            wire:key="board-stage.{{ $column['id'] }}"
                        >
                            <span class="fi-board-stage-grip" aria-hidden="true">
                                <x-filament::icon icon="lucide-grip-vertical" />
                            </span>

                            <span @class(['fi-board-stage', 'fi-board-stage-off' => ! $column['visible']])>
                                <span class="fi-board-stage-mark">
                                    @if ($dotColor)
                                        <span class="fi-board-stage-dot" style="{{ get_color_css_variables($dotColor, shades: [500]) }}"></span>
                                    @endif
                                </span>
                                <span class="fi-board-stage-name" title="{{ $column['name'] }}">{{ $column['name'] }}</span>
                            </span>

                            <div class="fi-ta-views-row-actions">
                                <x-filament::icon-button
                                    icon="lucide-ellipsis-vertical"
                                    color="gray"
                                    size="sm"
                                    :label="__('filament/pages/boards.view_settings.stage_actions', ['stage' => $column['name']])"
                                    data-stage-actions
                                    x-on:click="openRowMenu($el, $event, 'stage', {{ Js::from((string) $column['id']) }})"
                                />
                            </div>

                            <span
                                class="fi-board-stage-switch"
                                x-on:click="$wire.setBoardColumnVisible({{ Js::from((string) $column['id']) }}, {{ $column['visible'] ? 'false' : 'true' }}).finally(() => syncSwitch($el))"
                                @if ($isLastVisible) x-tooltip="{ content: {{ Js::from(__('filament/pages/boards.view_settings.last_column')) }}, theme: $store.theme }" @endif
                            >
                                <x-filament::toggle
                                    :state="$column['visible'] ? 'true' : 'false'"
                                    :disabled="$isLastVisible"
                                    :aria-label="__('filament/pages/boards.view_settings.show_column', ['column' => $column['name']])"
                                    :aria-describedby="$isLastVisible ? 'board-last-column-hint' : null"
                                    :data-visible="$column['visible'] ? 'true' : 'false'"
                                    data-board-column-toggle
                                />
                                @if ($isLastVisible)
                                    <span id="board-last-column-hint" class="sr-only">{{ __('filament/pages/boards.view_settings.last_column') }}</span>
                                @endif
                            </span>
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
                    x-on:click="closePanel()"
                />
                <p class="fi-board-panel-title" id="board-column-field-heading">{{ __('filament/pages/boards.view_settings.grouped_by_heading') }}</p>
            </div>

            <div class="fi-view-settings-scroll">
                <div class="fi-dropdown-list" role="radiogroup" aria-labelledby="board-column-field-heading">
                    @foreach ($columnFields as $candidate)
                        <button
                            type="button"
                            data-board-column-field="{{ $candidate['code'] }}"
                            role="radio"
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
                    x-on:click="closePanel()"
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

        <div class="fi-view-settings-view" x-show="panel === 'add'" x-cloak data-board-panel="add">
            <div class="fi-view-settings-search">
                <x-filament::icon-button
                    icon="lucide-chevron-left"
                    color="gray"
                    size="sm"
                    :label="__('filament/panel.tables.views.back')"
                    data-view-settings-back
                    x-on:click="closePanel()"
                />
                <input
                    type="search"
                    x-ref="addSearch"
                    x-model="query"
                    autocomplete="off"
                    placeholder="{{ __('filament/pages/boards.view_settings.search_attributes', ['entity' => $entityLabel]) }}"
                    aria-label="{{ __('filament/pages/boards.view_settings.search_attributes', ['entity' => $entityLabel]) }}"
                    class="fi-view-settings-search-input"
                    data-view-settings-search
                    x-on:keydown.enter.prevent="pickFirst()"
                    x-on:keydown.arrow-down.prevent="stepAdd(1)"
                    x-on:keydown.arrow-up.prevent="stepAdd(-1)"
                />
            </div>

            <div class="fi-view-settings-scroll" x-on:keydown.arrow-down.prevent="stepAdd(1)" x-on:keydown.arrow-up.prevent="stepAdd(-1)">
                <div class="fi-dropdown-list">
                    @foreach ($addableAttributes as $attribute)
                        <x-filament::dropdown.list.item
                            :wire:key="'board-add.'.$attribute['key']"
                            :icon="$attribute['icon']"
                            role="button"
                            tabindex="0"
                            :data-board-add-item="$attribute['key']"
                            :data-text="$attribute['label']"
                            x-show="isCandidate($el)"
                            x-on:click="add($el.dataset.boardAddItem)"
                        >
                            {{ $attribute['label'] }}
                        </x-filament::dropdown.list.item>
                    @endforeach
                </div>

                @if ($addableAttributes === [])
                    <p class="fi-view-settings-note">
                        <x-filament::icon icon="lucide-check" />
                        {{ __('filament/pages/boards.view_settings.all_attributes_shown') }}
                    </p>
                @else
                    <p class="fi-view-settings-note" x-cloak x-show="addItems().length === 0">
                        {{ __('filament/pages/boards.view_settings.no_attribute_matches') }}
                    </p>
                @endif
            </div>

            @if ($canCreateAttribute)
                <div class="fi-dropdown-list fi-view-settings-footer" x-ref="createRow" x-on:keydown.arrow-down.prevent="stepAdd(1)" x-on:keydown.arrow-up.prevent="stepAdd(-1)">
                    <x-filament::dropdown.list.item
                        icon="lucide-plus"
                        role="button"
                        tabindex="0"
                        data-view-settings-create
                        x-on:click="$dispatch('arm-label-focus', { selector: '.fi-view-settings-trigger' }); $wire.mountAction('createTableAttribute'); close()"
                    >
                        {{ __('filament/panel.tables.views.create_attribute') }}
                    </x-filament::dropdown.list.item>
                </div>
            @endif
        </div>

        <div x-ref="rowMenu">
            <x-filament::dropdown placement="right-start" :offset="16" width="fi-board-stages-row-menu" shift teleport>
                <x-slot name="trigger">
                    <span hidden></span>
                </x-slot>

                <x-filament::dropdown.list>
                    <x-filament::dropdown.list.item icon="lucide-arrow-up" x-bind:disabled="!! rowStage?.isFirst" x-on:click.stop="move(rowStage.kind, rowStage.id, -1)">
                        {{ __('filament/panel.tables.views.move_up') }}
                    </x-filament::dropdown.list.item>

                    <x-filament::dropdown.list.item icon="lucide-arrow-down" x-bind:disabled="!! rowStage?.isLast" x-on:click.stop="move(rowStage.kind, rowStage.id, 1)">
                        {{ __('filament/panel.tables.views.move_down') }}
                    </x-filament::dropdown.list.item>
                </x-filament::dropdown.list>

                <x-filament::dropdown.list x-show="rowStage?.kind === 'attribute'" x-cloak>
                    <x-filament::dropdown.list.item icon="lucide-eye-off" x-on:click.stop="remove(rowStage.id)">
                        {{ __('filament/pages/boards.view_settings.remove_attribute') }}
                    </x-filament::dropdown.list.item>
                </x-filament::dropdown.list>
            </x-filament::dropdown>
        </div>
    </div>
</x-filament::dropdown>
