<div
    id="add-column-menu"
    x-data="tableAddColumn"
    x-on:add-column-open.window="open($event.detail.trigger)"
    x-on:resize.window="close(false)"
    x-on:scroll.window.capture="$event.target.closest?.('[data-add-column]') || close(false)"
    x-on:livewire:navigating.window="close(false)"
    x-on:keydown.escape.prevent.stop="creating ? closeCreate() : close(true)"
    x-on:click.outside="$event.target.closest('[data-add-column-trigger]') || close(false)"
    x-cloak
    x-show="trigger !== null"
    x-bind:class="{ 'invisible': ! placed }"
    x-bind:style="{ top: `${top}px`, left: `${left}px` }"
    role="group"
    aria-label="{{ __('filament/panel.tables.views.add_column') }}"
    class="fi-dropdown-panel fi-width-xs fi-ta-add-column-menu"
    data-add-column
>
    <div class="fi-view-settings-view" x-show="! creating">
        <div class="fi-view-settings-search">
            <input
                type="search"
                x-model="query"
                autocomplete="off"
                placeholder="{{ __('filament/panel.tables.views.search_attributes', ['entity' => $entityLabel]) }}"
                aria-label="{{ __('filament/panel.tables.views.search_attributes', ['entity' => $entityLabel]) }}"
                data-add-column-search
                x-on:keydown.enter.prevent="pickFirst()"
                x-on:keydown.arrow-down.prevent="stepAdd(1)"
                x-on:keydown.arrow-up.prevent="stepAdd(-1)"
            />
        </div>

        <div class="fi-view-settings-scroll" x-on:keydown.arrow-down.prevent="stepAdd(1)" x-on:keydown.arrow-up.prevent="stepAdd(-1)">
            <div class="fi-dropdown-list">
                @foreach ($hidden as $column)
                    <x-filament::dropdown.list.item
                        :wire:key="'add-column.'.$column['name']"
                        role="button"
                        tabindex="0"
                        :aria-label="__('filament/panel.tables.views.add_column_attribute', ['column' => $column['text']])"
                        data-add-column-item
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
                <p class="fi-menu-empty fi-view-settings-note">
                    <x-filament::icon icon="lucide-check" />
                    {{ __('filament/panel.tables.views.all_attributes_in_view') }}
                </p>
            @else
                <p class="fi-menu-empty fi-view-settings-note" x-cloak x-show="addItems().length === 0">
                    {{ __('filament/panel.tables.views.no_attribute_matches') }}
                </p>
            @endif
        </div>

        @if ($types !== [])
            <div class="fi-dropdown-list fi-view-settings-footer" x-on:keydown.arrow-down.prevent="stepAdd(1)" x-on:keydown.arrow-up.prevent="stepAdd(-1)">
                <x-filament::dropdown.list.item
                    icon="lucide-plus"
                    role="button"
                    tabindex="0"
                    data-add-column-create
                    x-bind:aria-expanded="creating.toString()"
                    x-on:click="openCreate()"
                >
                    <span class="fi-view-settings-add">
                        {{ __('filament/panel.tables.views.create_attribute') }}
                        <x-filament::icon icon="lucide-chevron-right" class="fi-view-settings-add-chevron" />
                    </span>
                </x-filament::dropdown.list.item>
            </div>
        @endif
    </div>

    @if ($types !== [])
        <div class="fi-view-settings-view" x-show="creating" x-cloak>
            <div class="fi-view-settings-search">
                <x-filament::icon-button
                    icon="lucide-chevron-left"
                    color="gray"
                    size="sm"
                    :label="__('filament/panel.tables.views.back')"
                    x-on:click="closeCreate()"
                />
                <span class="fi-view-settings-label">{{ __('filament/panel.tables.views.create_attribute') }}</span>
            </div>

            <div class="fi-view-settings-scroll" x-on:keydown.arrow-down.prevent="stepTypes(1)" x-on:keydown.arrow-up.prevent="stepTypes(-1)">
                <div class="fi-dropdown-list">
                    @foreach ($types as $type => $option)
                        <x-filament::dropdown.list.item
                            :icon="$option['icon']"
                            role="button"
                            tabindex="0"
                            :aria-label="__('filament/panel.tables.views.create_attribute_type', ['type' => $option['label']])"
                            data-add-column-type
                            :data-type="$type"
                            x-on:click="create($el.dataset.type); close(false)"
                        >
                            {{ $option['label'] }}
                        </x-filament::dropdown.list.item>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
