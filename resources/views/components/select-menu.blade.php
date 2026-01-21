@props([
    'options' => [],
    'multiple' => false,
    'searchable' => true,
    'placeholder' => 'Select...',
    'disabled' => false,
    'label' => null,
    'icon' => null,
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

    // Extract wire:model for Livewire integration
    $wireModel = $attributes->wire('model')->value();
    $hasLiveModifier = $attributes->wire('model')->hasModifier('live');
@endphp

<div
    :data-state="open ? 'open' : 'closed'"
    @if($wireModel)
    wire:ignore
    @endif
    x-data="selectMenu({
        multiple: @js($multiple),
        options: @js($normalizedOptions),
        disabled: @js($disabled),
        searchable: @js($searchable),
        @if($wireModel)
        state: $wire.$entangle('{{ $wireModel }}'{{ $hasLiveModifier ? ', { live: true }' : '' }}),
        @else
        state: @js($multiple ? [] : null),
        @endif
    })"
    x-on:click.outside="close()"
    x-on:keydown.esc="open && (close(), $event.stopPropagation())"
    x-on:keydown="onKeydown($event)"
    {{ $attributes->whereDoesntStartWith('wire:model')->merge(['class' => 'relative']) }}
>
    {{-- Hidden live region for screen reader announcements --}}
    <div x-ref="announcer" aria-live="polite" aria-atomic="true" class="sr-only"></div>

    {{-- Optional visible label --}}
    @if ($label)
        <label :id="$id('label')" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
            {{ $label }}
        </label>
    @endif

    {{-- Trigger Button --}}
    <button
        x-ref="trigger"
        type="button"
        role="combobox"
        :id="$id('button')"
        aria-haspopup="listbox"
        :aria-expanded="open ? 'true' : 'false'"
        :aria-controls="$id('listbox')"
        :aria-activedescendant="activeDescendant"
        @if ($label)
            :aria-labelledby="$id('label')"
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
        @if ($icon)
            <x-filament::icon
                :icon="$icon"
                class="w-4 h-4 text-gray-400 shrink-0"
                aria-hidden="true"
            />
        @endif

        @if ($multiple)
            <span
                class="flex-1 text-left truncate text-sm"
                :class="hasValue ? 'text-gray-900 dark:text-white' : 'text-gray-400'"
                x-text="displayText || '{{ $placeholder }}'"
            ></span>
        @else
            <span
                class="flex-1 text-left truncate text-sm"
                :class="hasValue ? 'text-gray-900 dark:text-white' : 'text-gray-400'"
                x-text="displayText || '{{ $placeholder }}'"
            ></span>
        @endif

        <x-filament::icon
            icon="heroicon-o-chevron-down"
            class="w-3.5 h-3.5 text-gray-400 shrink-0 transition-transform duration-150"
            x-bind:class="open && 'rotate-180'"
            aria-hidden="true"
        />
    </button>

    {{-- Dropdown Panel --}}
    <div
        x-cloak
        x-float.placement.bottom-start.flip.offset="{ offset: 4 }"
        x-transition:enter-start="opacity-0"
        x-transition:leave-end="opacity-0"
        x-ref="panel"
        class="absolute z-50 w-full min-w-[200px] rounded-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-lg ring-1 ring-black/5 dark:ring-white/5 overflow-hidden transition"
    >
        @if ($searchable)
            <div class="relative border-b border-gray-200 dark:border-gray-700">
                <x-filament::icon icon="heroicon-o-magnifying-glass" class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400 pointer-events-none" aria-hidden="true" />
                <input
                    type="text"
                    placeholder="Search..."
                    aria-label="Search options"
                    :aria-controls="$id('listbox')"
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
            :id="$id('listbox')"
            :aria-multiselectable="multiple ? 'true' : 'false'"
            :aria-labelledby="$id('button')"
            tabindex="-1"
            class="max-h-56 overflow-y-auto p-1 focus:outline-none"
        >
            <template x-for="(option, index) in filteredOptions" :key="option.value">
                <li
                    role="option"
                    :id="getOptionId(index)"
                    :aria-selected="isSelected(option.value) ? 'true' : 'false'"
                    :data-highlighted="activeIndex === index ? '' : undefined"
                    :data-selected="isSelected(option.value) ? '' : undefined"
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

