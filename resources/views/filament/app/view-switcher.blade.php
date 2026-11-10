@use('Illuminate\Support\Js')

@php
    $rowMenuView = fn (array $row): array => [
        'id' => $row['id'],
        'canUpdate' => $row['canUpdate'],
        'isShared' => $row['isShared'],
        'isDefault' => $row['isDefault'],
        'createdLabel' => $row['createdLabel'],
    ];
@endphp

<x-filament::dropdown
    placement="bottom-start"
    width="xs"
    shift
    class="fi-view-switcher"
    x-on:view-save-settled.window="setTimeout(() => (document.activeElement === document.body || document.activeElement.closest('[data-view-save]')) && $el.querySelector('.fi-view-switcher-trigger')?.focus())"
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
        x-data="{
            query: '',
            rowView: null,
            openRowMenu(trigger, event, view) {
                const panel = document.querySelector('.fi-ta-views-row-menu')

                if (panel._x_isShown && panel.trigger === trigger) {
                    return
                }

                this.rowView = view

                setTimeout(() => {
                    panel.open(trigger)

                    if (event.detail === 0) {
                        this.$nextTick(() => [...panel.querySelectorAll('.fi-dropdown-list-item')].find((item) => item.offsetParent !== null)?.focus())
                    }
                })
            },
        }"
    >
        @if ($searchable)
            <label class="fi-ta-views-search">
                <x-filament::icon icon="lucide-search" />
                <input type="search" x-model="query" x-on:search="query = $el.value" x-on:keydown.stop placeholder="{{ __('filament/panel.tables.views.search') }}" aria-label="{{ __('filament/panel.tables.views.search') }}" />
            </label>
        @endif

        @if ($groups !== [])
        <div class="fi-ta-views-scroll">
            @foreach ($groups as $group)
                <x-filament::dropdown.list x-show="! query || {{ Js::from(collect($group['rows'])->map(fn (array $row): string => mb_strtolower($row['name']))->values()) }}.some((name) => name.includes(query.toLowerCase()))">
                    <p class="fi-ta-views-heading">{{ $group['heading'] }}</p>

                    @foreach ($group['rows'] as $row)
                        <div
                            @class(['fi-ta-views-row', 'fi-ta-views-selected' => $row['isActive']])
                            data-view-id="{{ $row['id'] }}" data-can-update="{{ $row['canUpdate'] ? 'true' : 'false' }}"
                            x-show="! query || @js(mb_strtolower($row['name'])).includes(query.toLowerCase())"
                            wire:key="view-switcher.{{ $row['id'] }}"
                        >
                            @if ($canApply)
                                <x-filament::dropdown.list.item
                                    :color="$row['isActive'] ? 'primary' : 'gray'"
                                    :aria-current="$row['isActive'] ? 'true' : null"
                                    class="min-w-0 flex-1"
                                    wire:click="applySavedView('{{ $row['id'] }}')"
                                >
                                    @include('filament.app.view-switcher-row', ['row' => $row])
                                </x-filament::dropdown.list.item>

                                <div class="fi-ta-views-row-actions">
                                    <x-filament::icon-button
                                        icon="lucide-ellipsis-vertical"
                                        color="gray"
                                        size="sm"
                                        :label="__('filament/panel.tables.views.actions', ['name' => $row['name']])"
                                        x-on:click="openRowMenu($el, $event, {{ Js::from($rowMenuView($row)) }})"
                                    />
                                </div>
                            @else
                                <x-filament::dropdown.list.item
                                    tag="a"
                                    :href="$row['url']"
                                    :spa-mode="true"
                                    :color="$row['isActive'] ? 'primary' : 'gray'"
                                    class="min-w-0 flex-1"
                                >
                                    @include('filament.app.view-switcher-row', ['row' => $row])
                                </x-filament::dropdown.list.item>
                            @endif
                        </div>
                    @endforeach
                </x-filament::dropdown.list>
            @endforeach
        </div>
        @endif

        @if ($canApply && $hasViews)
            <div>
                <x-filament::dropdown placement="right-start" :offset="16" width="fi-ta-views-row-menu" shift teleport>
                    <x-slot name="trigger">
                        <span hidden></span>
                    </x-slot>

                    <x-filament::dropdown.list>
                        <x-filament::dropdown.list.item icon="lucide-star" x-on:click="$wire.toggleDefaultSavedView(rowView.id)">
                            <span x-text="rowView?.isDefault ? @js(__('filament/panel.tables.views.unset_default')) : @js(__('filament/panel.tables.views.set_default'))"></span>
                        </x-filament::dropdown.list.item>

                        <x-filament::dropdown.list.item icon="lucide-pencil" x-show="rowView?.canUpdate" x-on:click="$wire.mountAction('renameSavedView', { view: rowView.id })">
                            {{ __('filament/panel.tables.views.rename') }}
                        </x-filament::dropdown.list.item>

                        <x-filament::dropdown.list.item icon="lucide-copy" x-on:click="$wire.duplicateSavedView(rowView.id)">
                            {{ __('filament/panel.tables.views.duplicate') }}
                        </x-filament::dropdown.list.item>

                        <x-filament::dropdown.list.item icon="lucide-lock" x-show="rowView?.canUpdate && rowView?.isShared" x-on:click="$wire.mountAction('stopSharingSavedView', { view: rowView.id })">
                            {{ __('filament/panel.tables.views.unshare') }}
                        </x-filament::dropdown.list.item>

                        <x-filament::dropdown.list.item icon="lucide-users" x-show="rowView?.canUpdate && ! rowView?.isShared" x-on:click="$wire.mountAction('shareSavedView', { view: rowView.id })">
                            {{ __('filament/panel.tables.views.share') }}
                        </x-filament::dropdown.list.item>
                    </x-filament::dropdown.list>

                    <x-filament::dropdown.list x-show="rowView?.canUpdate">
                        <x-filament::dropdown.list.item icon="lucide-trash-2" color="danger" x-on:click="$wire.mountAction('deleteSavedView', { view: rowView.id })">
                            {{ __('filament/panel.tables.views.delete') }}
                        </x-filament::dropdown.list.item>
                    </x-filament::dropdown.list>

                    <p class="fi-ta-views-row-created" x-text="rowView?.createdLabel"></p>
                </x-filament::dropdown>
            </div>
        @endif

        <x-filament::dropdown.list>
            @foreach ($fixed as $entry)
                @php
                    $selected = $entry['current'] && ($entry['kind'] !== 'list' || ! $hasActiveView);
                @endphp

                @if ($entry['kind'] === 'list' && $canApply)
                    <x-filament::dropdown.list.item
                        :color="$selected ? 'primary' : 'gray'"
                        :aria-current="$selected ? 'true' : null"
                        class="{{ $selected ? 'fi-ta-views-selected' : '' }}"
                        wire:click="applyOwnTable"
                    >
                        @include('filament.app.view-switcher-entry', ['entry' => $entry])
                    </x-filament::dropdown.list.item>
                @else
                    <x-filament::dropdown.list.item
                        tag="a"
                        :href="$entry['url']"
                        :spa-mode="true"
                        :color="$selected ? 'primary' : 'gray'"
                        :aria-current="$selected ? 'page' : null"
                        class="{{ $selected ? 'fi-ta-views-selected' : '' }}"
                    >
                        @include('filament.app.view-switcher-entry', ['entry' => $entry])
                    </x-filament::dropdown.list.item>
                @endif
            @endforeach
        </x-filament::dropdown.list>

        @if ($canApply)
            <x-filament::dropdown.list>
                <x-filament::dropdown.list.item icon="lucide-plus" class="fi-ta-views-save-current" wire:click="mountAction('saveSavedViewAs', { create: true })">
                    {{ __('filament/panel.tables.views.save_current') }}
                </x-filament::dropdown.list.item>
            </x-filament::dropdown.list>
        @endif
    </div>
</x-filament::dropdown>
