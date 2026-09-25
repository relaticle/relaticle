@php
    $state = $getState();
    $items = collect(is_array($state) ? $state : [])
        ->filter(fn (mixed $item): bool => is_array($item) && filled($item['display'] ?? null))
        ->values();
    $isChipList = $items->every(
        fn (array $item): bool => blank($item['href'] ?? null) && blank($item['copy'] ?? null)
    );
    $first = $items->first();
@endphp

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @if ($first === null)
        <p class="fi-in-placeholder">{{ $getPlaceholder() }}</p>
    @else
        <div
            x-data="multiValueOverflow(@js(__('filament/inline-edit.copied_value')))"
            @class([
                'fi-multi-value-entry',
                'fi-multi-value-chips' => $isChipList,
            ])
        >
            <div x-ref="announcer" aria-live="polite" aria-atomic="true" class="sr-only"></div>

            @foreach ($items as $index => $item)
                @include('filament.infolists.components.partials.multi-value-item', [
                    'item' => $item,
                    'index' => $index,
                    'showCopy' => $items->count() === 1,
                ])
            @endforeach

            @if ($items->count() > 1)
                <span
                    x-ref="more"
                    class="fi-multi-value-more shrink-0 text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap"
                    hidden
                >+<span x-text="hiddenCount"></span></span>
            @endif
        </div>
    @endif
</x-dynamic-component>
