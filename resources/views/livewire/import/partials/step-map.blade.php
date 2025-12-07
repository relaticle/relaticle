<div
    class="space-y-6"
    x-data="{ hoveredColumn: '{{ $csvHeaders[0] ?? '' }}' }"
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
                        $isMapped = $mappedField !== false;
                        $availableColumns = collect($this->importerColumns)
                            ->filter(fn ($col) => empty($columnMap[$col->getName()]))
                            ->values();
                    @endphp
                    <div
                        wire:key="map-{{ md5($header) }}"
                        class="flex items-center py-2 px-2 -mx-2 rounded-lg transition-colors"
                        :class="hoveredColumn === '{{ addslashes($header) }}' ? 'bg-primary-50 dark:bg-primary-950/30' : ''"
                        x-on:mouseenter="hoveredColumn = '{{ addslashes($header) }}'"
                    >
                        {{-- CSV Column Name --}}
                        <div class="flex-1 text-sm text-gray-950 dark:text-white">{{ $header }}</div>

                        {{-- Arrow --}}
                        <div class="w-6 flex justify-center">
                            <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5 text-gray-300 dark:text-gray-600" />
                        </div>

                        {{-- Attribute Select --}}
                        <div class="flex-1" x-data="{ open: false, search: '' }" x-on:click.outside="open = false">
                            @if ($isMapped)
                                {{-- Selected State --}}
                                <button
                                    type="button"
                                    wire:click="unmapColumn('{{ $mappedField }}')"
                                    class="w-full flex items-center justify-between gap-2 px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                                >
                                    <div class="flex items-center gap-2 min-w-0">
                                        <x-filament::icon icon="heroicon-o-squares-2x2" class="h-4 w-4 text-gray-400 shrink-0" />
                                        <span class="text-gray-950 dark:text-white truncate">{{ $this->getFieldLabel($mappedField) }}</span>
                                    </div>
                                    <x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4 text-gray-400 shrink-0" />
                                </button>
                            @else
                                {{-- Searchable Select --}}
                                <div class="relative">
                                    <button
                                        type="button"
                                        x-on:click="open = !open; $nextTick(() => open && $refs.search.focus())"
                                        class="w-full flex items-center justify-between gap-2 px-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                                    >
                                        <span class="text-gray-400">Select attribute</span>
                                        <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4 text-gray-400 shrink-0" />
                                    </button>

                                    {{-- Dropdown --}}
                                    <div
                                        x-show="open"
                                        x-transition:enter="transition ease-out duration-100"
                                        x-transition:enter-start="opacity-0 scale-95"
                                        x-transition:enter-end="opacity-100 scale-100"
                                        x-transition:leave="transition ease-in duration-75"
                                        x-transition:leave-start="opacity-100 scale-100"
                                        x-transition:leave-end="opacity-0 scale-95"
                                        x-cloak
                                        class="absolute z-10 mt-1 w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-lg"
                                    >
                                        {{-- Search Input --}}
                                        <div class="p-2 border-b border-gray-200 dark:border-gray-700">
                                            <input
                                                type="text"
                                                x-ref="search"
                                                x-model="search"
                                                placeholder="Search..."
                                                class="w-full px-2 py-1.5 text-sm rounded border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-950 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                                            />
                                        </div>

                                        {{-- Options --}}
                                        <div class="max-h-48 overflow-y-auto py-1">
                                            @foreach ($availableColumns as $column)
                                                <button
                                                    type="button"
                                                    x-show="!search || '{{ strtolower($column->getLabel()) }}'.includes(search.toLowerCase())"
                                                    wire:click="mapCsvColumnToField('{{ addslashes($header) }}', '{{ $column->getName() }}')"
                                                    x-on:click="open = false; search = ''"
                                                    class="w-full flex items-center gap-2 px-3 py-2 text-sm text-left text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                                                >
                                                    <x-filament::icon icon="heroicon-o-squares-2x2" class="h-4 w-4 text-gray-400 shrink-0" />
                                                    {{ $column->getLabel() }}
                                                </button>
                                            @endforeach
                                            <div
                                                x-show="search && ![{{ $availableColumns->map(fn ($c) => "'" . strtolower($c->getLabel()) . "'")->implode(',') }}].some(l => l.includes(search.toLowerCase()))"
                                                class="px-3 py-2 text-sm text-gray-400"
                                            >
                                                No results found
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif
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
