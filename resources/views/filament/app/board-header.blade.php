<div class="fi-board-header">
    <div class="fi-board-header-row">
        @include('filament.app.topbar-page-heading', ['heading' => $heading])

        @if (filled($viewSwitcher))
            <div class="fi-view-controls">
                {{ $viewSwitcher }}
                {{ $viewSettings }}
            </div>
        @endif

        <span x-data="boardStageOrder" hidden></span>
    </div>

    @include('filament.app.tables.filter-bar')
</div>
