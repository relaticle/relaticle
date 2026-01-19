<div class="flex flex-col h-full overflow-hidden">
    {{-- Main Content --}}
    <div class="flex-1 flex gap-4 min-h-0 overflow-hidden min-h-[12rem]">
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
                        class="w-full flex items-center justify-between px-3 py-2 text-left border-b border-gray-100 dark:border-gray-800 last:border-b-0 transition-colors hover:bg-gray-100 dark:hover:bg-gray-800 data-[loading]:opacity-50 {{ $selectedColumn === $csvColumn ? 'bg-primary-50 dark:bg-primary-950/30' : '' }}"
                    >
                        <div class="min-w-0 flex-1">
                            <span class="text-sm text-gray-900 dark:text-white truncate block">{{ $csvColumn }}</span>
                            <span class="text-[10px] text-gray-500 dark:text-gray-400 truncate block">
                                → {{ $analysis['fieldLabel'] }}
                            </span>
                        </div>
                        <x-filament::icon icon="heroicon-o-check" class="shrink-0 ml-2 w-4 h-4 text-success-500"/>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Values Panel (Right Panel) --}}
        <div class="flex-1 border border-gray-200 dark:border-gray-700 rounded-xl bg-white dark:bg-gray-900 flex flex-col overflow-hidden">
            @if ($selectedColumn !== '')
                @php
                    $selectedAnalysis = $this->selectedColumnAnalysis;
                    $columnValues = $this->columnValues;
                @endphp

                {{-- Header --}}
                <div class="px-3 py-2 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700 rounded-t-xl shrink-0">
                    <h3 class="text-sm font-medium text-gray-900 dark:text-white">{{ $selectedColumn }}</h3>
                    <p class="text-[10px] text-gray-500 dark:text-gray-400">
                        Mapped to <span class="font-medium">{{ $selectedAnalysis?->fieldLabel }}</span>
                        · {{ $selectedAnalysis?->uniqueCount ?? 0 }} unique values
                    </p>
                </div>

                {{-- Table Header --}}
                <div class="flex items-center px-3 py-1.5 text-[10px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 shrink-0">
                    <div class="w-2/5">Raw Data</div>
                    <div class="w-8 text-center">
                        <x-filament::icon icon="heroicon-o-arrow-right" class="w-3 h-3 mx-auto"/>
                    </div>
                    <div class="flex-1">Mapped Value</div>
                    <div class="w-16 text-center">Rows</div>
                    <div class="w-16 text-right">Skip</div>
                </div>

                {{-- Values List --}}
                <div class="flex-1 overflow-y-auto">
                    @forelse ($columnValues as $index => $valueData)
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
                            {{-- Raw Data --}}
                            <div class="w-2/5 min-w-0 pr-2">
                                <span class="text-sm {{ $isRawBlank ? 'text-gray-400 dark:text-gray-500 italic' : 'text-gray-900 dark:text-white' }} truncate block" title="{{ $rawValue }}">
                                    {{ $isRawBlank ? '(blank)' : Str::limit($rawValue, 40) }}
                                </span>
                            </div>

                            {{-- Arrow --}}
                            <div class="w-8 text-center">
                                <x-filament::icon icon="heroicon-o-arrow-right" class="w-3 h-3 text-gray-400 mx-auto"/>
                            </div>

                            {{-- Mapped Value --}}
                            <div class="flex-1 min-w-0 pr-2">
                                @if ($isSkipped)
                                    <span class="text-sm text-warning-600 dark:text-warning-400 italic">(skipped)</span>
                                @elseif ($isRawBlank)
                                    <span class="text-sm text-gray-400 dark:text-gray-500 italic">(blank)</span>
                                @else
                                    <input
                                        type="text"
                                        value="{{ $hasCorrection ? $mappedValue : $rawValue }}"
                                        wire:change.preserve-scroll="updateMappedValue({{ Js::from($selectedColumn) }}, {{ Js::from($rawValue) }}, $event.target.value)"
                                        class="w-full text-sm px-2 py-1 rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                                    />
                                @endif
                            </div>

                            {{-- Row Count --}}
                            <div class="w-16 text-center">
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ number_format($valueData['count']) }}
                                </span>
                            </div>

                            {{-- Skip Button --}}
                            <div class="w-16 flex justify-end">
                                @if (!$isRawBlank)
                                    @if ($isSkipped)
                                        <button
                                            wire:click.preserve-scroll="updateMappedValue({{ Js::from($selectedColumn) }}, {{ Js::from($rawValue) }}, {{ Js::from($rawValue) }})"
                                            class="p-1.5 rounded text-warning-600 dark:text-warning-400 hover:text-gray-600 hover:bg-gray-100 dark:hover:text-gray-300 dark:hover:bg-gray-800 transition-colors data-[loading]:opacity-50 data-[loading]:pointer-events-none"
                                            title="Restore this value"
                                        >
                                            <x-filament::icon icon="heroicon-o-arrow-uturn-left" class="w-4 h-4"/>
                                        </button>
                                    @else
                                        <button
                                            wire:click.preserve-scroll="skipValue({{ Js::from($selectedColumn) }}, {{ Js::from($rawValue) }})"
                                            class="p-1.5 rounded text-gray-400 hover:text-warning-600 hover:bg-warning-50 dark:hover:text-warning-400 dark:hover:bg-warning-950/50 transition-colors data-[loading]:opacity-50 data-[loading]:pointer-events-none"
                                            title="Skip this value"
                                        >
                                            <x-filament::icon icon="heroicon-o-no-symbol" class="w-4 h-4"/>
                                        </button>
                                    @endif
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="flex items-center justify-center py-8 text-sm text-gray-500 dark:text-gray-400">
                            No values to display
                        </div>
                    @endforelse
                </div>

                {{-- Infinite Scroll Trigger --}}
                @if (count($columnValues) >= $valuesPage * $perPage)
                    <div
                        wire:intersect.preserve-scroll="loadMore"
                        class="px-3 py-2 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 shrink-0 text-center"
                    >
                        <span class="text-xs text-gray-400 dark:text-gray-500">Loading more values...</span>
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
