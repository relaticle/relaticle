<div class="space-y-6">
    <div class="flex gap-6 min-h-[500px]">
        {{-- Column Mapping Table --}}
        <div class="flex-1">
            {{-- Header --}}
            <div class="flex items-center border-b border-gray-200 dark:border-gray-700 pb-3 mb-1">
                <div class="flex-1 text-sm font-medium text-gray-500 dark:text-gray-400">File column</div>
                <div class="w-8"></div>
                <div class="flex-1 flex items-center gap-2 text-sm font-medium text-gray-500 dark:text-gray-400">
                    <span>Attributes</span>
                    <x-filament::icon-button
                        icon="heroicon-o-information-circle"
                        color="gray"
                        size="sm"
                        label="Map unique attributes"
                    />
                    <span class="text-xs text-gray-400 dark:text-gray-500">Map unique attributes</span>
                </div>
            </div>

            {{-- Mapping Rows --}}
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($csvHeaders as $header)
                    @php
                        $mappedField = array_search($header, $columnMap);
                        $isMapped = $mappedField !== false;
                    @endphp
                    <div
                        wire:key="map-{{ md5($header) }}"
                        @class([
                            'flex items-center py-3 cursor-pointer transition-colors',
                            'hover:bg-gray-50 dark:hover:bg-gray-800/50',
                            'bg-primary-50 dark:bg-primary-950/30' => $selectedCsvColumn === $header,
                        ])
                        wire:click="selectCsvColumn('{{ addslashes($header) }}')"
                    >
                        {{-- CSV Column Name --}}
                        <div class="flex-1 text-sm font-medium text-gray-950 dark:text-white">
                            {{ $header }}
                        </div>

                        {{-- Arrow --}}
                        <div class="w-8 flex justify-center">
                            <x-filament::icon icon="heroicon-m-arrow-right" class="h-4 w-4 text-gray-400" />
                        </div>

                        {{-- Attribute Select --}}
                        <div class="flex-1" x-data x-on:click.stop>
                            @if ($isMapped)
                                <div class="flex items-center justify-between px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
                                    <div class="flex items-center gap-2">
                                        <x-filament::icon icon="heroicon-o-squares-2x2" class="h-4 w-4 text-gray-400" />
                                        <span class="text-sm text-gray-950 dark:text-white">
                                            {{ $this->getFieldLabel($mappedField) }}
                                        </span>
                                    </div>
                                    <button
                                        type="button"
                                        wire:click="unmapColumn('{{ $mappedField }}')"
                                        class="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                    >
                                        <x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4" />
                                    </button>
                                </div>
                            @else
                                <x-filament::input.wrapper>
                                    <x-filament::input.select
                                        wire:change="mapCsvColumnToField('{{ addslashes($header) }}', $event.target.value)"
                                    >
                                        <option value="">Select attribute</option>
                                        @foreach ($this->importerColumns as $column)
                                            @php
                                                $columnName = $column->getName();
                                                $isAlreadyMapped = !empty($columnMap[$columnName]);
                                            @endphp
                                            @unless ($isAlreadyMapped)
                                                <option value="{{ $columnName }}">{{ $column->getLabel() }}</option>
                                            @endunless
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Data Preview Panel --}}
        <div class="w-80 shrink-0 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden flex flex-col">
            @php
                $previewColumn = $selectedCsvColumn ?? $csvHeaders[0] ?? null;
                $previewValues = $previewColumn ? $this->getColumnPreviewValues($previewColumn, 5) : [];
            @endphp

            @if ($previewColumn)
                {{-- Preview Header --}}
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 flex items-center justify-between">
                    <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $previewColumn }}</span>
                    <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                        <x-filament::icon icon="heroicon-o-eye" class="h-3.5 w-3.5" />
                        <span>Data preview</span>
                    </div>
                </div>

                {{-- Preview Values --}}
                <div class="flex-1 overflow-y-auto">
                    @forelse ($previewValues as $value)
                        <div class="px-4 py-2.5 border-b border-gray-100 dark:border-gray-800 text-sm text-gray-700 dark:text-gray-300">
                            {{ $value ?: '(blank)' }}
                        </div>
                    @empty
                        <div class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            No data available
                        </div>
                    @endforelse
                </div>

                {{-- Preview Footer --}}
                <div class="px-4 py-2 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        This preview shows only a portion of the column values
                    </p>
                </div>
            @else
                <div class="flex-1 flex items-center justify-center text-sm text-gray-500 dark:text-gray-400">
                    Select a column to preview values
                </div>
            @endif
        </div>
    </div>

    {{-- Navigation --}}
    <div class="flex justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
        <x-filament::button wire:click="resetWizard" color="gray">Start over</x-filament::button>
        <x-filament::button wire:click="nextStep" :disabled="!$this->canProceedToNextStep()">Continue</x-filament::button>
    </div>
</div>
