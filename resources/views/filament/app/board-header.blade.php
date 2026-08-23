<div class="fi-board-header">
    <template x-teleport=".fi-topbar-start">
        <div
            data-page-heading
            class="fi-topbar-page-heading"
            title="{{ str(strip_tags((string) $heading))->squish() }}"
        >
            <h1 class="fi-topbar-page-title">{{ $heading }}</h1>

            @if (filled($headingEnd))
                {{ $headingEnd }}
            @endif
        </div>
    </template>

    {!! $boardToolbar !!}
</div>
