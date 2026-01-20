@props([
    'options' => [],
    'selected' => null,
    'multiple' => false,
    'searchable' => true,
    'placeholder' => 'Select...',
    'disabled' => false,
    'label' => null,
])

@php
    // Normalize options to consistent format: [{value, label, description?}]
    $normalizedOptions = collect($options)->map(function ($option, $key) {
        if (is_array($option) && isset($option['value'])) {
            return [
                'value' => $option['value'],
                'label' => (string) ($option['label'] ?? $option['value']),
                'description' => isset($option['description']) ? (string) $option['description'] : null,
            ];
        }
        if (is_string($key)) {
            return ['value' => $key, 'label' => (string) $option, 'description' => null];
        }
        return ['value' => $option, 'label' => (string) $option, 'description' => null];
    })->values()->all();

    // Normalize selected value for Alpine
    $normalizedSelected = $multiple
        ? array_values(array_filter((array) ($selected ?? []), fn ($v) => $v !== null && $v !== ''))
        : $selected;

    // Generate unique IDs for accessibility
    $componentId = 'vs-' . Str::random(8);
    $buttonId = $componentId . '-btn';
    $listboxId = $componentId . '-listbox';
    $labelId = $label ? $componentId . '-label' : null;
@endphp

<div
    x-data="{
        open: false,
        search: '',
        selected: @js($normalizedSelected),
        multiple: @js($multiple),
        options: @js($normalizedOptions),
        disabled: @js($disabled),
        activeIndex: -1,
        componentId: @js($componentId),

        init() {
            // Initialize activeIndex to first selected option when opening
            this.$watch('open', (isOpen) => {
                if (isOpen) {
                    this.activeIndex = this.getInitialActiveIndex();
                    this.$nextTick(() => {
                        this.scrollActiveIntoView();
                    });
                } else {
                    this.activeIndex = -1;
                }
            });
        },

        get multiSelectDisplay() {
            if (!this.multiple || !Array.isArray(this.selected) || this.selected.length === 0) {
                return null;
            }
            return this.selected.map(v => this.getOption(v).label).join(', ');
        },

        getInitialActiveIndex() {
            if (!this.hasValue) return 0;
            if (this.multiple && Array.isArray(this.selected) && this.selected.length > 0) {
                const firstSelected = this.selected[0];
                const index = this.filteredOptions.findIndex(o => this.valuesEqual(o.value, firstSelected));
                return index >= 0 ? index : 0;
            }
            if (!this.multiple && this.selected !== null) {
                const index = this.filteredOptions.findIndex(o => this.valuesEqual(o.value, this.selected));
                return index >= 0 ? index : 0;
            }
            return 0;
        },

        toggle() {
            if (this.disabled) return;
            this.open = !this.open;
            if (this.open && @js($searchable)) {
                this.$nextTick(() => this.$refs.searchInput?.focus());
            }
        },

        close() {
            this.open = false;
            this.search = '';
            this.activeIndex = -1;
        },

        select(value) {
            if (this.multiple) {
                if (!Array.isArray(this.selected)) {
                    this.selected = [];
                }
                if (this.isSelected(value)) {
                    this.selected = this.selected.filter(v => !this.valuesEqual(v, value));
                } else {
                    this.selected = [...this.selected, value];
                }
                // Announce change for screen readers
                this.announceSelection(value);
            } else {
                this.selected = value;
                this.close();
            }
            this.$dispatch('value-changed', { value: this.selected });
        },

        remove(value) {
            if (!Array.isArray(this.selected)) {
                this.selected = [];
                return;
            }
            this.selected = this.selected.filter(v => !this.valuesEqual(v, value));
            this.$dispatch('value-changed', { value: this.selected });
        },

        valuesEqual(a, b) {
            if (a == null && b == null) return true;
            if (a == null || b == null) return false;
            return String(a) === String(b);
        },

        isSelected(value) {
            if (this.multiple) {
                if (!Array.isArray(this.selected)) return false;
                return this.selected.some(v => this.valuesEqual(v, value));
            }
            return this.valuesEqual(this.selected, value);
        },

        getOption(value) {
            const found = this.options.find(o => this.valuesEqual(o.value, value));
            return found || { value, label: String(value ?? ''), description: null };
        },

        get filteredOptions() {
            if (!this.search || !this.search.trim()) return this.options;
            const q = this.search.toLowerCase().trim();
            return this.options.filter(o => {
                const label = String(o.label || '').toLowerCase();
                const desc = o.description ? String(o.description).toLowerCase() : '';
                return label.includes(q) || desc.includes(q);
            });
        },

        get hasValue() {
            if (this.multiple) {
                return Array.isArray(this.selected) && this.selected.length > 0;
            }
            return this.selected !== null && this.selected !== undefined && this.selected !== '';
        },

        get displayLabel() {
            if (!this.hasValue) return null;
            if (this.multiple) return null;
            const opt = this.getOption(this.selected);
            return opt ? opt.label : String(this.selected ?? '');
        },

        get selectedArray() {
            if (!this.multiple) return [];
            return Array.isArray(this.selected) ? this.selected : [];
        },

        getOptionId(index) {
            return this.componentId + '-option-' + index;
        },

        get activeDescendant() {
            if (!this.open || this.activeIndex < 0 || this.activeIndex >= this.filteredOptions.length) {
                return null;
            }
            return this.getOptionId(this.activeIndex);
        },

        // Keyboard Navigation
        onKeydown(event) {
            if (this.disabled) return;

            switch (event.key) {
                case 'ArrowDown':
                    event.preventDefault();
                    event.stopPropagation();
                    if (!this.open) {
                        this.open = true;
                    } else {
                        this.focusNext();
                    }
                    break;

                case 'ArrowUp':
                    event.preventDefault();
                    event.stopPropagation();
                    if (!this.open) {
                        this.open = true;
                    } else {
                        this.focusPrevious();
                    }
                    break;

                case 'Home':
                    if (this.open) {
                        event.preventDefault();
                        this.focusFirst();
                    }
                    break;

                case 'End':
                    if (this.open) {
                        event.preventDefault();
                        this.focusLast();
                    }
                    break;

                case 'Enter':
                    event.preventDefault();
                    if (this.open && this.activeIndex >= 0 && this.activeIndex < this.filteredOptions.length) {
                        this.select(this.filteredOptions[this.activeIndex].value);
                    } else if (!this.open) {
                        this.open = true;
                    }
                    break;

                case ' ':
                    // Space: toggle selection in multi-select, or open in single-select
                    if (this.open && this.activeIndex >= 0) {
                        event.preventDefault();
                        this.select(this.filteredOptions[this.activeIndex].value);
                    } else if (!this.open && !@js($searchable)) {
                        event.preventDefault();
                        this.open = true;
                    }
                    break;

                case 'Escape':
                    if (this.open) {
                        event.preventDefault();
                        event.stopPropagation();
                        this.close();
                        this.$refs.button?.focus();
                    }
                    break;

                case 'Tab':
                    if (this.open) {
                        this.close();
                    }
                    break;

                default:
                    // Type-ahead: if searchable, printable character, and dropdown closed, open and type
                    if (@js($searchable) && !this.open && !event.ctrlKey && !event.metaKey && !event.altKey && event.key.length === 1) {
                        event.preventDefault();
                        this.open = true;
                        this.$nextTick(() => {
                            if (this.$refs.searchInput) {
                                this.$refs.searchInput.focus();
                                this.$refs.searchInput.value = event.key;
                                this.search = event.key;
                                this.onSearchInput();
                            }
                        });
                    }
                    break;
            }
        },

        onSearchKeydown(event) {
            // Handle navigation from search input
            switch (event.key) {
                case 'ArrowDown':
                    event.preventDefault();
                    event.stopPropagation();
                    this.focusNext();
                    break;

                case 'ArrowUp':
                    event.preventDefault();
                    event.stopPropagation();
                    this.focusPrevious();
                    break;

                case 'Enter':
                    event.preventDefault();
                    if (this.activeIndex >= 0 && this.activeIndex < this.filteredOptions.length) {
                        this.select(this.filteredOptions[this.activeIndex].value);
                    } else if (this.filteredOptions.length > 0) {
                        this.select(this.filteredOptions[0].value);
                    }
                    break;

                case 'Escape':
                    event.preventDefault();
                    event.stopPropagation();
                    this.close();
                    this.$refs.button?.focus();
                    break;
            }
        },

        focusNext() {
            const max = this.filteredOptions.length - 1;
            if (max < 0) return;
            this.activeIndex = this.activeIndex >= max ? 0 : this.activeIndex + 1;
            this.scrollActiveIntoView();
        },

        focusPrevious() {
            const max = this.filteredOptions.length - 1;
            if (max < 0) return;
            this.activeIndex = this.activeIndex <= 0 ? max : this.activeIndex - 1;
            this.scrollActiveIntoView();
        },

        focusFirst() {
            if (this.filteredOptions.length === 0) return;
            this.activeIndex = 0;
            this.scrollActiveIntoView();
        },

        focusLast() {
            if (this.filteredOptions.length === 0) return;
            this.activeIndex = this.filteredOptions.length - 1;
            this.scrollActiveIntoView();
        },

        scrollActiveIntoView() {
            this.$nextTick(() => {
                const activeOption = this.$refs.listbox?.querySelector('[data-active=true]');
                if (activeOption) {
                    activeOption.scrollIntoView({ block: 'nearest' });
                }
            });
        },

        announceSelection(value) {
            // For screen readers - the aria-live region will announce
            const option = this.getOption(value);
            const action = this.isSelected(value) ? 'selected' : 'deselected';
            this.$refs.announcer.textContent = option.label + ' ' + action;
        },

        // Reset active index when search changes
        onSearchInput() {
            this.activeIndex = this.filteredOptions.length > 0 ? 0 : -1;
        }
    }"
    x-on:click.outside="close()"
    x-on:keydown="onKeydown($event)"
    wire:ignore.self
    {{ $attributes->merge(['class' => 'relative']) }}
