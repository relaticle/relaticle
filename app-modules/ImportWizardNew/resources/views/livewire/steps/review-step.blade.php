<div class="flex flex-col h-full overflow-hidden">
    {{-- Main Content --}}
    <div class="flex-1 flex gap-4 overflow-hidden min-h-[12rem]">
        {{-- Column List (Left Panel) --}}
        <div class="w-56 shrink-0 border border-gray-200 dark:border-gray-700 rounded-xl bg-white dark:bg-gray-900 flex flex-col overflow-hidden">
            <div class="px-3 py-2 text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700 rounded-t-xl shrink-0">
                Mapped Columns
            </div>

            <div class="flex-1 overflow-y-auto">
                @foreach ($this->columnAnalyses as $csvColumn => $analysis)
                    <button
                        wire:key="col-{{ md5($csvColumn) }}"
                        wire:click="selectColumn({{ Js::from($csvColumn) }})"
                        class="w-full px-3 py-2 text-left border-b border-gray-100 dark:border-gray-800 last:border-b-0 transition-colors hover:bg-gray-100 dark:hover:bg-gray-800 data-[loading]:opacity-50 {{ $selectedColumn === $csvColumn ? 'bg-primary-50 dark:bg-primary-950/30' : '' }}"
                    >
                        <span class="text-sm text-gray-900 dark:text-white truncate block">{{ $csvColumn }}</span>
                        <span class="text-[10px] text-gray-500 dark:text-gray-400 truncate block">
                            → {{ $analysis['fieldLabel'] }}
                        </span>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Values Panel (Right Panel) --}}
        <div class="flex-1 border border-gray-200 dark:border-gray-700 rounded-xl bg-white dark:bg-gray-900 flex flex-col overflow-hidden">
            @if ($selectedColumn !== '')
                {{-- Header --}}
                <div class="px-3 py-2 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700 rounded-t-xl shrink-0">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <h3 class="text-sm font-medium text-gray-900 dark:text-white">{{ $selectedColumn }}</h3>
                            <p class="text-[10px] text-gray-500 dark:text-gray-400">
                                Mapped to <span class="font-medium">{{ $this->selectedColumnAnalysis?->fieldLabel }}</span>
                                · {{ $this->selectedColumnAnalysis?->uniqueCount ?? 0 }} unique values
                            </p>
                        </div>

                        @if ($this->isSelectedColumnDateType)
                            {{-- Date Format Select Menu --}}
                            <div class="w-44">
                                <x-select-menu
                                    :options="$this->dateFormatOptions"
                                    :searchable="false"
                                    placeholder="Date format"
                                    icon="heroicon-o-cog-6-tooth"
                                    wire:model.live="columnDateFormats.{{ $selectedColumn }}"
                                />
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Filters, Search and Sort --}}
                <div class="px-3 py-2 border-b border-gray-200 dark:border-gray-700 shrink-0">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex gap-1 text-xs">
                            @foreach ([
                                'all' => 'All',
                                'modified' => 'Modified',
                                'skipped' => 'Skipped',
                            ] as $key => $label)
                                <button
                                    wire:key="filter-{{ $key }}"
                                    wire:click="setFilter('{{ $key }}')"
                                    class="px-2 py-1 rounded transition-colors {{ $filter === $key
                                        ? 'bg-primary-100 dark:bg-primary-900/50 text-primary-700 dark:text-primary-300'
                                        : 'text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800' }}"
                                >
                                    {{ $label }} ({{ $this->filterCounts[$key] ?? 0 }})
                                </button>
                            @endforeach
                        </div>

                        {{-- Search and Sort --}}
                        <div class="flex items-center gap-2">
                            {{-- Search Input --}}
                            <div class="relative">
                                <x-filament::icon
                                    icon="heroicon-o-magnifying-glass"
                                    class="absolute left-2 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400"
                                />
                                <input
                                    type="text"
                                    wire:model.live.debounce.300ms="search"
                                    placeholder="Search..."
                                    class="{{ $search !== '' ? 'w-48' : 'w-32' }} pl-7 pr-2 py-1 text-xs rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-1 focus:ring-primary-500 focus:border-primary-500 focus:w-48 transition-all"
                                />
                            </div>

                            {{-- Sort Dropdown --}}
                            <div x-data="{ open: false }" class="relative">
                            <button
                                @click="open = !open"
                                @click.outside="open = false"
                                class="flex items-center gap-1.5 px-2 py-1 text-xs rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                            >
                                <x-filament::icon icon="heroicon-o-bars-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}" class="w-4 h-4"/>
                                <span>{{ $this->sortLabel }}</span>
                                <x-filament::icon icon="heroicon-m-chevron-down" class="w-3 h-3"/>
                            </button>

                            {{-- Dropdown Panel --}}
                            <div
                                x-show="open"
                                x-transition:enter="transition ease-out duration-100"
                                x-transition:enter-start="opacity-0 scale-95"
                                x-transition:enter-end="opacity-100 scale-100"
                                x-transition:leave="transition ease-in duration-75"
                                x-transition:leave-start="opacity-100 scale-100"
                                x-transition:leave-end="opacity-0 scale-95"
                                class="absolute right-0 mt-1 w-48 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-lg z-10"
                            >
                                {{-- Sort Field Options --}}
                                <div class="p-1 border-b border-gray-100 dark:border-gray-700">
                                    <button
                                        wire:click="setSort('raw_value', '{{ $sortDirection }}')"
                                        @click="open = false"
                                        class="w-full flex items-center justify-between px-3 py-2 text-sm rounded hover:bg-gray-100 dark:hover:bg-gray-700 {{ $sortField === 'raw_value' ? 'text-primary-600 dark:text-primary-400' : 'text-gray-700 dark:text-gray-300' }}"
                                    >
                                        <span class="flex items-center gap-2">
                                            <x-filament::icon icon="heroicon-o-language" class="w-4 h-4"/>
                                            Raw value
                                        </span>
                                        @if ($sortField === 'raw_value')
                                            <x-filament::icon icon="heroicon-m-check" class="w-4 h-4 text-primary-600 dark:text-primary-400"/>
                                        @endif
                                    </button>
                                    <button
                                        wire:click="setSort('count', '{{ $sortDirection }}')"
                                        @click="open = false"
                                        class="w-full flex items-center justify-between px-3 py-2 text-sm rounded hover:bg-gray-100 dark:hover:bg-gray-700 {{ $sortField === 'count' ? 'text-primary-600 dark:text-primary-400' : 'text-gray-700 dark:text-gray-300' }}"
                                    >
                                        <span class="flex items-center gap-2">
                                            <x-filament::icon icon="heroicon-o-hashtag" class="w-4 h-4"/>
                                            Row count
                                        </span>
                                        @if ($sortField === 'count')
                                            <x-filament::icon icon="heroicon-m-check" class="w-4 h-4 text-primary-600 dark:text-primary-400"/>
                                        @endif
                                    </button>
                                </div>

                                {{-- Sort Direction Options --}}
                                <div class="p-1">
                                    <button
                                        wire:click="setSort('{{ $sortField }}', 'asc')"
                                        @click="open = false"
                                        class="w-full flex items-center justify-between px-3 py-2 text-sm rounded hover:bg-gray-100 dark:hover:bg-gray-700 {{ $sortDirection === 'asc' ? 'text-primary-600 dark:text-primary-400' : 'text-gray-700 dark:text-gray-300' }}"
                                    >
                                        <span class="flex items-center gap-2">
                                            <x-filament::icon icon="heroicon-o-bars-arrow-up" class="w-4 h-4"/>
                                            Ascending
                                        </span>
                                        @if ($sortDirection === 'asc')
                                            <x-filament::icon icon="heroicon-m-check" class="w-4 h-4 text-primary-600 dark:text-primary-400"/>
                                        @endif
                                    </button>
                                    <button
                                        wire:click="setSort('{{ $sortField }}', 'desc')"
                                        @click="open = false"
                                        class="w-full flex items-center justify-between px-3 py-2 text-sm rounded hover:bg-gray-100 dark:hover:bg-gray-700 {{ $sortDirection === 'desc' ? 'text-primary-600 dark:text-primary-400' : 'text-gray-700 dark:text-gray-300' }}"
                                    >
                                        <span class="flex items-center gap-2">
                                            <x-filament::icon icon="heroicon-o-bars-arrow-down" class="w-4 h-4"/>
                                            Descending
                                        </span>
                                        @if ($sortDirection === 'desc')
                                            <x-filament::icon icon="heroicon-m-check" class="w-4 h-4 text-primary-600 dark:text-primary-400"/>
                                        @endif
                                    </button>
                                </div>
                            </div>
                        </div>
                        </div>
                    </div>
                </div>

                {{-- Table Header --}}
                <div class="flex items-center px-3 py-1.5 text-[10px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 shrink-0">
                    <div class="w-1/2">Raw Data</div>
                    <div class="w-6 text-center">
                        <x-filament::icon icon="heroicon-o-arrow-right" class="w-3 h-3 mx-auto"/>
                    </div>
                    <div class="flex-1">Mapped Value</div>
                </div>

                {{-- Values List --}}
                <div class="flex-1 overflow-y-auto">
                    @forelse ($loadedValues as $index => $valueData)
                        @php
                            $rawValue = $valueData['raw'];
                            $mappedValue = $valueData['mapped'];
                            $isRawBlank = $rawValue === '';
                            $isSkipped = $mappedValue === '';
                            $hasCorrection = $mappedValue !== null;
                        @endphp

                        <div
                            wire:key="val-{{ $index }}-{{ crc32($rawValue) }}"
                            class="flex items-center px-3 py-2 border-b border-gray-100 dark:border-gray-800 last:border-b-0"
                        >
                            {{-- Raw Data + Row Count --}}
                            <div class="w-1/2 min-w-0 pr-2 flex items-center gap-2">
                                <span class="text-sm {{ $isRawBlank ? 'text-gray-400 dark:text-gray-500 italic' : 'text-gray-900 dark:text-white' }} truncate" title="{{ $rawValue }}">
                                    {{ $isRawBlank ? '(blank)' : Str::limit($rawValue, 35) }}
                                </span>
                                <span class="text-xs text-gray-400 dark:text-gray-500 shrink-0">
                                    {{ $valueData['count'] }} {{ Str::plural('row', $valueData['count']) }}
                                </span>
                            </div>

                            {{-- Arrow --}}
                            <div class="w-6 text-center">
                                <x-filament::icon icon="heroicon-o-arrow-right" class="w-3 h-3 text-gray-400 mx-auto"/>
                            </div>

                            {{-- Mapped Value + Actions --}}
                            <div class="flex-1 min-w-0 flex items-center gap-2">
                                @if ($isSkipped)
                                    @include('import-wizard-new::livewire.steps.partials.value-row-skipped', compact('rawValue', 'selectedColumn'))
                                @elseif ($isRawBlank)
                                    <span class="text-sm text-gray-400 dark:text-gray-500 italic">(blank)</span>
                                @elseif ($this->isSelectedColumnDateType)
                                    @include('import-wizard-new::livewire.steps.partials.value-row-date', compact('rawValue', 'mappedValue', 'hasCorrection', 'selectedColumn'))
                                @elseif ($this->isSelectedColumnChoiceType)
                                    @include('import-wizard-new::livewire.steps.partials.value-row-choice', compact('rawValue', 'mappedValue', 'hasCorrection', 'selectedColumn'))
                                @else
                                    @include('import-wizard-new::livewire.steps.partials.value-row-text', compact('rawValue', 'mappedValue', 'hasCorrection', 'selectedColumn'))
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="flex flex-col items-center justify-center py-8 text-sm text-gray-500 dark:text-gray-400">
                            @if ($search !== '' && $filter !== 'all')
                                <x-filament::icon icon="heroicon-o-magnifying-glass" class="w-8 h-8 mb-2 text-gray-300 dark:text-gray-600"/>
                                <span>No values match your search and filter</span>
                            @elseif ($search !== '')
                                <x-filament::icon icon="heroicon-o-magnifying-glass" class="w-8 h-8 mb-2 text-gray-300 dark:text-gray-600"/>
                                <span>No values match "{{ $search }}"</span>
                            @elseif ($filter !== 'all')
                                <x-filament::icon icon="heroicon-o-funnel" class="w-8 h-8 mb-2 text-gray-300 dark:text-gray-600"/>
                                <span>No {{ $filter }} values</span>
                            @else
                                No values to display
                            @endif

                            @if ($search !== '' || $filter !== 'all')
                                <button
                                    wire:click="clearFilters"
                                    class="mt-2 text-xs text-primary-600 dark:text-primary-400 hover:underline"
                                >
                                    Clear filters
                                </button>
                            @endif
                        </div>
                    @endforelse
                </div>

                {{-- Pagination Controls --}}
                @php
                    $totalRows = $totalFiltered;
                    $startRow = $totalRows > 0 ? (($valuesPage - 1) * $perPage) + 1 : 0;
                    $endRow = min($valuesPage * $perPage, $totalRows);
                @endphp
                @if ($this->totalPages > 1)
                    <div class="px-3 py-2 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 shrink-0 flex items-center justify-between">
                        <button
                            wire:click="previousPage"
                            @disabled($valuesPage <= 1)
                            class="px-3 py-1 text-xs font-medium rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                        >
                            Previous
                        </button>
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            {{ number_format($startRow) }}–{{ number_format($endRow) }} of {{ number_format($totalRows) }}
                        </span>
                        <button
                            wire:click="nextPage"
                            @disabled($valuesPage >= $this->totalPages)
                            class="px-3 py-1 text-xs font-medium rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                        >
                            Next
                        </button>
                    </div>
                @endif
            @else
                <div class="flex-1 flex items-center justify-center text-sm text-gray-500 dark:text-gray-400">
                    Select a column to view its values
                </div>
            @endif
        </div>
    </div>

    {{-- Navigation --}}
    <div class="shrink-0 flex justify-end gap-3 pt-4 mt-6 border-t border-gray-200 dark:border-gray-700 pb-1">
        <x-filament::button color="gray" wire:click="$parent.goBack()">
            Back
        </x-filament::button>
        <x-filament::button wire:click="continueToPreview" class="data-[loading]:opacity-50">
            Continue
        </x-filament::button>
    </div>
</div>
