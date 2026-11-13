@use('Illuminate\Support\Js')

<x-filament::dropdown :menu="false" placement="bottom-start" width="xs" shift size class="fi-view-settings">
    <x-slot name="trigger">
        <x-filament::button color="gray" size="sm" class="fi-view-settings-trigger">
            <x-filament::icon icon="lucide-sliders-horizontal" class="fi-view-settings-icon" />
            <span class="fi-view-settings-text">{{ __('filament/panel.tables.views.settings') }}</span>
            <x-filament::icon icon="lucide-chevron-down" class="fi-view-switcher-chevron" />
        </x-filament::button>
    </x-slot>

    <div class="fi-view-settings-panel" x-data="viewSettingsColumns" x-on:resize.window="closeMenu(false)" x-on:scroll.window.capture="closeMenu(false)" x-on:livewire:navigating.window="closeMenu(false)" data-view-settings role="group" x-on:dropdown-escape="adding && ($event.preventDefault(), closeAdd())" aria-label="{{ __('filament/panel.tables.views.view_settings_panel') }}">
        <div class="fi-view-settings-view" x-show="! adding" x-on:keydown.arrow-down.prevent="stepList(1)" x-on:keydown.arrow-up.prevent="stepList(-1)">
            <div class="fi-view-settings-scroll">
                <div
                    x-sortable
                    x-on:end.stop="reorder($event)"
                    x-on:pointerdown="$event.target.closest('[data-view-settings-menu]') || closeMenu(false)"
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
                                tabindex="0"
                                aria-controls="view-settings-row-menu"
                                x-bind:aria-expanded="(menu === {{ Js::from($column['name']) }}).toString()"
                                data-view-settings-menu
                                class="fi-view-settings-row-menu-trigger"
                                x-on:click="openMenu($el)"
                                x-on:dropdown-escape="menu !== null && ($event.preventDefault(), closeMenu(true))"
                            />
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="fi-dropdown-list fi-view-settings-footer">
                <x-filament::dropdown.list.item
                    icon="lucide-plus"
                    role="button"
                    tabindex="0"
                    data-view-settings-add
                    x-bind:aria-expanded="adding.toString()"
                    x-on:click="openAdd()"
                >
                    <span class="fi-view-settings-add">
                        {{ __('filament/panel.tables.views.add_attribute') }}
                        <x-filament::icon icon="lucide-chevron-right" class="fi-view-settings-add-chevron" />
                    </span>
                </x-filament::dropdown.list.item>
            </div>
        </div>

        <div class="fi-view-settings-view" x-show="adding" x-cloak data-view-settings-add-panel>
            <div class="fi-view-settings-search">
                <x-filament::icon-button
                    icon="lucide-chevron-left"
                    color="gray"
                    size="sm"
                    :label="__('filament/panel.tables.views.back')"
                    data-view-settings-back
                    x-on:click="closeAdd()"
                />
                <input
                    type="search"
                    x-model="query"
                    autocomplete="off"
                    placeholder="{{ __('filament/panel.tables.views.search_attributes', ['entity' => $entityLabel]) }}"
                    aria-label="{{ __('filament/panel.tables.views.search_attributes', ['entity' => $entityLabel]) }}"
                    class="fi-view-settings-search-input"
                    data-view-settings-search
                    x-on:keydown.enter.prevent="pickFirst()"
                    x-on:keydown.arrow-down.prevent="stepAdd(1)"
                    x-on:keydown.arrow-up.prevent="stepAdd(-1)"
                />
            </div>

            <div class="fi-view-settings-scroll" x-on:keydown.arrow-down.prevent="stepAdd(1)" x-on:keydown.arrow-up.prevent="stepAdd(-1)">
                <div class="fi-dropdown-list">
                    @foreach ($hidden as $column)
                        <x-filament::dropdown.list.item
                            :wire:key="'view-settings-add.'.$column['name']"
                            role="button"
                            tabindex="0"
                            :aria-label="__('filament/panel.tables.views.show_column', ['column' => $column['text']])"
                            data-view-settings-add-item
                            :data-attribute="$column['name']"
                            :data-text="$column['text']"
                            x-show="isCandidate($el)"
                            x-on:click="show($el.dataset.attribute)"
                        >
                            <span class="fi-view-settings-label">{{ $column['label'] }}</span>
                        </x-filament::dropdown.list.item>
                    @endforeach
                </div>

                @if ($hidden === [])
                    <p class="fi-view-settings-note">
                        <x-filament::icon icon="lucide-check" />
                        {{ __('filament/panel.tables.views.all_attributes_in_view') }}
                    </p>
                @else
                    <p class="fi-view-settings-note" x-cloak x-show="addItems().length === 0">
                        {{ __('filament/panel.tables.views.no_attribute_matches') }}
                    </p>
                @endif
            </div>

            @if ($canCreateAttribute)
                <div class="fi-dropdown-list fi-view-settings-footer" x-on:keydown.arrow-down.prevent="stepAdd(1)" x-on:keydown.arrow-up.prevent="stepAdd(-1)">
                    <x-filament::dropdown.list.item
                        icon="lucide-plus"
                        role="button"
                        tabindex="0"
                        data-view-settings-create
                        x-on:click="createAttribute(); close()"
                    >
                        {{ __('filament/panel.tables.views.create_attribute') }}
                    </x-filament::dropdown.list.item>
                </div>
            @endif
        </div>

        <div
            x-ref="rowMenu"
            id="view-settings-row-menu"
            role="menu"
            aria-label="{{ __('filament/panel.tables.column_menu.options') }}"
            x-cloak
            x-show="menu !== null"
            x-bind:class="{ 'invisible': ! placed }"
            x-bind:style="{ top: `${top}px`, left: `${left}px` }"
            x-on:dropdown-escape.prevent="closeMenu(true)"
            x-on:keydown.arrow-down.prevent="step(1)"
            x-on:keydown.arrow-up.prevent="step(-1)"
            x-on:keydown.home.prevent="menuItems()[0]?.focus()"
            x-on:keydown.end.prevent="menuItems().at(-1)?.focus()"
            x-on:keydown.tab.prevent="closeMenu(true)"
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
                    icon="lucide-eye-off"
                    data-view-settings-menu-item="remove"
                    x-on:click="remove()"
                >
                    {{ __('filament/panel.tables.column_menu.hide') }}
                </x-filament::dropdown.list.item>
            </div>
        </div>
    </div>
</x-filament::dropdown>
