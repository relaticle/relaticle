@php
    $currentIndex = collect($stages)->search(fn (array $stage): bool => $stage['current']);
    $current = $currentIndex === false ? null : $stages[$currentIndex];
@endphp

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    <div class="fi-record-stage">
        @if ($current)
            <span class="fi-record-stage-name">{{ $current['name'] }}</span>
        @else
            <span class="fi-record-highlight-empty">{{ __('filament/record-page.empty') }}</span>
        @endif

        <ol class="fi-record-stage-track" aria-label="{{ $current['name'] ?? __('filament/record-page.empty') }}">
            @foreach ($stages as $index => $stage)
                <li
                    x-tooltip="{ content: @js($stage['name']), theme: $store.theme }"
                    @class([
                        'fi-record-stage-step',
                        'fi-passed' => $currentIndex !== false && $index < $currentIndex,
                        'fi-current' => $currentIndex === $index,
                    ])
                    style="--stage-color: {{ $stage['color'] ?? 'var(--color-gray-400)' }}"
                ><span class="sr-only">{{ $stage['name'] }}</span></li>
            @endforeach
        </ol>
    </div>
</x-dynamic-component>