@once
@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('selectMenu', (config) => ({
        open: false,
        search: '',
        state: config.state,
        multiple: config.multiple,
        options: config.options,
        disabled: config.disabled,
        searchable: config.searchable,
        activeIndex: -1,
        documentClickListener: null,

        init() {
            // Sync open state when panel visibility changes
            this.$watch('open', (isOpen) => {
                if (isOpen) {
                    this.activeIndex = this.getInitialActiveIndex();
                    this.$nextTick(() => {
                        this.scrollActiveIntoView();
                        if (this.searchable) {
                            this.$refs.searchInput?.focus();
                        }
                    });
                } else {
                    this.activeIndex = -1;
                    this.search = '';
                }
            });

            this.documentClickListener = (event) => {
                if (this.open && !this.$el.contains(event.target)) {
                    this.close();
                }
            };
            document.addEventListener('click', this.documentClickListener);
        },

        destroy() {
            if (this.documentClickListener) {
                document.removeEventListener('click', this.documentClickListener);
            }
        },

        get selected() {
            return this.state;
        },

        set selected(value) {
            this.state = value;
        },

        get displayText() {
            if (!this.hasValue) return null;
            if (this.multiple && Array.isArray(this.selected)) {
                return this.selected.map(v => this.getOption(v)?.label || v).join(', ');
            }
            const opt = this.getOption(this.selected);
            return opt ? opt.label : String(this.selected ?? '');
        },

        get hasValue() {
            if (this.multiple) {
                return Array.isArray(this.selected) && this.selected.length > 0;
            }
            return this.selected !== null && this.selected !== undefined && this.selected !== '';
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

        get activeDescendant() {
            if (!this.open || this.activeIndex < 0 || this.activeIndex >= this.filteredOptions.length) {
                return null;
            }
            return this.getOptionId(this.activeIndex);
        },

        getInitialActiveIndex() {
            if (!this.hasValue) return 0;
            if (this.multiple && Array.isArray(this.selected) && this.selected.length > 0) {
                const index = this.filteredOptions.findIndex(o => this.valuesEqual(o.value, this.selected[0]));
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
            if (this.open) {
                this.close();
            } else {
                this.openPanel();
            }
        },

        openPanel() {
            if (this.disabled || this.open) return;
            this.$refs.panel?.open(this.$refs.trigger);
            this.open = true;
        },

        close() {
            if (!this.open) return;
            this.$refs.panel?.close();
            this.open = false;
        },

        select(value) {
            if (this.multiple) {
                let current = Array.isArray(this.selected) ? [...this.selected] : [];
                if (this.isSelected(value)) {
                    current = current.filter(v => !this.valuesEqual(v, value));
                } else {
                    current.push(value);
                }
                this.selected = current;
                this.announceSelection(value);
            } else {
                this.selected = value;
                this.close();
            }
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
            return this.options.find(o => this.valuesEqual(o.value, value)) || null;
        },

        getOptionId(index) {
            return this.$id('option-' + index);
        },

        onKeydown(event) {
            if (this.disabled) return;

            switch (event.key) {
                case 'ArrowDown':
                    event.preventDefault();
                    event.stopPropagation();
                    if (this.open) {
                        this.focusNext();
                    } else {
                        this.openPanel();
                    }
                    break;
                case 'ArrowUp':
                    event.preventDefault();
                    event.stopPropagation();
                    if (this.open) {
                        this.focusPrevious();
                    } else {
                        this.openPanel();
                    }
                    break;
                case 'Home':
                    if (this.open) { event.preventDefault(); this.focusFirst(); }
                    break;
                case 'End':
                    if (this.open) { event.preventDefault(); this.focusLast(); }
                    break;
                case 'Enter':
                    event.preventDefault();
                    if (this.open && this.activeIndex >= 0 && this.activeIndex < this.filteredOptions.length) {
                        this.select(this.filteredOptions[this.activeIndex].value);
                    } else if (!this.open) {
                        this.openPanel();
                    }
                    break;
                case ' ':
                    // Don't intercept space when typing in search input
                    if (this.searchable && document.activeElement === this.$refs.searchInput) {
                        return;
                    }
                    // WAI-ARIA: Space opens listbox but does NOT select (only Enter selects)
                    if (!this.open) {
                        event.preventDefault();
                        this.openPanel();
                    }
                    break;
                case 'Tab':
                    if (this.open) this.close();
                    break;
            }
        },

        onSearchKeydown(event) {
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
                    event.stopPropagation();
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
                    this.$refs.trigger?.focus();
                    break;
            }
        },

        onSearchInput() {
            this.activeIndex = this.filteredOptions.length > 0 ? 0 : -1;
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
                const activeOption = this.$refs.listbox?.querySelector('[data-highlighted]');
                if (activeOption) {
                    activeOption.scrollIntoView({ block: 'nearest' });
                }
            });
        },

        announceSelection(value) {
            const option = this.getOption(value);
            if (option && this.$refs.announcer) {
                const action = this.isSelected(value) ? 'selected' : 'deselected';
                let message = option.label + ' ' + action;

                if (this.multiple && Array.isArray(this.selected)) {
                    const count = this.selected.length;
                    message += `. ${count} item${count !== 1 ? 's' : ''} total`;
                }

                this.$refs.announcer.textContent = message;
            }
        },
    }));
});
</script>
@endpush
@endonce
