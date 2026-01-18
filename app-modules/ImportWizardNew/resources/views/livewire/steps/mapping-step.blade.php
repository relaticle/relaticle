<div
    class="space-y-4"
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
                        <div
                            class="w-52 relative"
                            @click.outside="if (activeDropdown === '{{ $dropdownId }}' && !activeSubmenu) closeDropdown()"
                        >
                            <div
                                class="w-full flex items-center gap-1.5 px-2 py-1 text-sm rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 hover:border-gray-300 dark:hover:border-gray-600 transition-colors cursor-pointer"
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
                                    class="p-0.5 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 shrink-0 transition-colors"
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
                            class="absolute left-0 z-50 mt-1 w-52 rounded-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 overflow-hidden"
                            x-cloak
                        >
                            {{-- Search Header --}}
                            <div class="px-2 py-1.5 border-b border-gray-200 dark:border-gray-700">
                                <div class="relative flex items-center">
                                    <x-filament::icon icon="heroicon-o-magnifying-glass" class="absolute left-0 w-3.5 h-3.5 text-gray-400 pointer-events-none" />
                                    <input
                                        type="text"
                                        placeholder="Search..."
                                        class="w-full h-6 pl-5 pr-1 text-xs bg-transparent text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none"
                                        x-ref="search-{{ md5($header) }}"
                                        x-effect="if (activeDropdown === '{{ $dropdownId }}') $nextTick(() => $el.focus())"
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
                                        class="w-full flex items-center gap-1.5 px-2 py-1 text-xs rounded-md transition-colors
                                            {{ $isSelected ? 'bg-primary-50 dark:bg-primary-950/50 text-primary-700 dark:text-primary-300' : '' }}
                                            {{ $isMapped ? 'opacity-40 cursor-not-allowed' : 'hover:bg-gray-50 dark:hover:bg-gray-800 text-gray-700 dark:text-gray-200' }}"
                                    >
                                        @if ($isSelected)
                                            <x-filament::icon icon="heroicon-s-check" class="w-3 h-3 text-primary-500 shrink-0" />
                                        @endif
                                        <span class="truncate">{{ $field->label }}{{ $field->required ? ' *' : '' }}</span>
                                        @if ($isMapped)
                                            <span class="ml-auto text-[10px] text-gray-400">mapped</span>
                                        @endif
                                    </button>
                                @endforeach

                                {{-- Relationships --}}
                                @if ($this->hasRelationships)
                                    <div class="px-2 py-1 mt-0.5 border-t border-gray-200 dark:border-gray-700">
                                        <span class="text-[10px] font-medium text-gray-400 uppercase tracking-wide">Link to Records</span>
                                    </div>
                                    @foreach ($this->relationships as $relName => $field)
                                        @php
                                            $isRelSel = $isUsedByRelationship && $relationshipMapping['relName'] === $relName;
                                            $isRelMapped = $this->isRelationshipMapped($relName) && !$isRelSel;
                                        @endphp
                                        <div
                                            x-show="!search || '{{ strtolower($field->label) }}'.includes(search.toLowerCase())"
                                            @if (!$isRelMapped)
                                                @mouseenter="showSubmenu('{{ $relName }}', $event)"
                                                @mouseleave="hideSubmenu()"
                                            @endif
                                        >
                                            <button
                                                type="button"
                                                @if ($isRelMapped) disabled @endif
                                                class="w-full flex items-center gap-1.5 px-2 py-1 text-xs rounded-md transition-colors
                                                    {{ $isRelSel ? 'bg-primary-50 dark:bg-primary-950/50 text-primary-700 dark:text-primary-300' : '' }}
                                                    {{ $isRelMapped ? 'opacity-40 cursor-not-allowed' : 'hover:bg-gray-50 dark:hover:bg-gray-800 text-gray-700 dark:text-gray-200' }}"
                                            >
                                                <x-filament::icon icon="{{ $field->icon() }}" class="w-3.5 h-3.5 text-gray-400 shrink-0" />
                                                <span class="flex-1 text-left">{{ $field->label }}</span>
                                                @if ($isRelMapped)
                                                    <span class="text-[10px] text-gray-400">mapped</span>
                                                @else
                                                    <x-filament::icon icon="heroicon-s-chevron-right" class="w-3.5 h-3.5 text-gray-400 shrink-0" />
                                                @endif
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

    {{-- Teleported Submenu --}}
    @if ($this->hasRelationships)
        @foreach ($this->relationships as $relName => $field)
            <template x-teleport="body">
                <div
                    x-show="activeSubmenu === '{{ $relName }}'"
                    x-transition.opacity.duration.100ms
                    :style="{ position: 'fixed', top: submenuPosition.top, left: submenuPosition.left }"
                    @mouseenter="keepSubmenu()"
                    @mouseleave="hideSubmenu()"
                    class="w-56 rounded-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 overflow-hidden z-[60]"
                    x-cloak
                >
                    <div class="px-3 py-2 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                        <span class="text-[10px] font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Match by</span>
                    </div>
                    <div class="p-1.5">
                        @foreach ($field->matchableFields as $matcher)
                            <button
                                type="button"
                                @click="$wire.mapToRelationship(activeDropdownColumn, '{{ $matcher->field }}', '{{ $relName }}'); closeDropdown()"
                                :class="isMatcherSelected('{{ $relName }}', '{{ $matcher->field }}')
                                    ? 'bg-primary-50 dark:bg-primary-950/50'
                                    : 'hover:bg-gray-50 dark:hover:bg-gray-800'"
                                class="w-full px-2.5 py-2 text-left rounded-md transition-colors"
                            >
                                <div class="flex items-center gap-1.5">
                                    <x-filament::icon
                                        icon="heroicon-s-check"
                                        class="w-3.5 h-3.5 text-primary-500 shrink-0"
                                        x-show="isMatcherSelected('{{ $relName }}', '{{ $matcher->field }}')"
                                        x-cloak
                                    />
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
                                <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ $matcher->description() }}</p>
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
