@props([
    'fields',
    'entityLinks' => [],
    'selected' => null,
    'mappedFieldKeys' => [],
    'mappedEntityLinkMatchers' => [],
    'column',
    'placeholder' => 'Select attribute',
])

@php
    $dropdownId = "fs-{$column}";
    $isFieldMapping = $selected?->isFieldMapping() ?? false;
    $isEntityLinkMapping = $selected?->isEntityLinkMapping() ?? false;
    $selectedField = $isFieldMapping ? $fields->get($selected->target) : null;
    $selectedEntityLink = $isEntityLinkMapping ? ($entityLinks[$selected->entityLink] ?? null) : null;
    $selectedMatcher = $selectedEntityLink?->getMatcher($selected->target);
    $hasValue = $selectedField !== null || $selectedEntityLink !== null;

    $sortedItems = collect($fields->all())
        ->map(fn($f) => (object)[
            'type' => 'field',
            'item' => $f,
            'key' => $f->key,
            'order' => $f->sortOrder ?? -1
        ])
        ->concat(
            collect($entityLinks)->map(fn($link, $key) => (object)[
                'type' => 'link',
                'item' => $link,
                'key' => $key,
                'order' => $link->sortOrder ?? -1
            ])
        )
        ->sortBy('order');
@endphp

<div
    x-data="{
        open: false,
        search: '',
        activeSubmenu: null,
        submenuPosition: { top: 0, left: 0 },
        submenuTimeout: null,
        dropdownId: {{ Js::from($dropdownId) }},
        init() {
            this.$watch('open', (isOpen) => {
                if (isOpen) {
                    this.$nextTick(() => this.$refs.searchInput?.focus());
                } else {
                    this.search = '';
                    this.activeSubmenu = null;
                }
            });
        },
        toggle() {
            if (this.open) {
                this.close();
            } else {
                this.openPanel();
            }
        },
        openPanel() {
            if (this.open) return;
            this.$refs.panel?.open?.(this.$refs.trigger);
            this.open = true;
        },
        close() {
            if (!this.open) return;
            this.$refs.panel?.close?.();
            this.open = false;
        },
        showSubmenu(name, event) {
            clearTimeout(this.submenuTimeout);
            const rect = event.currentTarget.getBoundingClientRect();
            this.submenuPosition = { top: rect.top, left: rect.right + 4 };
            this.activeSubmenu = name;

            this.$nextTick(() => {
                const el = document.querySelector('[data-submenu=' + CSS.escape(this.dropdownId + '-' + name) + ']');
                if (!el) return;

                const margin = 8;
                const sh = el.offsetHeight;
                const sw = el.offsetWidth;
                const vh = window.innerHeight;
                const vw = window.innerWidth;
                let { top, left } = this.submenuPosition;

                if (top + sh > vh - margin) {
                    top = Math.max(margin, vh - sh - margin);
                }

                if (left + sw > vw - margin) {
                    left = rect.left - sw - 4;
                }

                if (top !== this.submenuPosition.top || left !== this.submenuPosition.left) {
                    this.submenuPosition = { top, left };
                }
            });
        },
        hideSubmenu() {
            this.submenuTimeout = setTimeout(() => { this.activeSubmenu = null; }, 150);
        },
        keepSubmenu() {
            clearTimeout(this.submenuTimeout);
        },
        selectField(fieldKey) {
            this.$dispatch('field-selected', { column: {{ Js::from($column) }}, fieldKey });
            this.close();
        },
        selectEntityLink(entityLinkKey, matcherKey) {
            this.$dispatch('entity-link-selected', { column: {{ Js::from($column) }}, entityLinkKey, matcherKey });
            this.close();
        },
        clear() {
            this.$dispatch('field-cleared', { column: {{ Js::from($column) }} });
        }
    }"
    @click.outside="close()"
    @keydown.escape.window="open && close()"
    class="w-52 relative"
