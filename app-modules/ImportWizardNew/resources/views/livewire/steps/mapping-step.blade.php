<div
    class="space-y-4"
    x-data="{ hoveredColumn: '{{ $headers[0] ?? '' }}' }"
    @field-selected.window="$wire.mapToField($event.detail.column, $event.detail.fieldKey)"
    @relationship-selected.window="$wire.mapToRelationship($event.detail.column, $event.detail.matcherKey, $event.detail.relationshipName)"
    @field-cleared.window="$wire.unmapColumn($event.detail.column)"
    wire:ignore.self
>
    <div class="flex gap-4">
        {{-- Column Mapping List --}}
        <div class="flex-1 border border-gray-200 dark:border-gray-700 rounded-xl bg-white dark:bg-gray-900">
            {{-- Header --}}
            <div class="flex items-center px-3 py-2 text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700 rounded-t-xl">
                <div class="flex-1">File column</div>
                <div class="w-6"></div>
                <div class="flex-1 flex justify-end">
                    <div class="w-52">Map to</div>
                </div>
            </div>

            {{-- Mapping Rows --}}
            @foreach ($headers as $index => $header)
                @php
                    $mapping = $this->getMapping($header);
                    $mappedRelationshipNames = collect($this->relationships)
                        ->keys()
                        ->filter(fn ($name) => $this->isRelationshipMapped($name) && !($mapping?->relationship === $name))
                        ->values()
                        ->all();
                @endphp

                <div
                    wire:key="row-{{ md5($header) }}"
                    class="flex items-center py-2 border-b border-gray-100 dark:border-gray-800 last:border-b-0 px-3 transition-colors"
                    :class="hoveredColumn === '{{ addslashes($header) }}' ? 'bg-primary-50/50 dark:bg-primary-950/20' : ''"
                    @mouseenter="hoveredColumn = '{{ addslashes($header) }}'"
                >
                    {{-- File Column Name --}}
                    <div class="flex-1 min-w-0">
                        <span class="text-sm text-gray-900 dark:text-white truncate">{{ $header }}</span>
                    </div>

                    {{-- Arrow --}}
                    <div class="w-6 flex items-center justify-center">
                        <x-filament::icon icon="heroicon-m-arrow-long-right" class="w-4 h-4 text-gray-300 dark:text-gray-600" />
                    </div>

                    {{-- Attribute Selector --}}
                    <div class="flex-1 flex justify-end">
                        <x-import-wizard-new::field-select
                            :fields="$this->allFields"
                            :relationships="$this->relationships"
                            :selected="$mapping"
                            :mapped-field-keys="$this->mappedFieldKeys"
                            :mapped-relationships="$mappedRelationshipNames"
                            :column="$header"
                        />
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Preview Panel --}}
        <div class="w-56 shrink-0 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden flex flex-col max-h-[28rem] bg-white dark:bg-gray-900">
            <div class="px-3 py-2 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Preview</span>
                    <x-filament::icon icon="heroicon-o-eye" class="w-3.5 h-3.5 text-gray-400" />
                </div>
            </div>
            <div class="px-3 py-1.5 border-b border-gray-200 dark:border-gray-700 bg-primary-50/50 dark:bg-primary-950/20">
                <span class="text-xs font-medium text-gray-900 dark:text-white truncate block" x-text="hoveredColumn"></span>
            </div>
            <div class="flex-1 overflow-y-auto">
                @foreach ($headers as $header)
                    <div x-show="hoveredColumn === '{{ addslashes($header) }}'" x-cloak>
                        @foreach ($this->previewValues($header, 20) as $value)
                            <div class="px-3 py-1.5 border-b border-gray-100 dark:border-gray-800 last:border-b-0 text-xs text-gray-600 dark:text-gray-300 truncate">
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
            $unmappedLabels = collect($this->unmappedRequired->all())
                ->map(fn ($field) => $field->label)
                ->values();
        @endphp
        <div class="flex items-center gap-2 px-3 py-2 rounded-xl bg-warning-50 dark:bg-warning-950/50 border border-warning-200 dark:border-warning-800">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-4 h-4 text-warning-500 shrink-0" />
            <p class="text-xs text-warning-700 dark:text-warning-300">
                <span class="font-medium text-warning-800 dark:text-warning-200">Required:</span>
                {{ $unmappedLabels->join(', ') }}
            </p>
        </div>
    @endif

    {{-- Navigation --}}
    <div class="flex justify-between pt-3">
        <x-filament::button
            wire:click="$parent.mountAction('startOver')"
            color="gray"
        >
            Start over
        </x-filament::button>
        <x-filament::button
            wire:click="continueToReview"
            :disabled="!$this->canProceed()"
        >
            Continue
        </x-filament::button>
    </div>
</div>
