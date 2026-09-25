@php
    $state = $getState();
    $placeholder = $getPlaceholder();
    $items = match (true) {
        is_array($state) && array_key_exists('records', $state) => $state['records'],
        is_array($state) && array_key_exists('selected', $state) => $state['selected'],
        default => \Illuminate\Support\Arr::wrap($state),
    };
    $isEmpty = collect($items)->filter(function (mixed $item): bool {
        if (is_array($item)) {
            return filled($item['number'] ?? null)
                || filled($item['name'] ?? null)
                || filled($item['url'] ?? null)
                || filled($item['display'] ?? null);
        }

        return $item !== null && $item !== '';
    })->isEmpty();
@endphp

@if ($isEmpty && filled($placeholder))
    <x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
        <p class="fi-in-placeholder">{{ $placeholder }}</p>
    </x-dynamic-component>
@elseif (isset($vendorView) && is_string($vendorView) && view()->exists($vendorView))
    @include($vendorView)
@endif
