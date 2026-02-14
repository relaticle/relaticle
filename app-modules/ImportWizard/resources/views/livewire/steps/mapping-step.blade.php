<div
    class="flex flex-col h-full overflow-hidden"
    x-data="{ hoveredColumn: {{ Js::from($headers[0] ?? '') }} }"
    @field-selected.window="$wire.mapToField($event.detail.column, $event.detail.fieldKey)"
    @entity-link-selected.window="$wire.mapToEntityLink($event.detail.column, $event.detail.matcherKey, $event.detail.entityLinkKey)"
    @field-cleared.window="$wire.unmapColumn($event.detail.column)"
    wire:ignore.self
>
    <div class="flex-1 flex flex-col space-y-4 overflow-hidden min-h-[12rem]">
        <div class="flex-1 flex gap-4 min-h-0 overflow-hidden">
            {{-- Column Mapping List --}}
            <div
                class="flex-1 border border-gray-200 dark:border-gray-700 rounded-xl bg-white dark:bg-gray-900 flex flex-col overflow-hidden">
                {{-- Header --}}
                <div
                    class="flex items-center px-3 py-2 text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700 rounded-t-xl shrink-0">
                    <div class="flex-1">File column</div>
                    <div class="w-6"></div>
                    <div class="flex-1 flex justify-end">
                        <div class="w-52">Map to</div>
                    </div>
                </div>

                {{-- Mapping Rows --}}
                <div class="flex-1 overflow-y-auto">
                    @foreach ($headers as $index => $header)
                        @php
                            $mapping = $this->getMapping($header);
                            $allMappedMatchers = $this->getMappedEntityLinkMatchers();
                            $mappedEntityLinkMatchers = [];
                            foreach ($allMappedMatchers as $lk => $matcherFields) {
                                $currentMappingMatcher = ($mapping?->entityLink === $lk) ? $mapping?->target : null;
                                $mappedEntityLinkMatchers[$lk] = array_values(
                                    array_filter($matcherFields, fn ($f) => $f !== $currentMappingMatcher)
                                );
                            }
                        @endphp

                        <div
                            wire:key="row-{{ md5($header) }}"
                            class="flex items-center py-2 border-b border-gray-100 dark:border-gray-800 last:border-b-0 px-3 transition-colors"
                            :class="hoveredColumn === {{ Js::from($header) }} ? 'bg-primary-50/50 dark:bg-primary-950/20' : ''"
                            @mouseenter="hoveredColumn = {{ Js::from($header) }}"
                        >
                            {{-- File Column Name --}}
                            <div class="flex-1 min-w-0">
                                <span class="text-sm text-gray-900 dark:text-white truncate">{{ $header }}</span>
                            </div>

                            {{-- Arrow --}}
                            <div class="w-6 flex items-center justify-center">
                                <x-filament::icon icon="heroicon-m-arrow-long-right"
                                                  class="w-4 h-4 text-gray-300 dark:text-gray-600"/>
                            </div>

                            {{-- Attribute Selector --}}
                            <div class="flex-1 flex justify-end">
                                <x-import-wizard-new::field-select
                                    :fields="$this->allFields"
                                    :entity-links="$this->entityLinks"
                                    :selected="$mapping"
                                    :mapped-field-keys="$this->mappedFieldKeys"
                                    :mapped-entity-link-matchers="$mappedEntityLinkMatchers"
                                    :column="$header"
                                />
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Preview Panel --}}
            <div
                class="w-56 shrink-0 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden flex flex-col bg-white dark:bg-gray-900">
                <div class="px-3 py-2 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Preview</span>
                        <x-filament::icon icon="heroicon-o-eye" class="w-3.5 h-3.5 text-gray-400"/>
                    </div>
                </div>
                <div
                    class="px-3 py-1.5 border-b border-gray-200 dark:border-gray-700 bg-primary-50/50 dark:bg-primary-950/20">
                    <span class="text-xs font-medium text-gray-900 dark:text-white truncate block"
                          x-text="hoveredColumn"></span>
                </div>
                <div class="flex-1 overflow-y-auto">
                    @foreach ($headers as $header)
                        <div x-show="hoveredColumn === {{ Js::from($header) }}" x-cloak>
                            @foreach ($this->previewValues($header, 50) as $value)
                                <div
                                    class="px-3 py-1.5 border-b border-gray-100 dark:border-gray-800 last:border-b-0 text-xs text-gray-600 dark:text-gray-300 truncate">
                                    {{ $value ?: '—' }}
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
                <div class="px-3 py-1.5 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                    <p class="text-[10px] text-gray-500 dark:text-gray-400">Showing sample values</p>
                </div>
            </div>
        </div>

        {{-- Required Fields Warning --}}
        @if ($this->unmappedRequired->isNotEmpty())
            @php
                $unmappedLabels = $this->unmappedRequired->pluck('label');
            @endphp
            <div
                class="flex items-center gap-2 px-3 py-2 rounded-xl bg-warning-50 dark:bg-warning-950/50 border border-warning-200 dark:border-warning-800">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-4 h-4 text-warning-500 shrink-0"/>
                <p class="text-xs text-warning-700 dark:text-warning-300">
                    <span class="font-medium text-warning-800 dark:text-warning-200">Required:</span>
                    {{ $unmappedLabels->join(', ') }}
                </p>
            </div>
        @endif
    </div>

    <x-filament-actions::modals />

    {{-- Navigation --}}
    <div class="shrink-0 flex justify-end gap-3 pt-4 mt-6 border-t border-gray-200 dark:border-gray-700 pb-1">
        <x-filament::button
            wire:click="$parent.mountAction('startOver')"
            color="gray"
        >
            Start over
        </x-filament::button>
        {{ $this->continueAction }}
    </div>
</div>
