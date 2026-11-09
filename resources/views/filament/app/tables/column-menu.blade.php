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
        aria-label="{{ __('filament/panel.tables.column_menu.options') }}"
        x-cloak
        x-bind:class="{ 'hidden': column === null }"
        x-bind:style="{ top: `${top}px`, right: `${right}px` }"
        x-on:keydown.escape.prevent.stop="close(true)"
        x-on:keydown.arrow-down.prevent="step(1)"
        x-on:keydown.arrow-up.prevent="step(-1)"
        x-on:keydown.home.prevent="items()[0]?.focus()"
        x-on:keydown.end.prevent="items().at(-1)?.focus()"
        x-on:focusout="$event.relatedTarget && ! $el.contains($event.relatedTarget) && ! $event.relatedTarget.closest('.fi-ta-column-handle') && close(false)"
        x-on:click.outside="$event.target.closest('.fi-ta-column-handle') || close(false)"
        class="fi-dropdown-panel fi-ta-column-menu"
    >
        <div class="fi-dropdown-list" x-show="movable">
            <x-filament::dropdown.list.item
                icon="lucide-arrow-left"
                data-column-menu-item="moveLeft"
                x-bind:disabled="! canMoveLeft"
                x-bind:aria-disabled="(! canMoveLeft).toString()"
                x-bind:class="{ 'fi-disabled': ! canMoveLeft }"
                x-on:click="choose(() => $wire.moveTableColumn(column, before.left), column)"
            >
                {{ __('filament/panel.tables.column_menu.move_left') }}
            </x-filament::dropdown.list.item>
            <x-filament::dropdown.list.item
                icon="lucide-arrow-right"
                data-column-menu-item="moveRight"
                x-bind:disabled="! canMoveRight"
                x-bind:aria-disabled="(! canMoveRight).toString()"
                x-bind:class="{ 'fi-disabled': ! canMoveRight }"
                x-on:click="choose(() => $wire.moveTableColumn(column, before.right), column)"
            >
                {{ __('filament/panel.tables.column_menu.move_right') }}
            </x-filament::dropdown.list.item>
        </div>
        <div class="fi-dropdown-list">
            <x-filament::dropdown.list.item
                icon="lucide-pin"
                data-column-menu-item="pin"
                x-show="! pinned"
                x-on:click="choose(() => $wire.pinTableColumn(column), column)"
            >
                {{ __('filament/panel.tables.column_menu.pin') }}
            </x-filament::dropdown.list.item>
            <x-filament::dropdown.list.item
                icon="lucide-pin-off"
                data-column-menu-item="unpin"
                x-show="pinned"
                x-on:click="choose(() => $wire.unpinTableColumn(column), column)"
            >
                {{ __('filament/panel.tables.column_menu.unpin') }}
            </x-filament::dropdown.list.item>
        </div>
        <div class="fi-dropdown-list">
            <x-filament::dropdown.list.item
                icon="lucide-pencil"
                data-column-menu-item="editLabel"
                x-on:click="choose(() => $wire.mountAction('editTableColumnLabel', { column }), null)"
            >
                {{ __('filament/panel.tables.column_menu.edit_label') }}
            </x-filament::dropdown.list.item>
            <x-filament::dropdown.list.item
                icon="lucide-eye-off"
                data-column-menu-item="hide"
                x-show="hideable"
                x-on:click="choose(() => $wire.hideTableColumn(column), neighbour)"
            >
                {{ __('filament/panel.tables.column_menu.hide') }}
            </x-filament::dropdown.list.item>
        </div>
    </div>
</div>
