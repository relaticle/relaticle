<nav class="mb-8" aria-label="Progress">
    <ol role="list" class="flex items-center gap-2">
        @foreach ([1 => 'Upload', 2 => 'Map', 3 => 'Review', 4 => 'Import'] as $step => $label)
            @php
                $isCompleted = $step < $currentStep;
                $isCurrent = $step === $currentStep;
            @endphp
            <li class="flex items-center">
                @if ($isCompleted)
                    <button
                        type="button"
                        wire:click="goToStep({{ $step }})"
                        class="flex items-center gap-2 text-sm transition-colors cursor-pointer hover:opacity-80"
                    >
                        <span class="inline-flex items-center justify-center h-5 w-5 rounded text-xs font-medium bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ $step }}</span>
                        <span class="text-gray-500 dark:text-gray-400">{{ $label }}</span>
                    </button>
                @else
                    <span class="flex items-center gap-2 text-sm">
                        <span @class([
                            'inline-flex items-center justify-center h-5 w-5 rounded text-xs font-medium',
                            'bg-primary-600 text-white' => $isCurrent,
                            'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => !$isCurrent,
                        ])>{{ $step }}</span>
                        <span @class([
                            'text-gray-950 dark:text-white' => $isCurrent,
                            'text-gray-500 dark:text-gray-400' => !$isCurrent,
                        ])>{{ $label }}</span>
                    </span>
                @endif
                @if ($step < 4)
                    <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4 text-gray-300 dark:text-gray-600 mx-2" />
                @endif
            </li>
        @endforeach
    </ol>
</nav>
