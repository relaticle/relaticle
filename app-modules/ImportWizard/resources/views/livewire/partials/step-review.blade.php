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
                $hasColumnErrors = $selectedAnalysis?->hasErrors() ?? false;
                $errorValueCount = $selectedAnalysis?->getErrorCount() ?? 0;
            @endphp

            @if ($selectedAnalysis)
                @php
                    $isDateField = $selectedAnalysis->isDateField();
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

                    // Pass config to Alpine as a single JSON object
                    $alpineConfig = [
                        'sessionId' => $sessionId,
                        'csvColumn' => $selectedAnalysis->csvColumnName,
                        'fieldName' => $selectedAnalysis->mappedToField,
                        'perPage' => 100,
                        'isDateField' => $isDateField,
                        'isChoiceField' => $isChoiceField,
                        'isMultiChoice' => $isMultiChoice,
                        'choiceOptions' => $choiceOptions,
                        'dateFormat' => $effectiveDateFormat?->value,
                        'uniqueCount' => $selectedAnalysis->uniqueCount,
                        'valuesUrl' => route('import.values'),
                        'correctionsStoreUrl' => route('import.corrections.store'),
                        'correctionsDestroyUrl' => route('import.corrections.destroy'),
                    ];
                @endphp

                {{-- Alpine-powered Values Panel using fetch() to bypass Livewire state sync --}}
                <div
                    x-data="valueReviewer({{ Js::from($alpineConfig) }})"
                    x-init="loadValues()"
                    wire:key="values-panel-{{ $selectedAnalysis->mappedToField }}-{{ $effectiveDateFormat?->value ?? 'auto' }}"
                    class="flex flex-col flex-1"
                >
                    {{-- Column Header with Stats --}}
                    <div class="flex items-center justify-between px-3 py-2 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                        <div class="flex items-center gap-3">
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ number_format($selectedAnalysis->uniqueCount) }}</span> unique values
                            </div>
                            @if ($hasColumnErrors)
                                <button
                                    type="button"
                                    @click="toggleErrorsOnly()"
                                    :class="{
                                        'bg-danger-100 text-danger-700 dark:bg-danger-900 dark:text-danger-300': errorsOnly,
                                        'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700': !errorsOnly,
                                    }"
                                    class="inline-flex items-center gap-1.5 px-2 py-1 text-xs font-medium rounded-md transition-colors"
                                >
                                    <x-filament::icon icon="heroicon-m-funnel" class="h-3.5 w-3.5" />
                                    <span x-text="errorsOnly ? 'Show all' : 'Errors only ({{ $errorValueCount }})'"></span>
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
                                Showing <span x-text="showing.toLocaleString()">0</span> of <span x-text="total.toLocaleString()">0</span>
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

                    {{-- Values List --}}
                    <div class="flex-1 flex flex-col overflow-hidden">
                        {{-- Loading State --}}
                        <div x-show="loading" x-cloak class="flex items-center justify-center flex-1 py-12">
                            <div class="text-center">
                                <x-filament::loading-indicator class="h-8 w-8 mx-auto text-primary-500" />
                                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Loading values...</p>
                            </div>
                        </div>

                        {{-- Values Content --}}
                        <div
                            x-show="!loading"
                            x-cloak
                            @scroll.debounce.100ms="onScroll($event)"
                            class="overflow-y-auto flex-1 max-h-[400px]"
                        >
                            {{-- Render values with Alpine x-for --}}
                            <template x-for="(item, index) in values" :key="item.value + '-' + index">
                                <div
                                    :class="{
                                        'bg-warning-50/50 dark:bg-warning-950/30': item.issue && item.issue.severity === 'warning' && !item.isSkipped,
                                    }"
                                    class="px-3 py-2 border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50"
                                >
                                    <div class="flex items-center">
                                        {{-- Raw Value --}}
                                        <div class="flex-1 flex items-center gap-2 min-w-0">
                                            <span
                                                :class="{
                                                    'text-gray-400 line-through': item.isSkipped,
                                                    'text-gray-950 dark:text-white': !item.isSkipped,
                                                }"
                                                class="text-sm truncate"
                                                x-text="item.value !== '' ? item.value : '(blank)'"
                                            ></span>
                                            <span class="text-xs text-gray-400 shrink-0" x-text="item.count + '×'"></span>
                                        </div>

                                        {{-- Arrow --}}
                                        <div class="w-8 flex justify-center">
                                            <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5 text-gray-300 dark:text-gray-600" />
                                        </div>

                                        {{-- Mapped Value / Input --}}
                                        <div class="flex-1 min-w-0">
                                            <template x-if="item.isSkipped">
                                                <span class="text-sm text-gray-400 italic">Skipped</span>
                                            </template>

                                            <template x-if="!item.isSkipped && isChoiceField && choiceOptions.length > 0 && !isMultiChoice">
                                                {{-- Single-choice: Select dropdown --}}
                                                <select
                                                    @change="correctValue(item.value, $event.target.value)"
                                                    :class="{
                                                        'border-success-300 dark:border-success-700 bg-success-50 dark:bg-success-950': item.correctedValue && !(item.issue && item.issue.severity === 'error'),
                                                        'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800': !item.correctedValue || (item.issue && item.issue.severity === 'error'),
                                                    }"
                                                    class="w-full px-2 py-1 text-sm rounded border focus:outline-none focus:ring-1 focus:ring-primary-500"
                                                >
                                                    <template x-for="opt in choiceOptions" :key="opt">
                                                        <option
                                                            :value="opt"
                                                            :selected="opt === (item.correctedValue ?? item.value)"
                                                            x-text="opt"
                                                        ></option>
                                                    </template>
                                                </select>
                                            </template>

                                            <template x-if="!item.isSkipped && (!isChoiceField || choiceOptions.length === 0)">
                                                {{-- Default: Text input --}}
                                                <div class="flex items-center gap-2">
                                                    <input
                                                        type="text"
                                                        :value="item.correctedValue ?? (item.value !== '' ? item.value : '(blank)')"
                                                        @blur="if ($event.target.value !== (item.correctedValue ?? item.value)) correctValue(item.value, $event.target.value)"
                                                        @keydown.enter="$event.target.blur()"
                                                        :class="{
                                                            'border-success-300 dark:border-success-700 bg-success-50 dark:bg-success-950': item.correctedValue && !(item.issue && item.issue.severity === 'error'),
                                                            'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800': !item.correctedValue || (item.issue && item.issue.severity === 'error'),
                                                        }"
                                                        class="w-full px-2 py-1 text-sm rounded border focus:outline-none focus:ring-1 focus:ring-primary-500"
                                                    />
                                                    {{-- Show parsed date preview for date fields --}}
                                                    <template x-if="isDateField && item.parsedDate">
                                                        <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400" x-text="'→ ' + item.parsedDate"></span>
                                                    </template>
                                                </div>
                                            </template>
                                        </div>

                                        {{-- Skip Button --}}
                                        <div class="w-10 flex justify-end">
                                            <button
                                                type="button"
                                                @click="skipValue(item.value)"
                                                :title="item.isSkipped ? 'Unskip' : 'Skip'"
                                                :class="{
                                                    'text-gray-400 hover:text-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700': !item.isSkipped,
                                                    'text-primary-600 bg-primary-100 dark:bg-primary-900': item.isSkipped,
                                                }"
                                                class="p-1 rounded transition-colors"
                                            >
                                                <x-filament::icon icon="heroicon-o-no-symbol" class="h-4 w-4" />
                                            </button>
                                        </div>
                                    </div>

                                    {{-- Validation Error/Warning Message --}}
                                    <template x-if="item.issue && item.issue.severity === 'error' && !item.isSkipped">
                                        <div class="mt-1 ml-0 text-xs text-danger-600 dark:text-danger-400">
                                            <x-filament::icon icon="heroicon-m-exclamation-circle" class="h-3.5 w-3.5 inline-block -mt-0.5 mr-0.5" />
                                            <span x-text="item.issue.message"></span>
                                        </div>
                                    </template>
                                    <template x-if="item.issue && item.issue.severity === 'warning' && !item.isSkipped">
                                        <div class="mt-1 ml-0 text-xs text-warning-600 dark:text-warning-400">
                                            <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-3.5 w-3.5 inline-block -mt-0.5 mr-0.5" />
                                            <span x-text="item.issue.message"></span>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            {{-- Empty State --}}
                            <template x-if="values.length === 0 && !loading">
                                <div class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    No values found
                                </div>
                            </template>

                            {{-- Load More indicator --}}
                            <template x-if="hasMore">
                                <div class="px-3 py-3 text-center border-t border-gray-100 dark:border-gray-800">
                                    <span x-show="!loadingMore" class="text-sm text-gray-400">
                                        Scroll for more...
                                    </span>
                                    <span x-show="loadingMore" x-cloak class="text-sm text-gray-400">
                                        <x-filament::loading-indicator class="h-4 w-4 inline-block" /> Loading...
                                    </span>
                                </div>
                            </template>
                        </div>
                    </div>
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
