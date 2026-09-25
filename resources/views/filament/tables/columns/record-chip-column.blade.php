@php
    $chips = $getChips();
    $url = $getUrl();
@endphp

<div class="flex flex-wrap items-center gap-x-2 gap-y-1 px-3 py-2 text-sm text-gray-950 dark:text-white">
    @forelse ($chips as $chip)
        @if (filled($url))
            <a
                href="{{ $url }}"
                @if ($shouldOpenUrlInNewTab()) target="_blank" @endif
                class="text-primary-600 hover:underline dark:text-primary-400"
            >
                {{ $chip }}
            </a>
        @else
            {{ $chip }}
        @endif
    @empty
        <span class="text-gray-400 dark:text-gray-500">{{ $getPlaceholder() }}</span>
    @endforelse
</div>
