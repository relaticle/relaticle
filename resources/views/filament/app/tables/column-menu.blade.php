@php
    $items = [
        ['group' => 'move', 'label' => __('filament/panel.tables.column_menu.move_left'), 'icon' => 'heroicon-m-arrow-left', 'run' => '$wire.moveTableColumn(column, before.left)', 'disabled' => '! canMoveLeft', 'show' => 'movable', 'focus' => 'column'],
        ['group' => 'move', 'label' => __('filament/panel.tables.column_menu.move_right'), 'icon' => 'heroicon-m-arrow-right', 'run' => '$wire.moveTableColumn(column, before.right)', 'disabled' => '! canMoveRight', 'show' => 'movable', 'focus' => 'column'],
        ['group' => 'pin', 'label' => __('filament/panel.tables.column_menu.pin'), 'icon' => 'heroicon-m-lock-closed', 'run' => '$wire.pinTableColumn(column)', 'visible' => '! pinned', 'focus' => 'column'],
        ['group' => 'pin', 'label' => __('filament/panel.tables.column_menu.unpin'), 'icon' => 'heroicon-m-lock-open', 'run' => '$wire.unpinTableColumn(column)', 'visible' => 'pinned', 'focus' => 'column'],
        ['group' => 'manage', 'label' => __('filament/panel.tables.column_menu.edit_label'), 'icon' => 'heroicon-o-tag', 'run' => "\$wire.mountAction('editTableColumnLabel', { column })", 'focus' => 'null'],
        ['group' => 'manage', 'label' => __('filament/panel.tables.column_menu.hide'), 'icon' => 'heroicon-o-eye-slash', 'run' => '$wire.hideTableColumn(column)', 'visible' => 'hideable', 'focus' => 'neighbour'],
    ];
@endphp

<div
    x-data="tableColumnMenu"
    x-on:column-menu-open.window="open($event.detail)"
    x-on:resize.window="close(false)"
    x-on:scroll.window.capture="close(false)"
    x-on:livewire:navigating.window="close(false)"
    class="contents" wire:ignore wire:key="{{ $this->getId() }}.table.column-menu">
    <div
        x-init="panel = $el"
        role="menu"
        x-cloak
        x-bind:class="{ 'hidden': column === null }"
        x-bind:style="{ top: `${top}px`, right: `${right}px` }"
        x-on:keydown.escape.prevent.stop="close(true)"
        x-on:keydown.arrow-down.prevent="step(1)"
        x-on:keydown.arrow-up.prevent="step(-1)"
        x-on:click.outside="$event.target.closest('.fi-ta-column-handle') || close(false)"
        class="fi-dropdown-panel fi-ta-column-menu"
    >
        @foreach (collect($items)->groupBy('group') as $group)
            <div class="fi-dropdown-list" @isset($group->first()['show']) x-bind:style="{ display: {{ $group->first()['show'] }} ? null : 'none' }" @endisset>
                @foreach ($group as $item)
                    <button
                        type="button"
                        role="menuitem"
                        data-label="{{ $item['label'] }}"
                        @isset($item['visible'])
                            x-bind:style="{ display: {{ $item['visible'] }} ? null : 'none' }"
                        @endisset
                        @isset($item['disabled'])
                            x-bind:disabled="{{ $item['disabled'] }}"
                            x-bind:aria-disabled="({{ $item['disabled'] }}).toString()"
                            x-bind:class="{ 'fi-disabled': {{ $item['disabled'] }} }"
                        @endisset
                        x-on:click="choose(() => {{ $item['run'] }}, {{ $item['focus'] }})"
                        class="fi-dropdown-list-item"
                    >
                        <x-filament::icon :icon="$item['icon']" class="fi-dropdown-list-item-icon" />
                        <span class="fi-dropdown-list-item-label">{{ $item['label'] }}</span>
                    </button>
                @endforeach
            </div>
        @endforeach
    </div>
</div>
