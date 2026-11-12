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
        x-data="{
            rowStage: null,
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
            openRowMenu(trigger, event, stage) {
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
