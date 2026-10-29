@php
    $layouts = $this->getVisibleTableLayouts();
    $active = $this->getActiveTableLayout();
    $isModified = $this->isTableLayoutModified();
    $canUpdate = $this->canUpdateActiveTableLayout();
    $userId = auth()->id();
    $own = $layouts->where('user_id', $userId);
    $shared = $layouts->where('user_id', '!=', $userId);
@endphp

<div class="fi-ta-layouts" wire:key="{{ $this->getId() }}.table.layouts">
    <x-filament::dropdown placement="bottom-start" width="xs" shift>
        <x-slot name="trigger">
            <x-filament::button color="gray" size="sm" icon="heroicon-o-queue-list" class="fi-ta-layouts-trigger">
                <span class="fi-ta-layouts-trigger-label" @if ($active) title="{{ $active->name }}" @endif>{{ $active?->name ?? __('filament/panel.tables.layouts.label') }}</span>

                @if ($isModified)
                    <span class="font-normal text-gray-500 dark:text-gray-400">{{ __('filament/panel.tables.layouts.modified') }}</span>
                @endif

                <x-filament::icon icon="heroicon-m-chevron-down" class="fi-view-switcher-chevron" />
            </x-filament::button>
        </x-slot>

        <div class="fi-ta-layouts-panel" x-data="{ query: '' }">
        @if ($layouts->count() >= 7)
            <label class="fi-ta-layouts-search">
                <x-filament::icon icon="heroicon-o-magnifying-glass" />
                <input type="search" x-model="query" x-on:keydown.stop placeholder="{{ __('filament/panel.tables.layouts.search') }}" aria-label="{{ __('filament/panel.tables.layouts.search') }}" />
            </label>
        @endif

        <div class="fi-ta-layouts-scroll">
        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item
                icon="heroicon-o-table-cells"
                :color="$active ? 'gray' : 'primary'"
                :aria-current="$active ? null : 'true'"
                class="{{ $active ? '' : 'fi-ta-layouts-selected' }}"
                wire:click="useStandardTableLayout"
            >
                <span class="flex items-center justify-between gap-3">
                    <span class="truncate">{{ __('filament/panel.tables.layouts.standard') }}</span>

                    <span class="fi-ta-layouts-row-meta">
                        @if ($this->defaultTableLayoutId === null)
                            <span role="img" aria-label="{{ __('filament/panel.tables.layouts.default') }}" class="fi-ta-layouts-row-default"><x-filament::icon icon="heroicon-s-star" /></span>
                        @endif
                    </span>
                </span>
            </x-filament::dropdown.list.item>
        </x-filament::dropdown.list>

        @foreach ([__('filament/panel.tables.layouts.mine') => $own, __('filament/panel.tables.layouts.shared_with_workspace') => $shared] as $heading => $group)
            @if ($group->isNotEmpty())
                <x-filament::dropdown.list>
                    <p class="fi-ta-layouts-heading">
                        <span>{{ $heading }}</span>
                        <span class="fi-ta-layouts-pill">{{ __('filament/panel.tables.layouts.available', ['count' => $group->count()]) }}</span>
                    </p>

                    @foreach ($group as $layout)
                        @php
                            $layoutId = $layout->getKey();
                            $isDefault = $layoutId === $this->defaultTableLayoutId;
                            $canUpdateLayout = $this->canUpdateTableLayout($layout);
                        @endphp

                        <div
                            @class(['fi-ta-layouts-row', 'fi-ta-layouts-selected' => $layout->is($active)])
                            x-show="! query || @js(mb_strtolower($layout->name)).includes(query.toLowerCase())"
                            wire:key="{{ $this->getId() }}.table.layouts.{{ $layoutId }}"
                        >
                            <x-filament::dropdown.list.item
                                :icon="$layout->is_shared ? 'heroicon-o-user-group' : 'heroicon-o-bookmark'"
                                :color="$layout->is($active) ? 'primary' : 'gray'"
                                :aria-current="$layout->is($active) ? 'true' : null"
                                class="min-w-0 flex-1"
                                wire:click="applySavedLayout('{{ $layoutId }}')"
                            >
                                <span class="flex items-center justify-between gap-3">
                                    <span class="truncate" title="{{ $layout->name }}">{{ $layout->name }}</span>

                                    <span class="fi-ta-layouts-row-meta">
                                        @if ($isDefault)
                                            <span role="img" aria-label="{{ __('filament/panel.tables.layouts.default') }}" class="fi-ta-layouts-row-default"><x-filament::icon icon="heroicon-s-star" /></span>
                                        @endif

                                        @if ($layout->user_id !== $userId)
                                            <span class="truncate">{{ $layout->user?->name }}</span>
                                        @elseif ($layout->is_shared)
                                            <span>{{ __('filament/panel.tables.layouts.shared_label') }}</span>
                                        @endif
                                    </span>
                                </span>
                            </x-filament::dropdown.list.item>

                            <div class="fi-ta-layouts-row-actions">
                                @if ($canUpdateLayout)
                                    <x-filament::icon-button
                                        icon="heroicon-o-trash"
                                        color="gray"
                                        size="sm"
                                        class="fi-ta-layouts-row-delete"
                                        :label="__('filament/panel.tables.layouts.delete')"
                                        :tooltip="__('filament/panel.tables.layouts.delete')"
                                        wire:click="mountAction('deleteTableLayout', { layout: '{{ $layoutId }}' })"
                                    />
                                @endif

                                <x-filament::dropdown placement="bottom-end" width="fi-ta-layouts-row-menu" teleport>
                                    <x-slot name="trigger">
                                        <x-filament::icon-button
                                            icon="heroicon-m-ellipsis-vertical"
                                            color="gray"
                                            size="sm"
                                            :label="__('filament/panel.tables.layouts.actions', ['name' => $layout->name])"
                                        />
                                    </x-slot>

                                    <x-filament::dropdown.list>
                                        @if ($canUpdateLayout)
                                            <x-filament::dropdown.list.item icon="heroicon-o-pencil-square" wire:click="mountAction('renameTableLayout', { layout: '{{ $layoutId }}' })">
                                                {{ __('filament/panel.tables.layouts.rename') }}
                                            </x-filament::dropdown.list.item>

                                            @if ($layout->is_shared)
                                                <x-filament::dropdown.list.item icon="heroicon-o-user-group" wire:click="mountAction('stopSharingTableLayout', { layout: '{{ $layoutId }}' })">
                                                    {{ __('filament/panel.tables.layouts.unshare') }}
                                                </x-filament::dropdown.list.item>
                                            @else
                                                <x-filament::dropdown.list.item icon="heroicon-o-user-group" wire:click="mountAction('shareTableLayout', { layout: '{{ $layoutId }}' })">
                                                    {{ __('filament/panel.tables.layouts.share') }}
                                                </x-filament::dropdown.list.item>
                                            @endif
                                        @endif

                                        <x-filament::dropdown.list.item icon="heroicon-o-star" wire:click="toggleDefaultTableLayout('{{ $layoutId }}')">
                                            {{ $isDefault ? __('filament/panel.tables.layouts.unset_default') : __('filament/panel.tables.layouts.set_default') }}
                                        </x-filament::dropdown.list.item>
                                    </x-filament::dropdown.list>

                                    @if ($canUpdateLayout)
                                        <x-filament::dropdown.list>
                                            <x-filament::dropdown.list.item icon="heroicon-o-trash" color="danger" wire:click="mountAction('deleteTableLayout', { layout: '{{ $layoutId }}' })">
                                                {{ __('filament/panel.tables.layouts.delete') }}
                                            </x-filament::dropdown.list.item>
                                        </x-filament::dropdown.list>
                                    @endif
                                </x-filament::dropdown>
                            </div>
                        </div>
                    @endforeach
                </x-filament::dropdown.list>
            @endif
        @endforeach

        </div>

        <x-filament::dropdown.list>
            <button type="button" class="fi-dropdown-list-item fi-ta-layouts-save-current" wire:click="mountAction('saveTableLayoutAs')">
                <x-filament::icon icon="heroicon-m-plus" />
                {{ __('filament/panel.tables.layouts.save_current') }}
            </button>
        </x-filament::dropdown.list>
        </div>
    </x-filament::dropdown>

    @if ($isModified)
        <x-filament::button
            color="gray"
            size="sm"
            icon="heroicon-o-arrow-uturn-left"
            label-sr-only
            :tooltip="__('filament/panel.tables.layouts.revert')"
            wire:click="revertTableLayout"
        >
            {{ __('filament/panel.tables.layouts.revert') }}
        </x-filament::button>

        @if ($canUpdate)
            <x-filament::button
                color="gray"
                size="sm"
                icon="heroicon-o-check"
                label-sr-only
                :tooltip="__('filament/panel.tables.layouts.save')"
                class="fi-ta-layouts-save"
                wire:click="saveTableLayout"
            >
                {{ __('filament/panel.tables.layouts.save') }}
            </x-filament::button>
        @endif

        <x-filament::button
            color="gray"
            size="sm"
            icon="heroicon-o-plus"
            label-sr-only
            :tooltip="__('filament/panel.tables.layouts.save_as')"
            class="fi-ta-layouts-save-as"
            wire:click="mountAction('saveTableLayoutAs')"
        >
            {{ __('filament/panel.tables.layouts.save_as') }}
        </x-filament::button>
    @endif
</div>
