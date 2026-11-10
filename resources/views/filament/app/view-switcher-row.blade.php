<span class="flex items-center justify-between gap-3">
    <span class="flex min-w-0 items-center gap-2">
        <span class="fi-view-tile fi-view-tile-primary"><x-filament::icon icon="lucide-table-2" /></span>
        <span class="min-w-0 truncate" title="{{ $row['name'] }}">{{ $row['name'] }}</span>

        @if ($row['isDefault'])
            <span class="fi-ta-views-tag">{{ __('filament/panel.tables.views.default') }}</span>
        @endif
    </span>

    <span class="fi-ta-views-row-meta">
        @if ($row['owner'] !== null)
            <span class="truncate">{{ $row['owner'] }}</span>
        @elseif ($row['isShared'])
            <span>{{ __('filament/panel.tables.views.shared_label') }}</span>
        @endif
    </span>
</span>
