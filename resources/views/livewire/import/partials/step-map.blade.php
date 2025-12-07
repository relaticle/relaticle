<div
    class="space-y-6"
    x-data="{ hoveredColumn: '{{ $csvHeaders[0] ?? '' }}' }"
    wire:ignore.self
>
    <div class="flex gap-6">
        {{-- Column Mapping List --}}
        <div class="flex-1">
            {{-- Header --}}
            <div class="flex items-center pb-2 mb-1 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                <div class="flex-1">File column</div>
                <div class="w-6"></div>
                <div class="flex-1">Attributes</div>
            </div>

            {{-- Mapping Rows --}}
            <div class="space-y-1">
                @foreach ($csvHeaders as $header)
                    @php
                        $mappedField = array_search($header, $columnMap);
                        $selectedValue = $mappedField !== false ? $mappedField : '';
                    @endphp
                    <div
                        wire:key="map-{{ md5($header) }}"
                        class="flex items-center py-2 px-2 -mx-2 rounded-lg transition-colors"
                        :class="hoveredColumn === '{{ addslashes($header) }}' ? 'bg-primary-50 dark:bg-primary-950/30' : ''"
                        @mouseenter="hoveredColumn = '{{ addslashes($header) }}'"
                    >
                        {{-- CSV Column Name --}}
                        <div class="flex-1 text-sm text-gray-950 dark:text-white">{{ $header }}</div>

                        {{-- Arrow --}}
                        <div class="w-6 flex justify-center">
                            <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5 text-gray-300 dark:text-gray-600" />
                        </div>

                        {{-- Attribute Select --}}
                        <div class="flex-1">
                            <x-filament::input.wrapper>
                                <x-filament::input.select
                                    wire:change="mapCsvColumnToField('{{ addslashes($header) }}', $event.target.value)"
                                >
                                    <option value="" @selected($selectedValue === '')>Select attribute</option>
                                    @foreach ($this->importerColumns as $column)
                                        @php
                                            $columnName = $column->getName();
                                            $isAlreadyMapped = !empty($columnMap[$columnName]) && $columnMap[$columnName] !== $header;
                                        @endphp
                                        <option
                                            value="{{ $columnName }}"
                                            @selected($selectedValue === $columnName)
                                            @disabled($isAlreadyMapped)
                                        >
                                            {{ $column->getLabel() }}
                                        </option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Data Preview Panel --}}
        <div class="w-72 shrink-0 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden flex flex-col">
            {{-- Preview Header --}}
            <div class="px-3 py-2 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 flex items-center justify-between">
                <span class="text-sm font-medium text-gray-950 dark:text-white" x-text="hoveredColumn"></span>
                <div class="flex items-center gap-1 text-xs text-gray-400">
                    <x-filament::icon icon="heroicon-o-eye" class="h-3 w-3" />
                    <span>Data preview</span>
                </div>
            </div>

            {{-- Preview Values (Alpine-driven) --}}
            <div class="flex-1 overflow-y-auto">
                @foreach ($csvHeaders as $header)
                    <div x-show="hoveredColumn === '{{ addslashes($header) }}'" x-cloak>
                        @foreach ($this->getColumnPreviewValues($header, 5) as $value)
                            <div class="px-3 py-2 border-b border-gray-100 dark:border-gray-800 text-sm text-gray-700 dark:text-gray-300">
                                {{ $value ?: '(blank)' }}
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>

            {{-- Preview Footer --}}
            <div class="px-3 py-1.5 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                <p class="text-xs text-gray-400">This preview shows only a portion of the column values</p>
            </div>
        </div>
    </div>

    {{-- Navigation --}}
    <div class="flex justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
        <x-filament::button wire:click="resetWizard" color="gray">Start over</x-filament::button>
        <x-filament::button wire:click="nextStep" :disabled="!$this->canProceedToNextStep()">Continue</x-filament::button>
    </div>
</div>
