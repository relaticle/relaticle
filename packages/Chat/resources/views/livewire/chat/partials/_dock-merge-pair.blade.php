{{-- Expects: $pair (secondary and primary, each {name, url}). --}}
@php
    $segments = preg_split('/(:secondary|:primary)/', __('enums/proposal-entity.merge_pair'), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
@endphp

<span class="flex min-w-0 items-center gap-1" data-proposal-merge-pair>
    @foreach ($segments as $segment)
        @if (in_array($segment, [':secondary', ':primary'], true))
            @php($record = $pair[ltrim($segment, ':')])
            <span class="min-w-0 truncate">{{ $record['name'] }}</span>
            <a
                href="{{ $record['url'] }}"
                target="_blank"
                rel="noopener"
                data-proposal-merge-record-link
                class="pointer-events-auto inline-flex size-6 shrink-0 items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:text-gray-500 dark:hover:bg-white/10 dark:hover:text-gray-200"
                title="{{ __('Open :name in a new tab', ['name' => $record['name']]) }}"
                aria-label="{{ __('Open :name in a new tab', ['name' => $record['name']]) }}"
            >
                <x-lucide-external-link class="size-3.5" aria-hidden="true" />
            </a>
        @elseif (trim($segment) !== '')
            <span class="shrink-0 font-normal text-gray-500 dark:text-gray-400">{{ trim($segment) }}</span>
        @endif
    @endforeach
</span>
