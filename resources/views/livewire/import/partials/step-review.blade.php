<div class="space-y-6">
    <div class="flex gap-6 min-h-[500px]">
        {{-- Columns List --}}
        <div class="w-72 shrink-0 space-y-2">
            <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 px-1">Columns</h3>
            @foreach ($this->columnAnalyses as $analysis)
                <button
                    type="button"
                    wire:click="toggleColumn('{{ $analysis->mappedToField }}')"
                    wire:key="col-{{ $analysis->mappedToField }}"
                    @class([
                        'w-full text-left p-3 rounded-lg border transition-colors',
                        'border-primary-500 bg-primary-50 dark:bg-primary-950' => $expandedColumn === $analysis->mappedToField,
                        'border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800' => $expandedColumn !== $analysis->mappedToField,
                    ])
                >
                    <div class="flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-table-cells" class="h-4 w-4 text-gray-400" />
                        <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $analysis->csvColumnName }}</span>
                    </div>
                    <div class="flex items-center gap-1.5 mt-1 ml-6">
                        <x-filament::icon icon="heroicon-o-squares-2x2" class="h-3 w-3 text-gray-400" />
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $analysis->mappedToField }}</span>
                    </div>
                </button>
            @endforeach
        </div>

        {{-- Values Panel --}}
        <div class="flex-1 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden flex flex-col">
            @php
                $selectedAnalysis = $expandedColumn
                    ? $this->columnAnalyses->firstWhere('mappedToField', $expandedColumn)
                    : $this->columnAnalyses->first();
            @endphp

            @if ($selectedAnalysis)
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 shrink-0">
                    <div class="flex items-center gap-2 text-sm">
                        <x-filament::icon icon="heroicon-o-adjustments-horizontal" class="h-4 w-4 text-gray-400" />
                        <span class="text-gray-600 dark:text-gray-300">Sorted by</span>
                        <span class="font-medium text-gray-950 dark:text-white">Raw value</span>
                    </div>
                </div>

                <div class="flex items-center px-4 py-2 border-b border-gray-200 dark:border-gray-700 text-xs text-gray-500 dark:text-gray-400 shrink-0">
                    <div class="flex-1">Raw data</div>
                    <div class="flex-1 pl-8">Mapped value</div>
                </div>

                <div class="px-4 py-2 border-b border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-900/30 shrink-0">
                    <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                        <x-filament::icon icon="heroicon-m-chevron-down" class="h-3 w-3" />
                        <span>Automatically mapped</span>
                        <span class="font-medium">{{ $selectedAnalysis->uniqueCount }}</span>
                    </div>
                </div>

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
                            class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50"
                        >
                            <div class="flex items-center">
                                <div class="flex-1 flex items-center gap-2 min-w-0">
                                    <span @class([
                                        'text-sm font-medium truncate',
                                        'text-gray-400 line-through' => $isSkipped,
                                        'text-gray-950 dark:text-white' => !$isSkipped,
                                    ])>{{ $displayValue }}</span>
                                    <span class="text-xs text-gray-400 shrink-0">{{ $count }} {{ $count === 1 ? 'row' : 'rows' }}</span>
                                </div>

                                <x-filament::icon icon="heroicon-m-arrow-right" class="mx-3 h-4 w-4 text-gray-300 dark:text-gray-600 shrink-0" />

                                <div class="flex-1 flex items-center justify-between min-w-0">
                                    @if ($isSkipped)
                                        <span class="text-sm text-gray-400 italic">Skipped</span>
                                    @else
                                        <input
                                            type="text"
                                            value="{{ $mappedValue }}"
                                            x-on:blur="if ($event.target.value !== '{{ addslashes($mappedValue) }}') $wire.correctValue('{{ $selectedAnalysis->mappedToField }}', '{{ addslashes($value) }}', $event.target.value)"
                                            x-on:keydown.enter="$event.target.blur()"
                                            @class([
                                                'px-3 py-1.5 text-sm rounded-lg border bg-gray-50 dark:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500',
                                                'border-gray-200 dark:border-gray-700' => !$hasCorrection,
                                                'border-success-300 dark:border-success-700 bg-success-50 dark:bg-success-950' => $hasCorrection,
                                            ])
                                        />
                                    @endif

                                    <button
                                        type="button"
                                        wire:click="skipValue('{{ $selectedAnalysis->mappedToField }}', '{{ addslashes($value) }}')"
                                        title="{{ $isSkipped ? 'Unskip' : 'Skip' }}"
                                        @class([
                                            'ml-2 p-1.5 rounded-lg transition-colors shrink-0',
                                            'text-gray-400 hover:text-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700' => !$isSkipped,
                                            'text-primary-600 bg-primary-100 dark:bg-primary-900' => $isSkipped,
                                        ])
                                    >
                                        <x-filament::icon icon="heroicon-o-no-symbol" class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            No values found
                        </div>
                    @endforelse
                </div>
            @else
                <div class="flex items-center justify-center h-full text-gray-500 dark:text-gray-400">
                    Select a column to review values
                </div>
            @endif
        </div>
    </div>

    <div class="flex justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
        <x-filament::button wire:click="previousStep" color="gray">Back</x-filament::button>
        <x-filament::button wire:click="nextStep">Continue</x-filament::button>
    </div>
</div>
