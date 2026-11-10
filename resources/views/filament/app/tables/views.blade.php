@use('Filament\Support\Enums\IconSize')
@use('Illuminate\Support\Js')

@php
    $views = $this->getVisibleSavedViews();
    $active = $this->getActiveSavedView();
    $isModified = $this->isSavedViewModified();
    $canUpdate = $this->canUpdateActiveSavedView();
    $userId = auth()->id();
    $own = $views->where('user_id', $userId);
    $shared = $views->where('user_id', '!=', $userId);
@endphp

<div class="fi-ta-views" wire:key="{{ $this->getId() }}.table.views">
    <x-filament::dropdown placement="bottom-start" width="xs" shift>
        <x-slot name="trigger">
            <x-filament::button color="gray" size="sm" icon="lucide-list" class="fi-ta-views-trigger">
                <span class="fi-ta-views-trigger-label" @if ($active) title="{{ $active->name }}" @endif>{{ $active?->name ?? __('filament/panel.tables.views.label') }}</span>

                @if ($isModified)
                    <span class="fi-ta-views-trigger-modified font-normal text-gray-500 dark:text-gray-400">{{ __('filament/panel.tables.views.modified') }}</span>
                @endif

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
        @if ($views->count() >= 7)
            <label class="fi-ta-views-search">
                <x-filament::icon icon="lucide-search" />
                <input type="search" x-model="query" x-on:search="query = $el.value" x-on:keydown.stop placeholder="{{ __('filament/panel.tables.views.search') }}" aria-label="{{ __('filament/panel.tables.views.search') }}" />
            </label>
        @endif

        <div class="fi-ta-views-scroll">
        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item
                icon="lucide-table"
                :color="$active ? 'gray' : 'primary'"
                :aria-current="$active ? null : 'true'"
                class="{{ $active ? '' : 'fi-ta-views-selected' }}"
                wire:click="useStandardTable"
            >
                <span class="flex items-center justify-between gap-3">
                    <span class="truncate">{{ __('filament/panel.tables.views.standard', ['records' => str($this->getTable()->getPluralModelLabel())->lower()->toString()]) }}</span>

                    <span class="fi-ta-views-row-meta">
                        @if ($this->defaultSavedViewId === null)
                            <span role="img" aria-label="{{ __('filament/panel.tables.views.default') }}" class="fi-ta-views-row-default"><x-filament::icon icon="lucide-star" :size="IconSize::Large" class="fill-current" /></span>
                        @endif
                    </span>
                </span>
            </x-filament::dropdown.list.item>
        </x-filament::dropdown.list>

        @foreach ([__('filament/panel.tables.views.mine') => $own, __('filament/panel.tables.views.shared_with_workspace') => $shared] as $heading => $group)
            @if ($group->isNotEmpty())
                <x-filament::dropdown.list x-show="! query || {{ Js::from($group->map(fn (\App\Models\SavedView $view): string => mb_strtolower($view->name))->values()) }}.some((name) => name.includes(query.toLowerCase()))">
                    <p class="fi-ta-views-heading">
                        <span>{{ $heading }}</span>
                        <span class="fi-ta-views-pill">{{ __('filament/panel.tables.views.available', ['count' => $group->count()]) }}</span>
                    </p>

                    @foreach ($group as $view)
                        @php
                            $viewId = $view->getKey();
                            $isDefault = $viewId === $this->defaultSavedViewId;
                            $canUpdateView = $this->canUpdateSavedView($view);
                        @endphp

                        <div
                            @class(['fi-ta-views-row', 'fi-ta-views-selected' => $view->is($active)])
                            x-show="! query || @js(mb_strtolower($view->name)).includes(query.toLowerCase())"
                            wire:key="{{ $this->getId() }}.table.views.{{ $viewId }}"
                        >
                            <x-filament::dropdown.list.item
                                :icon="$view->is_shared ? 'lucide-users' : 'lucide-bookmark'"
                                :color="$view->is($active) ? 'primary' : 'gray'"
                                :aria-current="$view->is($active) ? 'true' : null"
                                class="min-w-0 flex-1"
                                wire:click="applySavedView('{{ $viewId }}')"
                            >
                                <span class="flex items-center justify-between gap-3">
                                    <span class="min-w-0 truncate" title="{{ $view->name }}">{{ $view->name }}</span>

                                    <span class="fi-ta-views-row-meta">
                                        @if ($isDefault)
                                            <span role="img" aria-label="{{ __('filament/panel.tables.views.default') }}" class="fi-ta-views-row-default"><x-filament::icon icon="lucide-star" :size="IconSize::Large" class="fill-current" /></span>
                                        @endif

                                        @if ($view->user_id !== $userId)
                                            <span class="truncate">{{ $view->user?->name }}</span>
                                        @elseif ($view->is_shared)
                                            <span>{{ __('filament/panel.tables.views.shared_label') }}</span>
                                        @endif
                                    </span>
                                </span>
                            </x-filament::dropdown.list.item>

                            <div class="fi-ta-views-row-actions">
                                @if ($canUpdateView)
                                    <x-filament::icon-button
                                        icon="lucide-trash-2"
                                        color="gray"
                                        size="sm"
                                        class="fi-ta-views-row-delete"
                                        :label="__('filament/panel.tables.views.delete')"
                                        :tooltip="__('filament/panel.tables.views.delete')"
                                        wire:click="mountAction('deleteSavedView', { view: '{{ $viewId }}' })"
                                    />
                                @endif

                                <x-filament::icon-button
                                    icon="lucide-ellipsis-vertical"
                                    color="gray"
                                    size="sm"
                                    :label="__('filament/panel.tables.views.actions', ['name' => $view->name])"
                                    x-on:click="openRowMenu($el, $event, {{ Js::from(['id' => $viewId, 'canUpdate' => $canUpdateView, 'isShared' => (bool) $view->is_shared, 'isDefault' => $isDefault]) }})"
                                />
                            </div>
                        </div>
                    @endforeach
                </x-filament::dropdown.list>
            @endif
        @endforeach

        </div>

        @if ($views->isNotEmpty())
            <div>
                <x-filament::dropdown placement="bottom-end" width="fi-ta-views-row-menu" teleport>
                    <x-slot name="trigger">
                        <span hidden></span>
                    </x-slot>

                    <x-filament::dropdown.list>
                        <x-filament::dropdown.list.item icon="lucide-square-pen" x-show="rowView?.canUpdate" x-on:click="$wire.mountAction('renameSavedView', { view: rowView.id })">
                            {{ __('filament/panel.tables.views.rename') }}
                        </x-filament::dropdown.list.item>

                        <x-filament::dropdown.list.item icon="lucide-users" x-show="rowView?.canUpdate && rowView?.isShared" x-on:click="$wire.mountAction('stopSharingSavedView', { view: rowView.id })">
                            {{ __('filament/panel.tables.views.unshare') }}
                        </x-filament::dropdown.list.item>

                        <x-filament::dropdown.list.item icon="lucide-users" x-show="rowView?.canUpdate && ! rowView?.isShared" x-on:click="$wire.mountAction('shareSavedView', { view: rowView.id })">
                            {{ __('filament/panel.tables.views.share') }}
                        </x-filament::dropdown.list.item>

                        <x-filament::dropdown.list.item icon="lucide-star" x-on:click="$wire.toggleDefaultSavedView(rowView.id)">
                            <span x-text="rowView?.isDefault ? @js(__('filament/panel.tables.views.unset_default')) : @js(__('filament/panel.tables.views.set_default'))"></span>
                        </x-filament::dropdown.list.item>
                    </x-filament::dropdown.list>

                    <x-filament::dropdown.list x-show="rowView?.canUpdate">
                        <x-filament::dropdown.list.item icon="lucide-trash-2" color="danger" x-on:click="$wire.mountAction('deleteSavedView', { view: rowView.id })">
                            {{ __('filament/panel.tables.views.delete') }}
                        </x-filament::dropdown.list.item>
                    </x-filament::dropdown.list>
                </x-filament::dropdown>
            </div>
        @endif

        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item icon="lucide-plus" class="fi-ta-views-save-current" wire:click="mountAction('saveSavedViewAs')">
                {{ __('filament/panel.tables.views.save_current') }}
            </x-filament::dropdown.list.item>
        </x-filament::dropdown.list>
        </div>
    </x-filament::dropdown>

    @if ($isModified)
        <x-filament::button
            color="gray"
            size="sm"
            icon="lucide-undo-2"
            label-sr-only
            :tooltip="__('filament/panel.tables.views.revert')"
            wire:click="revertSavedView"
        >
            {{ __('filament/panel.tables.views.revert') }}
        </x-filament::button>

        @if ($canUpdate)
            <x-filament::button
                color="gray"
                size="sm"
                icon="lucide-check"
                label-sr-only
                :tooltip="__('filament/panel.tables.views.save')"
                class="fi-ta-views-save"
                wire:click="mountAction('saveSavedView')"
            >
                {{ __('filament/panel.tables.views.save') }}
            </x-filament::button>
        @endif

        <x-filament::button
            color="gray"
            size="sm"
            icon="lucide-plus"
            label-sr-only
            :tooltip="__('filament/panel.tables.views.save_as')"
            class="fi-ta-views-save-as"
            wire:click="mountAction('saveSavedViewAs')"
        >
            {{ __('filament/panel.tables.views.save_as') }}
        </x-filament::button>
    @endif
</div>
