@php
    $chips = $getChips();
    $url = $getUrl();
@endphp

<div class="flex flex-wrap items-center gap-x-2 gap-y-1 px-3 py-2 text-sm text-gray-950 dark:text-white">
    @forelse ($chips as $chip)
        @if (filled($url))
            {{-- Filament wraps a cell with a URL in its own link, and a nested anchor gets pulled out of the cell. --}}
            <span class="text-primary-600 hover:underline dark:text-primary-400">{{ $chip }}</span>
        @else
            {{ $chip }}
        @endif
    @empty
        <span class="text-gray-400 dark:text-gray-500">{{ $getPlaceholder() }}</span>
    @endforelse
</div>
