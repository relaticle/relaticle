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
        @foreach ($this->columnMenuActions() as $actions)
            <div class="fi-dropdown-list">
                @foreach ($actions as $action)
                    {{ $action }}
                @endforeach
            </div>
        @endforeach
    </div>
</div>
