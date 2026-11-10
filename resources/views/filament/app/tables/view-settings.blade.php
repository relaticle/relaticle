@use('Illuminate\Support\Js')

<x-filament::dropdown placement="bottom-start" width="xs" shift class="fi-view-settings">
    <x-slot name="trigger">
        <x-filament::button color="gray" size="sm" class="fi-view-settings-trigger">
            <x-filament::icon icon="lucide-sliders-horizontal" class="fi-view-settings-icon" />
            <span class="fi-view-settings-text">{{ __('filament/panel.tables.views.settings') }}</span>
            <x-filament::icon icon="lucide-chevron-down" class="fi-view-switcher-chevron" />
        </x-filament::button>
    </x-slot>

    <div class="fi-view-settings-panel" x-data="viewSettingsColumns">
        <p class="fi-ta-views-heading">{{ __('filament/panel.tables.views.columns') }}</p>

        <div
            x-sortable
            x-on:end.stop="reorder($event)"
            data-sortable-animation-duration="150"
            class="fi-view-settings-list"
        >
            @foreach ($shown as $column)
                <div
                    class="fi-view-settings-row"
                    x-sortable-item="{{ $column['name'] }}"
                    data-column="{{ $column['name'] }}"
                    @if ($column['isPinned']) data-column-pinned @endif
                    wire:key="view-settings.{{ $column['name'] }}"
                >
                    @if ($column['isPinned'])
                        <span class="fi-view-settings-handle fi-view-settings-pin" title="{{ __('filament/panel.tables.views.pinned_column') }}">
                            <x-filament::icon icon="lucide-pin" />
                            <span class="sr-only">{{ __('filament/panel.tables.views.pinned_column') }}</span>
                        </span>
                    @else
                        <button type="button" x-sortable-handle tabindex="-1" aria-hidden="true" class="fi-view-settings-handle fi-view-settings-grip">
                            <x-filament::icon icon="lucide-grip-vertical" />
                        </button>
                    @endif

                    <span class="fi-view-settings-label">{{ $column['label'] }}</span>

                    @if ($column['isLocked'])
                        <span class="fi-view-settings-lock" title="{{ __('filament/panel.tables.views.title_column_locked') }}">
                            <x-filament::icon icon="lucide-lock" />
                            <span class="sr-only">{{ __('filament/panel.tables.views.title_column_locked') }}</span>
                        </span>
                    @else
                        <x-filament::icon-button
                            icon="lucide-eye"
                            color="gray"
                            size="sm"
                            :label="__('filament/panel.tables.views.hide_column', ['column' => $column['text']])"
                            data-view-settings-toggle
                            x-on:click="toggle($el.closest('[data-column]'))"
                        />
                    @endif
                </div>
            @endforeach
        </div>

        <x-filament::dropdown.list>
            @if ($hidden === [])
                <x-filament::dropdown.list.item icon="lucide-check" disabled aria-disabled="true" data-view-settings-add>
                    {{ __('filament/panel.tables.views.all_columns_shown') }}
                </x-filament::dropdown.list.item>
            @else
                <x-filament::dropdown.list.item
                    icon="lucide-plus"
                    data-view-settings-add
                    x-bind:aria-expanded="adding.toString()"
                    x-on:click.stop="adding = ! adding"
                >
                    <span class="fi-view-settings-add">
                        {{ __('filament/panel.tables.views.add_column') }}
                        <x-filament::icon icon="lucide-chevron-right" class="fi-view-settings-add-chevron" x-bind:class="{ 'fi-expanded': adding }" />
                    </span>
                </x-filament::dropdown.list.item>

                <div x-show="adding" x-cloak class="fi-view-settings-hidden">
                    @foreach ($hidden as $column)
                        <x-filament::dropdown.list.item
                            :wire:key="'view-settings-add.'.$column['name']"
                            :aria-label="__('filament/panel.tables.views.show_column', ['column' => $column['text']])"
                            x-on:click.stop="show({{ Js::from($column['name']) }})"
                        >
                            <span class="fi-view-settings-label">{{ $column['label'] }}</span>
                        </x-filament::dropdown.list.item>
                    @endforeach
                </div>
            @endif
        </x-filament::dropdown.list>

        @if ($canReset)
            <x-filament::dropdown.list>
                <x-filament::dropdown.list.item icon="lucide-undo-2" class="fi-view-settings-reset" wire:click="useStandardTable">
                    {{ __('filament/panel.tables.views.reset') }}
                </x-filament::dropdown.list.item>
            </x-filament::dropdown.list>
        @endif
    </div>
</x-filament::dropdown>
