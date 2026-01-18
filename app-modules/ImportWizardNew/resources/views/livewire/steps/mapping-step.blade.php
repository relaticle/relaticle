<div
    class="space-y-6"
    x-data="{
        hoveredColumn: '{{ $headers[0] ?? '' }}',
        activeDropdown: null,
        activeDropdownColumn: null,
        activeSubmenu: null,
        submenuTimeout: null,
        submenuPosition: { top: 0, left: 0 },
        openDropdown(id, column) {
            this.activeDropdown = id;
            this.activeDropdownColumn = column;
            this.activeSubmenu = null;
        },
        closeDropdown() {
            this.activeDropdown = null;
            this.activeDropdownColumn = null;
            this.activeSubmenu = null;
        },
        showSubmenu(name, event) {
            clearTimeout(this.submenuTimeout);
            const rect = event.currentTarget.getBoundingClientRect();
            this.submenuPosition = { top: rect.top + 'px', left: (rect.right + 4) + 'px' };
            this.activeSubmenu = name;
        },
        hideSubmenu() {
            this.submenuTimeout = setTimeout(() => { this.activeSubmenu = null; }, 150);
        },
        keepSubmenu() {
            clearTimeout(this.submenuTimeout);
        },
        isMatcherSelected(relName, matcherKey) {
            const mappings = $wire.columnMappings;
            for (const source in mappings) {
                const m = mappings[source];
                if (m.relationship === relName &&
                    m.source === this.activeDropdownColumn &&
                    m.target === matcherKey) {
                    return true;
                }
            }
            return false;
        }
    }"
    @keydown.escape.window="closeDropdown()"
    wire:ignore.self