>
    {{-- Hidden live region for screen reader announcements --}}
    <div
        x-ref="announcer"
        aria-live="polite"
        aria-atomic="true"
        class="sr-only"
    ></div>

    {{-- Optional visible label --}}
    @if ($label)
        <label
            id="{{ $labelId }}"
            class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1"
        >
            {{ $label }}
        </label>
    @endif

    {{-- Trigger Button --}}
    <button
        x-ref="button"
        type="button"
        role="combobox"
        id="{{ $buttonId }}"
        aria-haspopup="listbox"
        :aria-expanded="open ? 'true' : 'false'"
        aria-controls="{{ $listboxId }}"
        :aria-activedescendant="activeDescendant"
        @if ($labelId)
            aria-labelledby="{{ $labelId }}"
        @elseif ($label)
            aria-label="{{ $label }}"
        @else
            aria-label="{{ $placeholder }}"
        @endif
        :disabled="disabled"
        @class([
            'w-full h-9 flex items-center gap-1.5 px-2.5 text-sm rounded-lg border focus:outline-none',
            'cursor-not-allowed opacity-50' => $disabled,
            'cursor-pointer' => !$disabled,
        ])
        :class="[
            open
                ? 'border-primary-500 dark:border-primary-400 ring-2 ring-primary-500/20'
                : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600 bg-white dark:bg-gray-900'
        ]"
        x-on:click="toggle()"
    >
        @if ($multiple)
            {{-- Multi-select: show comma-separated labels with truncate --}}
            <span
                class="flex-1 text-left truncate text-sm"
                :class="hasValue ? 'text-gray-900 dark:text-white' : 'text-gray-400'"
                x-text="multiSelectDisplay || '{{ $placeholder }}'"
            ></span>
        @else
            {{-- Single-select: show selected label or placeholder --}}
            <span
                class="flex-1 text-left truncate text-sm"
                :class="hasValue ? 'text-gray-900 dark:text-white' : 'text-gray-400'"
                x-text="displayLabel || '{{ $placeholder }}'"
            ></span>
        @endif

        <x-filament::icon
            icon="heroicon-o-chevron-down"
            class="w-3.5 h-3.5 text-gray-400 shrink-0"
            x-bind:class="open ? 'rotate-180' : ''"
            aria-hidden="true"
        />
    </button>

    {{-- Dropdown Panel --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-1"
        class="absolute left-0 z-50 mt-1 w-full min-w-[200px] rounded-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-lg ring-1 ring-black/5 dark:ring-white/5 overflow-hidden"
        x-cloak
    >
        @if ($searchable)
            {{-- Search Header --}}
            <div class="relative border-b border-gray-200 dark:border-gray-700">
                <x-filament::icon icon="heroicon-o-magnifying-glass" class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400 pointer-events-none" aria-hidden="true" />
                <input
                    type="text"
                    placeholder="Search..."
                    aria-label="Search options"
                    aria-controls="{{ $listboxId }}"
                    :aria-activedescendant="activeDescendant"
                    class="w-full h-8 pl-8 pr-2 text-xs bg-transparent text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none"
                    x-ref="searchInput"
                    x-model="search"
                    x-on:input="onSearchInput()"
                    x-on:keydown="onSearchKeydown($event)"
                />
            </div>
        @endif

        {{-- Listbox --}}
        <ul
            x-ref="listbox"
            role="listbox"
            id="{{ $listboxId }}"
            :aria-multiselectable="multiple ? 'true' : 'false'"
            aria-labelledby="{{ $buttonId }}"
            tabindex="-1"
            class="max-h-56 overflow-y-auto p-1 focus:outline-none"
        >
            <template x-for="(option, index) in filteredOptions" :key="String(option.value) + '-' + index">
                <li
                    role="option"
                    :id="getOptionId(index)"
                    :aria-selected="isSelected(option.value) ? 'true' : 'false'"
                    :data-active="activeIndex === index ? 'true' : 'false'"
                    x-on:click="select(option.value)"
                    x-on:mouseenter="activeIndex = index"
                    class="w-full text-left px-2.5 py-2 rounded-md transition-colors cursor-pointer"
                    :class="[
                        activeIndex === index
                            ? 'bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white'
                            : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800'
                    ]"
                >
                    <div class="flex items-center gap-1.5">
                        <span
                            class="w-4 h-4 shrink-0 flex items-center justify-center transition-opacity duration-75"
                            :class="isSelected(option.value) ? 'opacity-100' : 'opacity-0'"
                            aria-hidden="true"
                        >
                            <x-filament::icon icon="heroicon-s-check" class="w-4 h-4 text-primary-600 dark:text-primary-400" />
                        </span>
                        <span class="truncate flex-1 text-xs" x-text="option.label"></span>
                    </div>
                    <template x-if="option.description">
                        <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5 ml-[22px]" x-text="option.description"></p>
                    </template>
                </li>
            </template>

            {{-- Empty states --}}
            <template x-if="filteredOptions.length === 0 && options.length > 0">
                <li role="option" aria-disabled="true" class="px-2 py-3 text-xs text-gray-500 dark:text-gray-400 text-center">
                    No matching options
                </li>
            </template>

            <template x-if="options.length === 0">
                <li role="option" aria-disabled="true" class="px-2 py-3 text-xs text-gray-500 dark:text-gray-400 text-center">
                    No options available
                </li>
            </template>
        </ul>
    </div>
</div>
