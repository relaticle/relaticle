<nav class="mb-8" aria-label="Progress">
    <ol role="list" class="flex items-center gap-2">
        @foreach ([1 => 'Upload', 2 => 'Map', 3 => 'Review', 4 => 'Import'] as $step => $label)
            @php
                $isClickable = $step < $currentStep;
            @endphp
            <li class="flex items-center">
                <button
                    type="button"
                    @class([
                        'flex items-center gap-2 text-sm transition-colors',
                        'cursor-pointer hover:opacity-80' => $isClickable,
                        'cursor-default' => !$isClickable,
                    ])
                    @disabled(!$isClickable)
                >
                    <span @class([
                        'inline-flex items-center justify-center h-5 w-5 rounded text-xs font-medium',
                        'bg-primary-600 text-white' => $currentStep === $step,
                        'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $currentStep !== $step,
                    ])>{{ $step }}</span>
                    <span @class([
                        'text-gray-950 dark:text-white' => $currentStep === $step,
                        'text-gray-500 dark:text-gray-400' => $currentStep !== $step,
                    ])>{{ $label }}</span>
                </button>
                @if ($step < 4)
                    <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4 text-gray-300 dark:text-gray-600 mx-2" />
                @endif
            </li>
        @endforeach
    </ol>
</nav>
