<div class="fi-board-header" data-board-column-width="{{ $columnWidth->value }}" data-board-card-size="{{ $cardSize->value }}">
    <div class="fi-board-header-row">
        @include('filament.app.topbar-page-heading', ['heading' => $heading])

        @if (filled($viewSwitcher))
            <div class="fi-view-controls">
                {{ $viewSwitcher }}
                {{ $viewSettings }}
            </div>
        @endif

        <div class="fi-board-header-actions">
            <x-filament::actions :actions="$actions" />
        </div>

        <span x-data="boardStageOrder" data-board-no-value-column="{{ \App\Data\BoardStateData::NO_VALUE_COLUMN }}" hidden></span>
    </div>

    <div class="fi-board-bar-row">
        @include('filament.app.tables.filter-bar')

        @if (filled($layoutToggles))
            {{ $layoutToggles }}
        @endif
    </div>
</div>
