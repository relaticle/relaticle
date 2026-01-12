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
                        $colIsDateField = $analysis->isDateField();
                        $colNeedsFormatConfirm = $analysis->needsDateFormatConfirmation();

                        // Get relationship info for this field
                        $relInfo = $this->getRelationshipInfoForField($analysis->mappedToField);
                        $relField = $relInfo['field'] ?? null;
                        $relMatcherKey = $relInfo['matcherKey'] ?? null;
                        $relMatcher = $relField?->getMatcher($relMatcherKey);
                    @endphp
                    <button
                        type="button"
                        wire:click="toggleColumn('{{ $analysis->mappedToField }}')"
                        wire:key="col-{{ $analysis->mappedToField }}"
                        @class([
                            'w-full text-left px-2.5 py-2 rounded-lg transition-colors',
                            'bg-primary-50 dark:bg-primary-950 text-primary-700 dark:text-primary-300' => $expandedColumn === $analysis->mappedToField,
                            'hover:bg-gray-50 dark:hover:bg-gray-800' => $expandedColumn !== $analysis->mappedToField,
                        ])
                    >
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-1.5 min-w-0">
                                <span class="text-sm text-gray-950 dark:text-white truncate">{{ $analysis->csvColumnName }}</span>
                                @if ($colNeedsFormatConfirm)
                                    <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-4 w-4 shrink-0 text-warning-500" title="Date format needs confirmation" />
                                @endif
                            </div>
                            @if ($hasErrors)
                                <span class="shrink-0 inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-medium rounded bg-danger-100 text-danger-700 dark:bg-danger-900 dark:text-danger-300">
                                    {{ $errorCount }}
                                </span>
                            @endif
                        </div>
                        @if ($relField && $relMatcher)
                            <div class="flex items-center gap-0.5 text-xs text-gray-500 dark:text-gray-400">
                                <x-filament::icon icon="{{ $relField->icon }}" class="w-3 h-3 shrink-0" />
                                <span>{{ $relField->label }}</span>
                                <x-filament::icon icon="heroicon-m-chevron-right" class="w-3 h-3 shrink-0 text-gray-400 dark:text-gray-500" />
                                <span>{{ $relMatcher->label }}</span>
                            </div>
                        @else
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $this->getFieldLabel($analysis->mappedToField) }}</div>
                        @endif
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
                $hasColumnErrors = $selectedAnalysis?->hasErrors() ?? false;
                $errorValueCount = $selectedAnalysis?->getErrorCount() ?? 0;

                if ($showOnlyErrors && $hasColumnErrors) {
                    $values = $selectedAnalysis?->paginatedErrorValues($reviewPage, $perPage) ?? [];
                    $totalUnique = $errorValueCount;
                } else {
                    $values = $selectedAnalysis?->paginatedValues($reviewPage, $perPage) ?? [];
                    $totalUnique = $selectedAnalysis?->uniqueCount ?? 0;
                }

                $showing = min($reviewPage * $perPage, $totalUnique);
                $hasMore = $showing < $totalUnique;
            @endphp

            @if ($selectedAnalysis)
                @php
                    $isDateField = $selectedAnalysis->isDateField();
                    $isDateOnlyField = $selectedAnalysis->isDateOnlyField();
                    $isDateTimeField = $selectedAnalysis->isDateTimeField();
                    $effectiveDateFormat = $selectedAnalysis->getEffectiveDateFormat();
                    $needsFormatConfirmation = $selectedAnalysis->needsDateFormatConfirmation();

                    // Choice field detection
                    $isChoiceField = $selectedAnalysis->isChoiceField();
                    $isMultiChoice = $selectedAnalysis->isMultiChoiceField();
                    $hasMissingOptions = $selectedAnalysis->hasMissingChoiceOptions();
                    $choiceOptions = $isChoiceField ? $this->getChoiceOptionsForField($selectedAnalysis->mappedToField) : [];

                    // Use appropriate format enum based on field type
                    $formatOptions = $isDateTimeField
                        ? \Relaticle\ImportWizard\Enums\TimestampFormat::cases()
                        : \Relaticle\ImportWizard\Enums\DateFormat::cases();

                    // Convert DateFormat to TimestampFormat for display if datetime field
                    $displayFormat = $isDateTimeField && $effectiveDateFormat
                        ? \Relaticle\ImportWizard\Enums\TimestampFormat::fromDateFormat($effectiveDateFormat)
                        : $effectiveDateFormat;
                @endphp
                {{-- Column Header with Stats --}}
                <div class="flex items-center justify-between px-3 py-2 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                    <div class="flex items-center gap-3">
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            <span class="font-medium text-gray-700 dark:text-gray-300">{{ number_format($selectedAnalysis->uniqueCount) }}</span> unique values
                        </div>
                        @if ($hasColumnErrors)
                            <button
                                type="button"
                                wire:click="toggleShowOnlyErrors"
                                @class([
                                    'inline-flex items-center gap-1.5 px-2 py-1 text-xs font-medium rounded-md transition-colors',
                                    'bg-danger-100 text-danger-700 dark:bg-danger-900 dark:text-danger-300' => $showOnlyErrors,
                                    'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700' => !$showOnlyErrors,
                                ])
                            >
                                <x-filament::icon icon="heroicon-m-funnel" class="h-3.5 w-3.5" />
                                {{ $showOnlyErrors ? 'Show all' : 'Errors only (' . $errorValueCount . ')' }}
                            </button>
                        @endif
                        {{-- Create Missing Options Button --}}
                        @if ($hasMissingOptions)
                            {{ ($this->createMissingOptionsAction)(['column' => $selectedAnalysis->mappedToField]) }}
                        @endif
                    </div>
                    <div class="flex items-center gap-3">
                        {{-- Date/Timestamp Format Dropdown --}}
                        @if ($isDateField)
                            @include('import-wizard::components.format-select', [
                                'formats' => $formatOptions,
                                'selected' => $displayFormat,
                                'label' => $isDateTimeField ? 'Timestamp format' : 'Date format',
                                'field' => $selectedAnalysis->mappedToField,
                                'needsConfirmation' => $needsFormatConfirmation,
                            ])
                        @endif
                        <div class="text-xs text-gray-400">
                            Showing {{ number_format($showing) }} of {{ number_format($totalUnique) }}
                        </div>
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
                    @php
                        // For date fields, separate values into groups
                        $needsReviewValues = [];
                        $autoMappedValues = [];

                        if ($isDateField && !$showOnlyErrors) {
                            foreach ($values as $val => $cnt) {
                                $issue = $selectedAnalysis->getIssueForValue($val);
                                if ($issue !== null) {
                                    $needsReviewValues[$val] = $cnt;
                                } else {
                                    $autoMappedValues[$val] = $cnt;
                                }
                            }
                        }
                        $showGroupedView = $isDateField && !$showOnlyErrors && (count($needsReviewValues) > 0 || count($autoMappedValues) > 0);
                    @endphp

                    @if ($showGroupedView)
                        {{-- Grouped View for Date Fields --}}
                        @if (count($needsReviewValues) > 0)
                            <div class="px-3 py-2 bg-warning-50 dark:bg-warning-950/50 border-b border-warning-200 dark:border-warning-800">
                                <div class="flex items-center gap-1.5 text-xs font-medium text-warning-700 dark:text-warning-300 uppercase tracking-wider">
                                    <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-3.5 w-3.5" />
                                    Needs Review ({{ count($needsReviewValues) }})
                                </div>
                            </div>
                            @foreach ($needsReviewValues as $value => $count)
                                @include('import-wizard::livewire.partials.value-row', [
                                    'selectedAnalysis' => $selectedAnalysis,
                                    'value' => $value,
                                    'count' => $count,
                                    'isDateField' => $isDateField,
                                    'effectiveDateFormat' => $effectiveDateFormat,
                                    'isChoiceField' => $isChoiceField,
                                    'isMultiChoice' => $isMultiChoice,
                                    'choiceOptions' => $choiceOptions,
                                ])
                            @endforeach
                        @endif

                        @if (count($autoMappedValues) > 0)
                            <div class="px-3 py-2 bg-success-50 dark:bg-success-950/50 border-b border-success-200 dark:border-success-800 @if(count($needsReviewValues) > 0) mt-2 @endif">
                                <div class="flex items-center gap-1.5 text-xs font-medium text-success-700 dark:text-success-300 uppercase tracking-wider">
                                    <x-filament::icon icon="heroicon-m-check-circle" class="h-3.5 w-3.5" />
                                    Automatically Mapped ({{ count($autoMappedValues) }})
                                </div>
                            </div>
                            @foreach ($autoMappedValues as $value => $count)
                                @include('import-wizard::livewire.partials.value-row', [
                                    'selectedAnalysis' => $selectedAnalysis,
                                    'value' => $value,
                                    'count' => $count,
                                    'isDateField' => $isDateField,
                                    'effectiveDateFormat' => $effectiveDateFormat,
                                    'isChoiceField' => $isChoiceField,
                                    'isMultiChoice' => $isMultiChoice,
                                    'choiceOptions' => $choiceOptions,
                                ])
                            @endforeach
                        @endif
                    @else
                        {{-- Standard View --}}
                        @forelse ($values as $value => $count)
                            @include('import-wizard::livewire.partials.value-row', [
                                'selectedAnalysis' => $selectedAnalysis,
                                'value' => $value,
                                'count' => $count,
                                'isDateField' => $isDateField,
                                'effectiveDateFormat' => $effectiveDateFormat,
                            ])
                        @empty
                            <div class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                No values found
                            </div>
                        @endforelse
                    @endif

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

            <x-filament::button wire:click="nextStep">
                Continue
            </x-filament::button>
        </div>
    </div>
</div>
