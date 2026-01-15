<nav aria-label="Progress">
    <ol role="list" class="flex items-center">
        @foreach ([1 => 'Upload', 2 => 'Map', 3 => 'Review', 4 => 'Import'] as $step => $label)
            <li class="relative {{ $step < 4 ? 'pr-8 sm:pr-20 flex-1' : '' }}">
                @if ($step < 4)
                    <div class="absolute inset-0 flex items-center" aria-hidden="true">
                        <div class="h-0.5 w-full {{ $currentStep > $step ? 'bg-primary-600' : 'bg-gray-200 dark:bg-gray-700' }}"></div>
                    </div>
                @endif
                <div
                    @class([
                        'relative flex h-8 w-8 items-center justify-center rounded-full transition-colors',
                        'bg-primary-600 text-white' => $currentStep > $step,
                        'border-2 border-primary-600 bg-white dark:bg-gray-900 text-primary-600' => $currentStep === $step,
                        'border-2 border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-500' => $currentStep < $step,
                    ])
                >
                    @if ($currentStep > $step)
                        <x-heroicon-s-check class="h-5 w-5" />
                    @else
                        <span class="text-sm font-medium">{{ $step }}</span>
                    @endif
                </div>
                <span class="absolute -bottom-6 left-1/2 -translate-x-1/2 text-xs font-medium {{ $currentStep >= $step ? 'text-primary-600' : 'text-gray-500' }}">
                    {{ $label }}
                </span>
            </li>
        @endforeach
    </ol>
</nav>
