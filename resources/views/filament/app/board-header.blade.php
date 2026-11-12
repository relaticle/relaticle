<div class="fi-board-header">
    @include('filament.app.topbar-page-heading', ['heading' => $heading])

    @if (filled($viewSwitcher))
        <div class="fi-view-controls">
            {{ $viewSwitcher }}
            {{ $viewSettings }}
        </div>
    @endif

    <span x-data="boardStageOrder" hidden></span>

    {!! $boardToolbar !!}
</div>
