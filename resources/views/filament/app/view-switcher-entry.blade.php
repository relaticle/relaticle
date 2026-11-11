<span class="flex min-w-0 items-center gap-2">
    <span class="fi-view-tile fi-view-tile-{{ $entry['tone'] }}"><x-filament::icon :icon="$entry['icon']" /></span>
    <span class="min-w-0 truncate" title="{{ $entry['label'] }}">{{ $entry['label'] }}</span>
</span>