>
    <div class="flex gap-6">
        {{-- Column Mapping List --}}
        <div class="flex-1">
            {{-- Header --}}
            <div class="flex items-center py-2 text-xs text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                <div class="flex-1">File column</div>
                <div class="w-8"></div>
                <div class="flex-1 flex justify-end">
                    <div class="w-56">Attributes</div>
                </div>
            </div>

            {{-- Mapping Rows --}}
            @foreach ($headers as $index => $header)
                @php
                    $dropdownId = 'dd-' . md5($header);
                    $mapping = $this->getMapping($header);
                    $mappedField = $this->getFieldForSource($header);
                    $relationshipMapping = $this->getRelationshipForSource($header);
                    $isUsedByRelationship = $relationshipMapping !== null;
                    $hasMappingDisplay = $mappedField !== null || $isUsedByRelationship;
                    $mappedFieldKeys = $this->mappedFieldKeys;
                @endphp

                <div
                    wire:key="row-{{ md5($header) }}"
                    class="flex items-center py-2 border-b border-gray-100 dark:border-gray-800 -mx-2 px-2 rounded-md transition-colors"
                    :class="hoveredColumn === '{{ addslashes($header) }}' ? 'bg-white dark:bg-white/5' : ''"
                    @mouseenter="hoveredColumn = '{{ addslashes($header) }}'"
                >
                    {{-- File Column Name --}}
                    <div class="flex-1 min-w-0 flex items-center gap-2">
                        <span class="text-sm text-gray-900 dark:text-white truncate">{{ $header }}</span>
                    </div>

                    {{-- Arrow (centered) --}}
                    <div class="w-8 flex items-center justify-center">
                        <x-filament::icon icon="heroicon-o-arrow-right" class="w-3.5 h-3.5 text-gray-300 dark:text-gray-600" />
                    </div>

                    {{-- Attribute Selector --}}
                    <div class="flex-1 flex justify-end">
                        <div
                            class="w-56 relative"
                            @click.outside="if (activeDropdown === '{{ $dropdownId }}' && !activeSubmenu) closeDropdown()"
                        >
                            <div
                                class="w-full flex items-center gap-1.5 px-2.5 py-1.5 text-sm rounded-md bg-gray-100 dark:bg-gray-800 hover:bg-gray-150 dark:hover:bg-gray-750 transition-colors cursor-pointer"
                                @click="activeDropdown === '{{ $dropdownId }}' ? closeDropdown() : openDropdown('{{ $dropdownId }}', '{{ addslashes($header) }}')"
                            >
                            @if ($isUsedByRelationship)
                                @php
                                    $relField = $relationshipMapping['field'];
                                    $relMatcher = $relField->getMatcher($relationshipMapping['matcherKey']);
                                @endphp
                                <x-filament::icon icon="{{ $relField->icon() }}" class="w-3.5 h-3.5 text-gray-500 dark:text-gray-400 shrink-0" />
                                <span class="flex-1 text-left text-gray-900 dark:text-white truncate text-sm">
                                    {{ $relField->label }}
                                    @if ($relMatcher)
                                        <x-filament::icon icon="heroicon-m-chevron-right" class="inline w-3 h-3 text-gray-400 dark:text-gray-500 mx-0.5" />
                                        <span class="text-gray-500 dark:text-gray-400">{{ $relMatcher->label }}</span>
                                    @endif
                                </span>
                            @elseif ($mappedField)
                                <x-filament::icon icon="heroicon-o-squares-2x2" class="w-3.5 h-3.5 text-gray-500 dark:text-gray-400 shrink-0" />
                                <span class="flex-1 text-left text-gray-900 dark:text-white truncate text-sm">{{ $mappedField->label }}</span>
                            @else
                                <x-filament::icon icon="heroicon-o-squares-2x2" class="w-3.5 h-3.5 text-gray-400 shrink-0" />
                                <span class="flex-1 text-left text-gray-400 truncate text-sm">Select attribute</span>
                            @endif

                            @if ($hasMappingDisplay)
                                <button
                                    type="button"
                                    wire:click.stop="unmapColumn('{{ addslashes($header) }}')"
                                    @click.stop
                                    class="p-0.5 rounded hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 shrink-0"
                                >
                                    <x-filament::icon icon="heroicon-o-x-mark" class="w-3.5 h-3.5" />
                                </button>
                            @else
                                <x-filament::icon icon="heroicon-o-chevron-down" class="w-3.5 h-3.5 text-gray-400 shrink-0" />
                            @endif
                        </div>

                        {{-- Dropdown Panel --}}
                        <div
                            x-show="activeDropdown === '{{ $dropdownId }}'"
                            x-transition:enter="transition ease-out duration-100"
                            x-transition:enter-start="opacity-0 scale-95"
                            x-transition:enter-end="opacity-100 scale-100"
                            x-transition:leave="transition ease-in duration-75"
                            x-transition:leave-start="opacity-100 scale-100"
                            x-transition:leave-end="opacity-0 scale-95"
                            class="absolute left-0 z-50 mt-1 w-56 rounded-md bg-white dark:bg-gray-900 shadow-lg ring-1 ring-black/5 dark:ring-white/10"
                            x-cloak
                        >
                            {{-- Search --}}
                            <div class="p-1.5 border-b border-gray-100 dark:border-gray-800">
                                <div class="relative">
                                    <x-filament::icon icon="heroicon-o-magnifying-glass" class="absolute left-2 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400" />
                                    <input
                                        type="text"
                                        placeholder="Search..."
                                        class="w-full pl-7 pr-2 py-1 text-xs bg-gray-50 dark:bg-gray-800 border-0 rounded text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-1 focus:ring-primary-500"
                                        @input="$dispatch('search-{{ md5($header) }}', $el.value)"
                                    />
                                </div>
                            </div>

                            {{-- Options --}}
                            <div class="max-h-52 overflow-y-auto p-1" x-data="{ search: '' }" @search-{{ md5($header) }}.window="search = $event.detail">
                                {{-- Fields --}}
                                <div class="px-2 py-1">
                                    <span class="text-[10px] font-medium text-gray-400 uppercase tracking-wide">Fields</span>
                                </div>
                                @foreach ($this->allFields as $field)
                                    @php
                                        $isSelected = $mapping !== null && $mapping->isFieldMapping() && $mapping->target === $field->key;
                                        $isMapped = in_array($field->key, $mappedFieldKeys) && !$isSelected;
                                    @endphp
                                    <button
                                        type="button"
                                        x-show="!search || '{{ strtolower($field->label) }}'.includes(search.toLowerCase())"
                                        wire:click="mapToField('{{ addslashes($header) }}', '{{ $field->key }}')"
                                        @click="closeDropdown()"
                                        :disabled="{{ $isMapped ? 'true' : 'false' }}"
                                        class="w-full flex items-center gap-1.5 px-2 py-1 text-xs rounded transition-colors
                                            {{ $isSelected ? 'bg-primary-50 dark:bg-primary-950/50 text-primary-700 dark:text-primary-300' : '' }}
                                            {{ $isMapped ? 'opacity-40 cursor-not-allowed' : 'hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-700 dark:text-gray-200' }}"
                                    >
                                        @if ($isSelected)
                                            <x-filament::icon icon="heroicon-s-check" class="w-3.5 h-3.5 text-primary-500 shrink-0" />
                                        @endif
                                        <span class="truncate">{{ $field->label }}{{ $field->required ? ' *' : '' }}</span>
                                        @if ($isMapped)
                                            <span class="ml-auto text-[10px] text-gray-400">mapped</span>
                                        @endif
                                    </button>
                                @endforeach

                                {{-- Relationships --}}
                                @if ($this->hasRelationships)
                                    <div class="px-2 py-1 mt-0.5 border-t border-gray-100 dark:border-gray-800">
                                        <span class="text-[10px] font-medium text-gray-400 uppercase tracking-wide">Link to Records</span>
                                    </div>
                                    @foreach ($this->relationships as $relName => $field)
                                        @php $isRelSel = $isUsedByRelationship && $relationshipMapping['relName'] === $relName; @endphp
                                        <div
                                            x-show="!search || '{{ strtolower($field->label) }}'.includes(search.toLowerCase())"
                                            @mouseenter="showSubmenu('{{ $relName }}', $event)"
                                            @mouseleave="hideSubmenu()"
                                        >
                                            <button
                                                type="button"
                                                class="w-full flex items-center gap-1.5 px-2 py-1 text-xs rounded transition-colors
                                                    {{ $isRelSel ? 'bg-primary-50 dark:bg-primary-950/50 text-primary-700 dark:text-primary-300' : 'hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-700 dark:text-gray-200' }}"
                                            >
                                                <x-filament::icon icon="{{ $field->icon() }}" class="w-3.5 h-3.5 text-gray-400 shrink-0" />
                                                <span class="flex-1 text-left">{{ $field->label }}</span>
                                                <x-filament::icon icon="heroicon-s-chevron-right" class="w-3.5 h-3.5 text-gray-400 shrink-0" />
                                            </button>
                                        </div>
                                    @endforeach
                                @endif
                            </div>
                        </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Preview Panel --}}
        <div class="w-48 shrink-0 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden flex flex-col max-h-72">
            <div class="px-2.5 py-1.5 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                <span class="text-xs font-medium text-gray-900 dark:text-white truncate" x-text="hoveredColumn"></span>
            </div>
            <div class="flex-1 overflow-y-auto">
                @foreach ($headers as $header)
                    <div x-show="hoveredColumn === '{{ addslashes($header) }}'" x-cloak>
                        @foreach ($this->previewValues($header, 5) as $value)
                            <div class="px-2.5 py-1.5 border-b border-gray-100 dark:border-gray-800 text-xs text-gray-600 dark:text-gray-300 truncate">
                                {{ $value ?: '(blank)' }}
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Teleported Submenu --}}
    @if ($this->hasRelationships)
        @foreach ($this->relationships as $relName => $field)
            <template x-teleport="body">
                <div
                    x-show="activeSubmenu === '{{ $relName }}'"
                    x-transition.opacity.duration.100ms
                    :style="{ position: 'fixed', top: submenuPosition.top, left: submenuPosition.left, zIndex: 9999 }"
                    @mouseenter="keepSubmenu()"
                    @mouseleave="hideSubmenu()"
                    class="w-52 rounded-lg bg-white dark:bg-gray-900 shadow-lg ring-1 ring-black/5 dark:ring-white/10"
                    x-cloak
                >
                    <div class="px-3 py-2 border-b border-gray-100 dark:border-gray-800">
                        <span class="text-[10px] font-medium text-gray-400 uppercase tracking-wide">Match by</span>
                    </div>
                    <div class="p-1.5 space-y-0.5">
                        @foreach ($field->matchableFields as $matcher)
                            <button
                                type="button"
                                @click="$wire.mapToRelationship(activeDropdownColumn, '{{ $matcher->field }}', '{{ $relName }}'); closeDropdown()"
                                :class="isMatcherSelected('{{ $relName }}', '{{ $matcher->field }}')
                                    ? 'bg-primary-50 dark:bg-primary-950/50'
                                    : 'hover:bg-gray-50 dark:hover:bg-gray-800'"
                                class="w-full flex items-start gap-2.5 px-2.5 py-2 text-left rounded-md transition-colors"
                            >
                                {{-- Checkmark space - always reserve width for alignment --}}
                                <div class="w-4 h-4 shrink-0 mt-0.5 flex items-center justify-center">
                                    <x-filament::icon
                                        icon="heroicon-s-check"
                                        class="w-4 h-4 text-primary-500"
                                        x-show="isMatcherSelected('{{ $relName }}', '{{ $matcher->field }}')"
                                        x-cloak
                                    />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-1.5">
                                        <span
                                            :class="isMatcherSelected('{{ $relName }}', '{{ $matcher->field }}')
                                                ? 'text-primary-700 dark:text-primary-300'
                                                : 'text-gray-900 dark:text-white'"
                                            class="text-sm font-medium"
                                        >{{ $matcher->label }}</span>
                                        @if ($matcher->createsNew)
                                            <span class="px-1.5 py-0.5 text-[10px] font-medium rounded bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-400">creates</span>
                                        @endif
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $matcher->description() }}</p>
                                </div>
                            </button>
                        @endforeach
                    </div>
                </div>
            </template>
        @endforeach
    @endif

    {{-- Required Fields Warning --}}
    @if ($this->unmappedRequired->isNotEmpty())
        @php
            $unmappedLabels = collect($this->unmappedRequired->all())
                ->map(fn ($field) => $field->label)
                ->values();
        @endphp
        <div class="flex items-start gap-2 p-3 rounded-lg bg-warning-50 dark:bg-warning-950/50 border border-warning-200 dark:border-warning-800">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-5 h-5 text-warning-500 shrink-0" />
            <div class="text-sm">
                <span class="font-medium text-warning-800 dark:text-warning-200">Required fields not mapped:</span>
                <span class="text-warning-700 dark:text-warning-300">{{ $unmappedLabels->join(', ') }}</span>
            </div>
        </div>
    @endif

    {{-- Navigation --}}
    <div class="flex justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
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