>
    {{-- Trigger Button --}}
    <button
        type="button"
        role="combobox"
        aria-haspopup="listbox"
        :aria-expanded="open"
        aria-label="Map column {{ $column }} to field"
        class="w-full flex items-center gap-1.5 px-2.5 py-1.5 text-sm rounded-lg border bg-white dark:bg-gray-900 focus:outline-none transition-colors cursor-pointer"
        :class="open
            ? 'border-primary-500 dark:border-primary-400 ring-2 ring-primary-500/20'
            : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'"
        x-ref="trigger"
        @click="toggle()"
    >
        @if ($selectedEntityLink)
            <x-filament::icon icon="{{ $selectedEntityLink->icon() }}" class="w-3.5 h-3.5 text-gray-500 dark:text-gray-400 shrink-0" />
            <span class="flex-1 text-left text-gray-900 dark:text-white truncate text-sm">
                {{ $selectedEntityLink->label }}
                @if ($selectedMatcher)
                    <x-filament::icon icon="heroicon-m-chevron-right" class="inline w-3 h-3 text-gray-400 dark:text-gray-500 mx-0.5" />
                    <span class="text-gray-500 dark:text-gray-400">{{ $selectedMatcher->label }}</span>
                @endif
            </span>
        @elseif ($selectedField)
            <x-filament::icon icon="{{ $selectedField->icon ?? 'heroicon-o-squares-2x2' }}" class="w-3.5 h-3.5 text-gray-500 dark:text-gray-400 shrink-0" />
            <span class="flex-1 text-left text-gray-900 dark:text-white truncate text-sm">{{ $selectedField->label }}</span>
        @else
            <x-filament::icon icon="heroicon-o-squares-2x2" class="w-3.5 h-3.5 text-gray-400 shrink-0" />
            <span class="flex-1 text-left text-gray-400 truncate text-sm">{{ $placeholder }}</span>
        @endif

        @if ($hasValue)
            <span
                role="button"
                tabindex="0"
                @click.stop="clear()"
                @keydown.enter.stop="clear()"
                @keydown.space.stop="clear()"
                aria-label="Clear mapping"
                class="p-0.5 rounded hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 shrink-0 transition-colors"
            >
                <x-filament::icon icon="heroicon-o-x-mark" class="w-3.5 h-3.5" />
            </span>
        @else
            <x-filament::icon
                icon="heroicon-o-chevron-down"
                class="w-3.5 h-3.5 text-gray-400 shrink-0 transition-transform duration-150"
                x-bind:class="open && 'rotate-180'"
            />
        @endif
    </button>

    {{-- Dropdown Panel --}}
    <div
        x-ref="panel"
        x-cloak
        x-show="open"
        x-float.placement.bottom-start.flip.offset.teleport="{ offset: 4 }"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        role="listbox"
        aria-label="Available fields"
        class="absolute z-50 w-52 rounded-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-lg ring-1 ring-black/5 dark:ring-white/5 overflow-hidden"
    >
        {{-- Search Header --}}
        <div class="relative border-b border-gray-200 dark:border-gray-700">
            <x-filament::icon icon="heroicon-o-magnifying-glass" class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400 pointer-events-none" />
            <input
                type="text"
                placeholder="Search..."
                aria-label="Search fields"
                class="w-full h-8 pl-8 pr-2 text-xs bg-transparent text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none"
                x-ref="searchInput"
                x-model="search"
            />
        </div>

        {{-- Options (unified sorted list) --}}
        <div class="max-h-56 overflow-y-auto p-1">
            @foreach ($sortedItems as $entry)
                @if ($entry->type === 'field')
                    @php
                        $field = $entry->item;
                        $isSelected = $isFieldMapping && $selected->target === $field->key;
                        $isMapped = in_array($field->key, $mappedFieldKeys) && !$isSelected;
                    @endphp
                    <button
                        type="button"
                        role="option"
                        :aria-selected="{{ $isSelected ? 'true' : 'false' }}"
                        x-show="!search || {{ Js::from(strtolower($field->label)) }}.includes(search.toLowerCase())"
                        @click="selectField('{{ $field->key }}')"
                        {{ $isMapped ? 'disabled' : '' }}
                        class="w-full flex items-center gap-1.5 px-2 py-1.5 text-xs rounded-md transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/50 focus-visible:bg-gray-50 dark:focus-visible:bg-gray-800
                            {{ $isSelected ? 'bg-primary-50 dark:bg-primary-950/50 text-primary-700 dark:text-primary-300' : '' }}
                            {{ $isMapped ? 'opacity-40 cursor-not-allowed' : 'hover:bg-gray-50 dark:hover:bg-gray-800 text-gray-700 dark:text-gray-200' }}"
                    >
                        <x-filament::icon icon="{{ $field->icon ?? 'heroicon-o-squares-2x2' }}" class="w-3.5 h-3.5 text-gray-400 shrink-0" />
                        <span class="truncate flex-1 text-left">{{ $field->label }}{{ $field->required ? ' *' : '' }}</span>
                        @if ($isMapped)
                            <span class="text-[9px] text-gray-400 dark:text-gray-500 italic">in use</span>
                        @elseif ($isSelected)
                            <x-filament::icon icon="heroicon-s-check" class="w-3 h-3 text-primary-500 shrink-0" />
                        @endif
                    </button>
                @else
                    @php
                        $linkKey = $entry->key;
                        $link = $entry->item;
                        $isLinkSelected = $isEntityLinkMapping && $selected->entityLink === $linkKey;
                        $linkMappedMatchers = $mappedEntityLinkMatchers[$linkKey] ?? [];
                        $isLinkFullyMapped = count($linkMappedMatchers) >= count($link->matchableFields) && !$isLinkSelected;
                    @endphp
                    <div
                        x-show="!search || {{ Js::from(strtolower($link->label)) }}.includes(search.toLowerCase())"
                        @if (!$isLinkFullyMapped)
                            @mouseenter="showSubmenu('{{ $linkKey }}', $event)"
                            @mouseleave="hideSubmenu()"
                        @endif
                    >
                        <button
                            type="button"
                            role="option"
                            aria-haspopup="menu"
                            {{ $isLinkFullyMapped ? 'disabled aria-disabled=true' : '' }}
                            @if (!$isLinkFullyMapped)
                                @focus="showSubmenu('{{ $linkKey }}', $event)"
                                @blur="hideSubmenu()"
                                @keydown.enter.prevent="showSubmenu('{{ $linkKey }}', $event)"
                                @keydown.space.prevent="showSubmenu('{{ $linkKey }}', $event)"
                                @keydown.arrow-right.prevent="showSubmenu('{{ $linkKey }}', $event)"
                            @endif
                            class="w-full flex items-center gap-1.5 px-2 py-1.5 text-xs rounded-md transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/50 focus-visible:bg-gray-50 dark:focus-visible:bg-gray-800
                                {{ $isLinkSelected ? 'bg-primary-50 dark:bg-primary-950/50 text-primary-700 dark:text-primary-300' : '' }}
                                {{ $isLinkFullyMapped ? 'opacity-40 cursor-not-allowed' : 'hover:bg-gray-50 dark:hover:bg-gray-800 text-gray-700 dark:text-gray-200' }}"
                        >
                            <x-filament::icon icon="{{ $link->icon() }}" class="w-3.5 h-3.5 text-gray-400 shrink-0" />
                            <span class="flex-1 text-left">{{ $link->label }}</span>
                            @if ($isLinkFullyMapped)
                                <span class="text-[9px] text-gray-400 dark:text-gray-500 italic">in use</span>
                            @else
                                @if ($isLinkSelected)
                                    <x-filament::icon icon="heroicon-s-check" class="w-3 h-3 text-primary-500 shrink-0" />
                                @endif
                                <x-filament::icon icon="heroicon-s-chevron-right" class="w-3.5 h-3.5 shrink-0 {{ $isLinkSelected ? 'text-primary-400' : 'text-gray-400' }}" />
                            @endif
                        </button>
                    </div>
                @endif
            @endforeach
        </div>
    </div>

    {{-- Teleported Submenus --}}
    @foreach ($entityLinks as $linkKey => $link)
        @php
            $isLinkSelected = $isEntityLinkMapping && $selected->entityLink === $linkKey;
        @endphp
        <template x-teleport="body">
            <div
                data-submenu="{{ $dropdownId }}-{{ $linkKey }}"
                x-cloak
                x-show="activeSubmenu === '{{ $linkKey }}'"
                x-transition:enter="transition ease-out duration-100"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                :style="{ position: 'fixed', top: submenuPosition.top + 'px', left: submenuPosition.left + 'px' }"
                @mouseenter="keepSubmenu()"
                @mouseleave="hideSubmenu()"
                @keydown.escape.prevent="activeSubmenu = null"
                @keydown.arrow-left.prevent="activeSubmenu = null"
                x-effect="if (activeSubmenu === '{{ $linkKey }}') $nextTick(() => $el.querySelector('button')?.focus())"
                role="menu"
                aria-label="Match options for {{ $link->label }}"
                class="w-56 rounded-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-lg overflow-hidden z-[60]"
            >
                <div class="px-2.5 py-2 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                    <span class="text-[10px] font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Match by</span>
                </div>
                <div class="p-1">
                    @foreach ($link->matchableFields as $matcher)
                        @php
                            $isMatcherSelected = $isLinkSelected && $selected->target === $matcher->field;
                            $globalMappedMatchers = $mappedEntityLinkMatchers[$linkKey] ?? [];
                            $isMatcherUsed = in_array($matcher->field, $globalMappedMatchers) && !$isMatcherSelected;
                        @endphp
                        <button
                            type="button"
                            role="menuitem"
                            @click="selectEntityLink('{{ $linkKey }}', '{{ $matcher->field }}')"
                            @keydown.enter.prevent="selectEntityLink('{{ $linkKey }}', '{{ $matcher->field }}')"
                            @keydown.space.prevent="selectEntityLink('{{ $linkKey }}', '{{ $matcher->field }}')"
                            {{ $isMatcherUsed ? 'disabled' : '' }}
                            class="w-full px-2.5 py-2 text-left rounded-md transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/50 focus-visible:bg-gray-50 dark:focus-visible:bg-gray-800
                                {{ $isMatcherSelected ? 'bg-primary-50 dark:bg-primary-950/50' : 'hover:bg-gray-50 dark:hover:bg-gray-800' }}
                                {{ $isMatcherUsed ? 'opacity-40 cursor-not-allowed' : '' }}"
                        >
                            <div class="flex items-center gap-1.5">
                                @if ($isMatcherSelected)
                                    <x-filament::icon icon="heroicon-s-check" class="w-3 h-3 text-primary-500 shrink-0" />
                                @endif
                                <span class="text-sm font-medium {{ $isMatcherSelected ? 'text-primary-700 dark:text-primary-300' : 'text-gray-900 dark:text-white' }}">
                                    {{ $matcher->label }}
                                </span>
                                @if($isMatcherUsed)
                                    <span class="text-[9px] text-gray-400 dark:text-gray-500 italic ml-auto">in use</span>
                                @endif
                                @if ($matcher->isCreate())
                                    <span class="px-1.5 py-0.5 text-[9px] font-medium rounded bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-400 ml-auto">creates</span>
                                @endif
                            </div>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ $matcher->description() }}</p>
                        </button>
                    @endforeach
                </div>
            </div>
        </template>
    @endforeach
</div>
