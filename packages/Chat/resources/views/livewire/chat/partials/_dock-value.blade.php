{{-- The value half of one display row, shared by the single-record field grid and the plan line pairs.

     Expects: $row, $stack (old above new, for a pair longer than 28 characters), $clampable (long text gets two lines and "more"),
     $refLinks (the plan steps this value links to, drawn as "new" chips). --}}
@php
    $stack = $stack ?? false;
    $clampable = $clampable ?? false;
    $refLinks = $refLinks ?? [];
    $newValue = $row['new'] ?? $row['value'] ?? null;
    $oldValue = $row['old'] ?? null;
    $hasOld = ! in_array($oldValue, [null, ''], true);
    $hasNew = ! in_array($newValue, [null, ''], true);
    $values = is_array($row['values'] ?? null) ? $row['values'] : [];
    $type = $row['type'] ?? null;
    $isPlainText = $values === [] && ! in_array($type, ['boolean', 'link', 'badges'], true);
    $isLong = $clampable && $isPlainText && $hasNew && (mb_strlen((string) $newValue) > 48 || str_contains((string) $newValue, "\n"));
    $stacked = $stack && $hasOld && ($hasNew || $values !== []);
@endphp
@if ($hasOld || $hasNew || $values !== [])
    <span @class([
        'flex min-w-0 gap-x-1.5 gap-y-0.5',
        'flex-col items-start' => $stacked,
        'flex-wrap items-center' => ! $stacked,
    ])>
        @if ($hasOld)
            <span class="text-gray-400 line-through decoration-gray-300 dark:text-gray-500 dark:decoration-gray-600">{{ $oldValue }}</span>
            @if (! $stacked && ($hasNew || $values !== []))
                <x-heroicon-m-arrow-right class="h-3 w-3 text-gray-400 dark:text-gray-500" aria-hidden="true" />
            @endif
        @endif

        @if ($values !== [])
            @if ($type === 'link')
                <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
                    @foreach ($values as $url)
                        <a
                            href="{{ (str_starts_with((string) $url, 'http') ? '' : 'https://').$url }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="min-w-0 truncate text-primary-600 hover:underline dark:text-primary-400"
                        >{{ $url }}</a>
                    @endforeach
                </span>
            @else
                @foreach ($values as $badge)
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[length:var(--text-micro)] font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300">{{ $badge }}</span>
                @endforeach
            @endif
        @elseif ($type === 'boolean' && $hasNew)
            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[length:var(--text-micro)] font-medium text-gray-600 dark:bg-white/10 dark:text-gray-400">{{ $newValue }}</span>
        @elseif ($hasNew)
            @if (is_string($row['icon'] ?? null) && $row['icon'] !== '')
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="size-4 shrink-0 text-gray-400 dark:text-gray-500" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $row['icon'] }}"></path>
                </svg>
            @endif

            @if ($isLong)
                <span
                    class="min-w-0"
                    x-data="{ open: false, overflows: false }"
                    x-init="$nextTick(() => overflows = $refs.text.scrollHeight > $refs.text.clientHeight + 1)"
                >
                    <span
                        x-ref="text"
                        class="line-clamp-2 whitespace-pre-wrap break-words text-sm text-gray-900 dark:text-white"
                        x-bind:class="open && '!line-clamp-none'"
                    >{{ $newValue }}</span>
                    <button
                        type="button"
                        x-show="overflows || open"
                        x-cloak
                        x-on:click="open = ! open"
                        x-bind:aria-expanded="open"
                        class="text-xs font-medium text-primary-600 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:text-primary-400"
                    >
                        <span x-show="! open">{{ __('more') }}</span>
                        <span x-show="open" x-cloak>{{ __('less') }}</span>
                    </button>
                </span>
            @else
                @php($parts = \Relaticle\Chat\Support\RecordNameResolver::chipParts((string) $newValue, $refLinks))
                @foreach ($parts as $part)
                    @if ($part['isNew'])
                        <span class="inline-flex max-w-full items-center gap-1 rounded-md bg-primary-50 px-1.5 text-xs font-medium leading-5 text-primary-700 dark:bg-primary-400/10 dark:text-primary-300" data-proposal-ref>
                            <span class="truncate">{{ $part['text'] }}</span>
                            <span class="font-normal opacity-80">{{ __('new') }}</span>
                        </span>
                    @else
                        <span @class([
                            'min-w-0 break-words text-sm text-gray-900 dark:text-white',
                            'whitespace-pre-wrap' => str_contains($part['text'], "\n"),
                        ])>{{ $part['text'] }}</span>
                    @endif
                @endforeach
            @endif
        @endif
    </span>
@endif
