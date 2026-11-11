@use('Illuminate\Support\Js')

<x-filament::dropdown :menu="false" placement="bottom-start" width="xs" shift class="fi-view-settings">
    <x-slot name="trigger">
        <x-filament::button color="gray" size="sm" class="fi-view-settings-trigger">
            <x-filament::icon icon="lucide-sliders-horizontal" class="fi-view-settings-icon" />
            <span class="fi-view-settings-text">{{ __('filament/panel.tables.views.settings') }}</span>
            <x-filament::icon icon="lucide-chevron-down" class="fi-view-switcher-chevron" />
        </x-filament::button>
    </x-slot>

    <div class="fi-view-settings-panel" x-data="viewSettingsColumns" data-view-settings role="group" aria-label="{{ __('filament/panel.tables.views.view_settings_panel') }}">
        <div class="fi-view-settings-scroll">
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
                    @if ($column['isLocked']) data-column-locked @endif
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

                    <x-filament::icon-button
                        icon="lucide-ellipsis-vertical"
                        color="gray"
                        size="sm"
                        :label="__('filament/panel.tables.views.column_options', ['column' => $column['text']])"
                        aria-haspopup="menu"
                        x-bind:aria-expanded="(menu === {{ Js::from($column['name']) }}).toString()"
                        data-view-settings-menu
                        class="fi-view-settings-row-menu-trigger"
                        x-on:click="openMenu($el)"
                        x-on:dropdown-escape="menu !== null && ($event.preventDefault(), closeMenu(true))"
                    />
                </div>
            @endforeach
        </div>

        <div class="fi-dropdown-list">
            @if ($hidden === [])
                <p class="fi-view-settings-note">
                    <x-filament::icon icon="lucide-check" />
                    {{ __('filament/panel.tables.views.all_columns_shown') }}
                </p>
            @else
                <x-filament::dropdown.list.item
                    icon="lucide-plus"
                    role="button"
                    tabindex="0"
                    data-view-settings-add
                    x-bind:aria-expanded="adding.toString()"
                    x-on:click="adding = ! adding"
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
                            role="button"
                            tabindex="0"
                            :aria-label="__('filament/panel.tables.views.show_column', ['column' => $column['text']])"
                            x-on:click="show({{ Js::from($column['name']) }})"
                        >
                            <span class="fi-view-settings-label">{{ $column['label'] }}</span>
                        </x-filament::dropdown.list.item>
                    @endforeach
                </div>
            @endif
        </div>
        </div>

        @if ($canReset)
            <div class="fi-dropdown-list fi-view-settings-footer">
                <x-filament::dropdown.list.item
                    icon="lucide-undo-2"
                    role="button"
                    tabindex="0"
                    class="fi-view-settings-reset"
                    x-on:click="close(); $wire.mountAction('resetTableToDefault')"
                >
                    {{ __('filament/panel.tables.views.reset') }}
                </x-filament::dropdown.list.item>
            </div>
        @endif

        <div
            x-ref="rowMenu"
            role="menu"
            aria-label="{{ __('filament/panel.tables.column_menu.options') }}"
            x-cloak
            x-show="menu !== null"
            x-bind:class="{ 'invisible': ! placed }"
            x-bind:style="{ top: `${top}px`, left: `${left}px` }"
            x-on:dropdown-escape.prevent="closeMenu(true)"
            x-on:keydown.arrow-down.prevent="step(1)"
            x-on:keydown.arrow-up.prevent="step(-1)"
            x-on:focusout="focusLeft($event)"
            x-on:click.outside="$event.target.closest('[data-view-settings-menu]') || closeMenu(false)"
            class="fi-dropdown-panel fi-view-settings-row-menu"
        >
            <div class="fi-dropdown-list">
                <x-filament::dropdown.list.item
                    icon="lucide-tag"
                    data-view-settings-menu-item="label"
                    x-on:click="editLabel(); close()"
                >
                    {{ __('filament/panel.tables.column_menu.edit_label') }}
                </x-filament::dropdown.list.item>
            </div>
            <div class="fi-dropdown-list" x-show="! locked">
                <x-filament::dropdown.list.item
                    icon="lucide-circle-x"
                    data-view-settings-menu-item="remove"
                    x-on:click="remove()"
                >
                    {{ __('filament/panel.tables.column_menu.hide') }}
                </x-filament::dropdown.list.item>
            </div>
        </div>
    </div>
</x-filament::dropdown>
