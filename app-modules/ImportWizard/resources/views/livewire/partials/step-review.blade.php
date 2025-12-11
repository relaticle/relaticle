<div class="space-y-6">
    <div class="flex gap-6 min-h-[500px]">
        {{-- Columns List --}}
        <div class="w-56 shrink-0 flex flex-col">
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider px-1 mb-2">Columns</div>
            <div class="space-y-1 overflow-y-auto flex-1">
                @foreach ($this->columnAnalyses as $analysis)
                    @php
                        $errorCount = $analysis->getErrorCount();
                        $hasErrors = $errorCount > 0;
                    @endphp
                    <button
                        type="button"
                        wire:click="toggleColumn('{{ $analysis->mappedToField }}')"
                        wire:key="col-{{ $analysis->mappedToField }}"
                        @class([
                            'w-full text-left px-2.5 py-2 rounded-lg transition-colors',
                            'bg-primary-50 dark:bg-primary-950 text-primary-700 dark:text-primary-300' => $expandedColumn === $analysis->mappedToField,
                            'hover:bg-gray-50 dark:hover:bg-gray-800' => $expandedColumn !== $analysis->mappedToField,
                            'ring-1 ring-danger-500/50' => $hasErrors,
                        ])
                    >
                        <div class="flex items-center justify-between gap-2">
                            <div class="text-sm text-gray-950 dark:text-white truncate">{{ $analysis->csvColumnName }}</div>
                            @if ($hasErrors)
                                <span class="shrink-0 inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-medium rounded bg-danger-100 text-danger-700 dark:bg-danger-900 dark:text-danger-300">
                                    {{ $errorCount }}
                                </span>
                            @endif
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $analysis->mappedToField }}</div>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Values Panel --}}
        <div class="flex-1 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden flex flex-col">
            @php
                $selectedAnalysis = $expandedColumn
                    ? $this->columnAnalyses->firstWhere('mappedToField', $expandedColumn)
                    : $this->columnAnalyses->first();
                $perPage = 100;
                $values = $selectedAnalysis?->paginatedValues($reviewPage, $perPage) ?? [];
                $totalUnique = $selectedAnalysis?->uniqueCount ?? 0;
                $showing = min($reviewPage * $perPage, $totalUnique);
                $hasMore = $showing < $totalUnique;
            @endphp

            @if ($selectedAnalysis)
                {{-- Column Header with Stats --}}
                <div class="flex items-center justify-between px-3 py-2 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        <span class="font-medium text-gray-700 dark:text-gray-300">{{ number_format($totalUnique) }}</span> unique values
                    </div>
                    <div class="text-xs text-gray-400">
                        Showing {{ number_format($showing) }} of {{ number_format($totalUnique) }}
                    </div>
                </div>

                {{-- Column Labels --}}
                <div class="flex items-center px-3 py-2 border-b border-gray-200 dark:border-gray-700 text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    <div class="flex-1">Raw data</div>
                    <div class="w-8"></div>
                    <div class="flex-1">Mapped value</div>
                    <div class="w-10"></div>
                </div>

                {{-- Values List with Fixed Height Scroll --}}
                <div
                    x-data="{ loading: false }"
                    x-on:scroll.debounce.100ms="
                        if (!loading && $el.scrollTop + $el.clientHeight >= $el.scrollHeight - 100) {
                            loading = true;
                            $wire.loadMoreValues().then(() => { loading = false; });
                        }
                    "
                    class="overflow-y-auto flex-1 max-h-[400px]"
                >
                    @forelse ($values as $value => $count)
                        @php
                            $isSkipped = $this->isValueSkipped($selectedAnalysis->mappedToField, $value);
                            $hasCorrection = $this->hasCorrectionForValue($selectedAnalysis->mappedToField, $value);
                            $displayValue = $value ?: '(blank)';
                            $mappedValue = $hasCorrection ? $this->getCorrectedValue($selectedAnalysis->mappedToField, $value) : $displayValue;
                            $valueIssue = $selectedAnalysis->getIssueForValue($value);
                            $hasError = $valueIssue !== null && $valueIssue->severity === 'error' && !$isSkipped;
                        @endphp
                        <div
                            wire:key="val-{{ md5($selectedAnalysis->mappedToField . $value) }}"
                            @class([
                                'px-3 py-2 border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50',
                                'bg-danger-50/50 dark:bg-danger-950/30' => $hasError,
                            ])
                        >
                            <div class="flex items-center">
                                {{-- Raw Value --}}
                                <div class="flex-1 flex items-center gap-2 min-w-0">
                                    <span @class([
                                        'text-sm truncate',
                                        'text-gray-400 line-through' => $isSkipped,
                                        'text-danger-700 dark:text-danger-400' => $hasError,
                                        'text-gray-950 dark:text-white' => !$isSkipped && !$hasError,
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
                                        <input
                                            type="text"
                                            value="{{ $mappedValue }}"
                                            x-on:blur="if ($event.target.value !== '{{ addslashes($mappedValue) }}') $wire.correctValue('{{ $selectedAnalysis->mappedToField }}', '{{ addslashes($value) }}', $event.target.value)"
                                            x-on:keydown.enter="$event.target.blur()"
                                            @class([
                                                'w-full px-2 py-1 text-sm rounded border focus:outline-none focus:ring-1 focus:ring-primary-500',
                                                'border-danger-300 dark:border-danger-700 bg-danger-50 dark:bg-danger-950' => $hasError,
                                                'border-success-300 dark:border-success-700 bg-success-50 dark:bg-success-950' => $hasCorrection && !$hasError,
                                                'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800' => !$hasCorrection && !$hasError,
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

                            {{-- Validation Error Message --}}
                            @if ($hasError)
                                <div class="mt-1 ml-0 text-xs text-danger-600 dark:text-danger-400">
                                    <x-filament::icon icon="heroicon-m-exclamation-circle" class="h-3.5 w-3.5 inline-block -mt-0.5 mr-0.5" />
                                    {{ $valueIssue->message }}
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            No values found
                        </div>
                    @endforelse

                    {{-- Load More indicator --}}
                    @if ($hasMore)
                        <div
                            wire:key="load-more-{{ $reviewPage }}"
                            class="px-3 py-3 text-center border-t border-gray-100 dark:border-gray-800"
                        >
                            <span x-show="!loading" class="text-sm text-gray-400">
                                Scroll for more...
                            </span>
                            <span x-show="loading" x-cloak class="text-sm text-gray-400">
                                <x-filament::loading-indicator class="h-4 w-4 inline-block" /> Loading...
                            </span>
                        </div>
                    @endif
                </div>
            @else
                <div class="flex-1 flex items-center justify-center text-sm text-gray-500 dark:text-gray-400">
                    Select a column to review values
                </div>
            @endif
        </div>
    </div>

    {{-- Navigation --}}
    <div class="flex justify-between items-center pt-4 border-t border-gray-200 dark:border-gray-700">
        <x-filament::button wire:click="previousStep" color="gray">Back</x-filament::button>

        <div class="flex items-center gap-4">
            @if ($this->hasValidationErrors())
                <div class="flex items-center gap-2 text-sm text-danger-600 dark:text-danger-400">
                    <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-5 w-5" />
                    <span>{{ $this->getTotalErrorCount() }} {{ Str::plural('error', $this->getTotalErrorCount()) }} to fix</span>
                </div>
            @endif

            <x-filament::button
                wire:click="nextStep"
                :disabled="!$this->canProceedToNextStep()"
            >
                Continue
            </x-filament::button>
        </div>
    </div>
</div>
