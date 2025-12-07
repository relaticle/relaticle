@php
    $firstColumn = $this->columnAnalyses->first()?->mappedToField;
@endphp

<div
    class="space-y-6"
    x-data="{ activeColumn: '{{ $expandedColumn ?? $firstColumn }}' }"
>
    <div class="flex gap-6">
        {{-- Columns List --}}
        <div class="w-56 shrink-0 space-y-1">
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider px-1 mb-2">Columns</div>
            @foreach ($this->columnAnalyses as $analysis)
                <button
                    type="button"
                    wire:click="toggleColumn('{{ $analysis->mappedToField }}')"
                    wire:key="col-{{ $analysis->mappedToField }}"
                    x-on:mouseenter="activeColumn = '{{ $analysis->mappedToField }}'"
                    @class([
                        'w-full text-left px-2.5 py-2 rounded-lg transition-colors',
                        'bg-primary-50 dark:bg-primary-950 text-primary-700 dark:text-primary-300' => $expandedColumn === $analysis->mappedToField,
                        'hover:bg-gray-50 dark:hover:bg-gray-800' => $expandedColumn !== $analysis->mappedToField,
                    ])
                >
                    <div class="text-sm text-gray-950 dark:text-white">{{ $analysis->csvColumnName }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $analysis->mappedToField }}</div>
                </button>
            @endforeach
        </div>

        {{-- Values Panel --}}
        <div class="flex-1 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden flex flex-col">
            @php
                $selectedAnalysis = $expandedColumn
                    ? $this->columnAnalyses->firstWhere('mappedToField', $expandedColumn)
                    : $this->columnAnalyses->first();
            @endphp

            @if ($selectedAnalysis)
                {{-- Column Header --}}
                <div class="flex items-center px-3 py-2 border-b border-gray-200 dark:border-gray-700 text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    <div class="flex-1">Raw data</div>
                    <div class="w-8"></div>
                    <div class="flex-1">Mapped value</div>
                    <div class="w-10"></div>
                </div>

                {{-- Values List --}}
                <div class="overflow-y-auto flex-1">
                    @forelse ($selectedAnalysis->paginatedValues($reviewPage, 50, $reviewSearch) as $value => $count)
                        @php
                            $isSkipped = $this->isValueSkipped($selectedAnalysis->mappedToField, $value);
                            $hasCorrection = $this->hasCorrectionForValue($selectedAnalysis->mappedToField, $value);
                            $displayValue = $value ?: '(blank)';
                            $mappedValue = $hasCorrection ? $this->getCorrectedValue($selectedAnalysis->mappedToField, $value) : $displayValue;
                        @endphp
                        <div
                            wire:key="val-{{ md5($selectedAnalysis->mappedToField . $value) }}"
                            class="flex items-center px-3 py-2 border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50"
                        >
                            {{-- Raw Value --}}
                            <div class="flex-1 flex items-center gap-2 min-w-0">
                                <span @class([
                                    'text-sm truncate',
                                    'text-gray-400 line-through' => $isSkipped,
                                    'text-gray-950 dark:text-white' => !$isSkipped,
                                ])>{{ $displayValue }}</span>
                                <span class="text-xs text-gray-400 shrink-0">{{ $count }}</span>
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
                                    <input
                                        type="text"
                                        value="{{ $mappedValue }}"
                                        x-on:blur="if ($event.target.value !== '{{ addslashes($mappedValue) }}') $wire.correctValue('{{ $selectedAnalysis->mappedToField }}', '{{ addslashes($value) }}', $event.target.value)"
                                        x-on:keydown.enter="$event.target.blur()"
                                        @class([
                                            'w-full px-2 py-1 text-sm rounded border focus:outline-none focus:ring-1 focus:ring-primary-500',
                                            'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800' => !$hasCorrection,
                                            'border-success-300 dark:border-success-700 bg-success-50 dark:bg-success-950' => $hasCorrection,
                                        ])
                                    />
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
                    @empty
                        <div class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            No values found
                        </div>
                    @endforelse
                </div>
            @else
                <div class="flex-1 flex items-center justify-center text-sm text-gray-500 dark:text-gray-400">
                    Select a column to review values
                </div>
            @endif
        </div>
    </div>

    {{-- Navigation --}}
    <div class="flex justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
        <x-filament::button wire:click="previousStep" color="gray">Back</x-filament::button>
        <x-filament::button wire:click="nextStep">Continue</x-filament::button>
    </div>
</div>
