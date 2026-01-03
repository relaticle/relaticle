@php
    $isSkipped = $this->isValueSkipped($selectedAnalysis->mappedToField, $value);
    $hasCorrection = $this->hasCorrectionForValue($selectedAnalysis->mappedToField, $value);
    $displayValue = $value !== '' ? $value : '(blank)';
    $mappedValue = $hasCorrection ? $this->getCorrectedValue($selectedAnalysis->mappedToField, $value) : $displayValue;
    $valueIssue = $selectedAnalysis->getIssueForValue($value);
    $hasError = $valueIssue !== null && $valueIssue->severity === 'error' && !$isSkipped;
    $hasWarning = $valueIssue !== null && $valueIssue->severity === 'warning' && !$isSkipped;
    $isAmbiguous = $valueIssue?->isDateAmbiguous() ?? false;

    // For date fields, show parsed preview
    $parsedDatePreview = null;
    if ($isDateField && $effectiveDateFormat !== null && $value !== '' && !$hasError) {
        $parsed = $effectiveDateFormat->parse($value);
        if ($parsed !== null) {
            $parsedDatePreview = $parsed->format('M j, Y');
        }
    }
@endphp
<div
    wire:key="val-{{ md5($selectedAnalysis->mappedToField . $value) }}"
    @class([
        'px-3 py-2 border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50',
        'bg-warning-50/50 dark:bg-warning-950/30' => $hasWarning && !$isSkipped,
    ])
>
    <div class="flex items-center">
        {{-- Raw Value --}}
        <div class="flex-1 flex items-center gap-2 min-w-0">
            <span @class([
                'text-sm truncate',
                'text-gray-400 line-through' => $isSkipped,
                'text-gray-950 dark:text-white' => !$isSkipped,
            ])>{{ $displayValue }}</span>
            <span class="text-xs text-gray-400 shrink-0">{{ $count }}×</span>
        </div>

        {{-- Arrow --}}
        <div class="w-8 flex justify-center">
            <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5 text-gray-300 dark:text-gray-600" />
        </div>

        {{-- Mapped Value / Input --}}
        <div class="flex-1 min-w-0">
            @if ($isSkipped)
                <span class="text-sm text-gray-400 italic">Skipped</span>
            @else
                <div class="flex items-center gap-2">
                    <input
                        type="text"
                        value="{{ $mappedValue }}"
                        x-on:blur="if ($event.target.value !== '{{ addslashes($mappedValue) }}') $wire.correctValue('{{ $selectedAnalysis->mappedToField }}', '{{ addslashes($value) }}', $event.target.value)"
                        x-on:keydown.enter="$event.target.blur()"
                        @class([
                            'w-full px-2 py-1 text-sm rounded border focus:outline-none focus:ring-1 focus:ring-primary-500',
                            'border-success-300 dark:border-success-700 bg-success-50 dark:bg-success-950' => $hasCorrection && !$hasError,
                            'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800' => !$hasCorrection || $hasError,
                        ])
                    />
                    {{-- Show parsed date preview for date fields --}}
                    @if ($isDateField && $parsedDatePreview !== null)
                        <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">→ {{ $parsedDatePreview }}</span>
                        @if ($isAmbiguous)
                            <span class="text-xs text-warning-600 dark:text-warning-400" title="Ambiguous date">
                                <x-filament::icon icon="heroicon-m-question-mark-circle" class="h-4 w-4" />
                            </span>
                        @endif
                    @endif
                </div>
            @endif
        </div>

        {{-- Skip Button --}}
        <div class="w-10 flex justify-end">
            <button
                type="button"
                wire:click="skipValue('{{ $selectedAnalysis->mappedToField }}', '{{ addslashes($value) }}')"
                title="{{ $isSkipped ? 'Unskip' : 'Skip' }}"
                @class([
                    'p-1 rounded transition-colors',
                    'text-gray-400 hover:text-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700' => !$isSkipped,
                    'text-primary-600 bg-primary-100 dark:bg-primary-900' => $isSkipped,
                ])
            >
                <x-filament::icon icon="heroicon-o-no-symbol" class="h-4 w-4" />
            </button>
        </div>
    </div>

    {{-- Validation Error Message --}}
    @if ($hasError)
        <div class="mt-1 ml-0 text-xs text-danger-600 dark:text-danger-400">
            <x-filament::icon icon="heroicon-m-exclamation-circle" class="h-3.5 w-3.5 inline-block -mt-0.5 mr-0.5" />
            {{ $valueIssue->message }}
        </div>
    @elseif ($hasWarning)
        <div class="mt-1 ml-0 text-xs text-warning-600 dark:text-warning-400">
            <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-3.5 w-3.5 inline-block -mt-0.5 mr-0.5" />
            {{ $valueIssue->message }}
        </div>
    @endif
</div>
