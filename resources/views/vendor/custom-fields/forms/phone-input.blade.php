@php
    $fieldWrapperView = $getFieldWrapperView();
    $isDisabled = $isDisabled();
    $statePath = $getStatePath();
    $allowMultiple = $getAllowMultiple();
    $maxValues = $getMaxValues();
    $addLabel = $getAddLabel();
    $emptyStateLabel = $getEmptyStateLabel();
    $placeholder = $getPlaceholder() ?? '(555) 123-4567';
    $defaultCountry = $getDefaultCountry();
    $countryOptions = $getCountryOptions();
    $countryOptionsWithNames = $getCountryOptionsWithNames();
    $componentId = 'phone-input-' . str_replace('.', '-', $statePath);
    $key = $getKey();
@endphp

<x-dynamic-component
    :component="$fieldWrapperView"
    :field="$field"
    class="fi-fo-phone-input-wrp"
>
    <x-filament::input.wrapper
        :disabled="$isDisabled"
        :valid="! $errors->has($statePath)"
        :attributes="
            \Filament\Support\prepare_inherited_attributes($attributes)
                ->class(['fi-fo-phone-input'])
        "
    >
        <div
            wire:key="{{ $key }}-{{ $isDisabled ? 'disabled' : 'enabled' }}"
            wire:ignore.self
            x-cloak
            x-data="{
                state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
                isOpen: false,
                componentKey: @js($key),
                allowMultiple: @js($allowMultiple),
                maxValues: @js($maxValues),
                isDisabled: @js($isDisabled),
                defaultCountry: @js($defaultCountry),
                countryOptions: @js($countryOptions),
                countryOptionsWithNames: @js($countryOptionsWithNames),
                maxVisibleEntries: 2,
                componentId: @js($componentId),
                countrySearch: '',
                activeCountryDropdown: null,
                highlightedIndex: -1,
                newEntry: { country: @js($defaultCountry), number: '' },
                copiedIndex: null,
                documentClickListener: null,

                init() {
                    if (!Array.isArray(this.state)) {
                        this.state = this.state ? [this.state] : [];
                    }
                    if (this.state.length === 0 && !this.allowMultiple) {
                        this.state = [{ country: this.defaultCountry, number: '' }];
                    }
                    // Normalize entries to ensure they have country and number
                    this.state = this.state.map((entry) => ({
                        country: entry?.country || this.defaultCountry,
                        number: entry?.number || ''
                    }));

                    this.documentClickListener = (event) => {
                        if (this.isOpen && !this.$el.contains(event.target)) {
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

                get canAddMore() {
                    if (!this.allowMultiple) {
                        return false;
                    }
                    return this.filledEntries.length < this.maxValues;
                },

                get filledEntries() {
                    return this.state.filter(entry => entry.number && entry.number.trim() !== '');
                },

                get hasValues() {
                    return this.filledEntries.length > 0;
                },

                get visibleEntries() {
                    return this.filledEntries.slice(0, this.maxVisibleEntries);
                },

                get hiddenCount() {
                    return Math.max(0, this.filledEntries.length - this.maxVisibleEntries);
                },

                get filteredCountries() {
                    if (!this.countrySearch) {
                        return Object.entries(this.countryOptionsWithNames);
                    }
                    const search = this.countrySearch.toLowerCase();
                    return Object.entries(this.countryOptionsWithNames).filter(([code, label]) =>
                        code.toLowerCase().includes(search) || label.toLowerCase().includes(search)
                    );
                },

                get highlightedOptionId() {
                    if (this.highlightedIndex < 0 || this.highlightedIndex >= this.filteredCountries.length) {
                        return null;
                    }
                    const [code] = this.filteredCountries[this.highlightedIndex];
                    return `${this.componentId}-country-option-${this.activeCountryDropdown}-${code}`;
                },

                getOptionId(index, code) {
                    return `${this.componentId}-country-option-${index}-${code}`;
                },

                getListboxId(index) {
                    return `${this.componentId}-country-listbox-${index}`;
                },

                formatDisplay(entry) {
                    if (!entry.number) return '';
                    const countryCode = this.getCallingCode(entry.country);
                    return '+' + countryCode + ' ' + entry.number;
                },

                getCallingCode(country) {
                    const match = this.countryOptions[country];
                    if (match) {
                        const code = match.match(/(\d+)/);
                        return code ? code[1] : '';
                    }
                    return '';
                },

                getTelLink(entry) {
                    if (!entry.number) return '';
                    const countryCode = this.getCallingCode(entry.country);
                    const digitsOnly = entry.number.replace(/[^0-9]/g, '');
                    return 'tel:+' + countryCode + digitsOnly;
                },

                getCountryLabel(country) {
                    return this.countryOptions[country] || country;
                },

                getCountryAriaLabel(country) {
                    const label = this.countryOptionsWithNames[country] || country;
                    return `{{ __('custom-fields::custom-fields.phone.change_country') }}, ${label}`;
                },

                toggle() {
                    if (this.isDisabled) return;
                    if (this.isOpen) {
                        this.close();
                    } else {
                        this.openPanel();
                    }
                },

                openPanel() {
                    if (this.isDisabled || this.isOpen) return;
                    this.$refs.panel?.open(this.$refs.trigger);
                    this.isOpen = true;
                    this.$nextTick(() => this.$refs.newPhoneInput?.focus());
                },

                close() {
                    if (!this.isOpen) return;
                    // Close any open country dropdown first
                    if (this.activeCountryDropdown !== null) {
                        const panelRef = this.activeCountryDropdown === 'new' ? 'countryPanelNew' : 'countryPanelSingle';
                        this.$refs[panelRef]?.close();
                    }
                    this.$refs.panel?.close();
                    this.isOpen = false;
                    this.activeCountryDropdown = null;
                    this.countrySearch = '';
                    this.highlightedIndex = -1;
                    this.newEntry = { country: this.defaultCountry, number: '' };
                },

                openCountryDropdown(index) {
                    if (this.activeCountryDropdown === index) return;
                    const panelRef = index === 'new' ? 'countryPanelNew' : 'countryPanelSingle';
                    const triggerRef = index === 'new' ? 'countryTriggerNew' : 'countryTriggerSingle';
                    this.$refs[panelRef]?.open(this.$refs[triggerRef]);
                    this.activeCountryDropdown = index;
                    this.countrySearch = '';
                    this.highlightedIndex = -1;
                    this.announceResults();
                },

                closeCountryDropdown() {
                    if (this.activeCountryDropdown === null) return;
                    const panelRef = this.activeCountryDropdown === 'new' ? 'countryPanelNew' : 'countryPanelSingle';
                    this.$refs[panelRef]?.close();
                    this.activeCountryDropdown = null;
                    this.countrySearch = '';
                    this.highlightedIndex = -1;
                },

                selectCountry(index, code) {
                    if (index === 'new') {
                        this.newEntry.country = code;
                        this.closeCountryDropdown();
                        this.$nextTick(() => {
                            this.$refs.newPhoneInput?.focus();
                        });
                    } else {
                        this.updateEntry(index, 'country', code);
                        this.closeCountryDropdown();
                    }
                },

                selectHighlightedCountry(index) {
                    if (this.highlightedIndex >= 0 && this.highlightedIndex < this.filteredCountries.length) {
                        const [code] = this.filteredCountries[this.highlightedIndex];
                        this.selectCountry(index, code);
                    }
                },

                highlightNext() {
                    if (this.filteredCountries.length === 0) return;
                    this.highlightedIndex = (this.highlightedIndex + 1) % this.filteredCountries.length;
                    this.scrollHighlightedIntoView();
                },

                highlightPrev() {
                    if (this.filteredCountries.length === 0) return;
                    this.highlightedIndex = this.highlightedIndex <= 0
                        ? this.filteredCountries.length - 1
                        : this.highlightedIndex - 1;
                    this.scrollHighlightedIntoView();
                },

                highlightFirst() {
                    if (this.filteredCountries.length === 0) return;
                    this.highlightedIndex = 0;
                    this.scrollHighlightedIntoView();
                },

                highlightLast() {
                    if (this.filteredCountries.length === 0) return;
                    this.highlightedIndex = this.filteredCountries.length - 1;
                    this.scrollHighlightedIntoView();
                },

                scrollHighlightedIntoView() {
                    this.$nextTick(() => {
                        const optionId = this.highlightedOptionId;
                        if (optionId) {
                            const option = document.getElementById(optionId);
                            if (option) {
                                option.scrollIntoView({ block: 'nearest' });
                            }
                        }
                    });
                },

                announceResults() {
                    this.$nextTick(() => {
                        const count = this.filteredCountries.length;
                        const message = count === 0
                            ? '{{ __('custom-fields::custom-fields.phone.no_results') }}'
                            : (count === 1
                                ? '{{ __('custom-fields::custom-fields.phone.one_result') }}'
                                : `${count} {{ __('custom-fields::custom-fields.phone.results_available') }}`);
                        const announcer = this.$refs.srAnnouncer;
                        if (announcer) {
                            announcer.textContent = message;
                        }
                    });
                },

                handleSearchKeydown(event, index) {
                    switch (event.key) {
                        case 'ArrowDown':
                            event.preventDefault();
                            this.highlightNext();
                            break;
                        case 'ArrowUp':
                            event.preventDefault();
                            this.highlightPrev();
                            break;
                        case 'Home':
                            event.preventDefault();
                            this.highlightFirst();
                            break;
                        case 'End':
                            event.preventDefault();
                            this.highlightLast();
                            break;
                        case 'Enter':
                            event.preventDefault();
                            this.selectHighlightedCountry(index);
                            break;
                        case 'Escape':
                            event.preventDefault();
                            this.closeCountryDropdown();
                            break;
                        case 'Tab':
                            this.closeCountryDropdown();
                            break;
                    }
                },

                handleButtonKeydown(event, index) {
                    switch (event.key) {
                        case 'ArrowDown':
                        case 'ArrowUp':
                        case ' ':
                            event.preventDefault();
                            if (this.activeCountryDropdown !== index) {
                                this.openCountryDropdown(index);
                            }
                            break;
                        case 'Escape':
                            if (this.activeCountryDropdown === index) {
                                event.preventDefault();
                                this.closeCountryDropdown();
                            }
                            break;
                    }
                },

                addEntry() {
                    const number = this.newEntry.number.trim();
                    if (!number || !this.canAddMore) return;

                    const isDuplicate = this.state.some(entry =>
                        entry.country === this.newEntry.country && entry.number === number
                    );

                    if (isDuplicate) {
                        new FilamentNotification()
                            .title('{{ __('custom-fields::custom-fields.validation.duplicate_value') }}')
                            .body('{{ __('custom-fields::custom-fields.phone.number_already_exists') }}')
                            .warning()
                            .send();
                        return;
                    }

                    this.state.push({
                        country: this.newEntry.country,
                        number: number
                    });

                    this.newEntry = { country: this.defaultCountry, number: '' };

                    this.$nextTick(() => {
                        this.$refs.newPhoneInput?.focus();
                    });
                },

                updateEntry(index, field, value) {
                    if (this.state[index]) {
                        const updated = [...this.state];
                        updated[index] = { ...updated[index], [field]: value };
                        this.state = updated;
                    }
                },

                deleteEntry(entryToDelete) {
                    if (this.state.length > 1) {
                        this.state = this.state.filter((e) => e !== entryToDelete);
                    } else {
                        this.state = [{ country: this.defaultCountry, number: '' }];
                    }
                },

                reorderEntries(event) {
                    const filled = this.filledEntries;
                    const movedEntry = filled[event.oldIndex];
                    const targetEntry = filled[event.newIndex];

                    const oldStateIndex = this.state.indexOf(movedEntry);
                    const newStateIndex = this.state.indexOf(targetEntry);

                    const reordered = this.state.splice(oldStateIndex, 1)[0];
                    this.state.splice(newStateIndex, 0, reordered);
                    this.state = [...this.state];
                },

                copyToClipboard(text, index) {
                    window.navigator.clipboard.writeText(text);
                    this.copiedIndex = index;
                    setTimeout(() => {
                        this.copiedIndex = null;
                    }, 2000);
                }
            }"
            x-on:click.outside="close()"
            x-on:keydown.esc="isOpen && (close(), $event.stopPropagation())"
            x-effect="if (countrySearch !== '') { highlightedIndex = 0; announceResults(); }"
            class="relative w-full"
        >
            {{-- Screen reader announcer for live updates --}}
            <div
                x-ref="srAnnouncer"
                class="sr-only"
                role="status"
                aria-live="polite"
                aria-atomic="true"
            ></div>

            {{-- Single Value Mode --}}
            <template x-if="!allowMultiple">
                <div class="flex w-full items-center">
                    {{-- Country Selector Button --}}
                    <div class="relative shrink-0">
                        <button
                            type="button"
                            role="combobox"
                            x-ref="countryTriggerSingle"
                            :aria-expanded="activeCountryDropdown === 0 ? 'true' : 'false'"
                            :aria-controls="getListboxId(0)"
                            :aria-activedescendant="activeCountryDropdown === 0 ? highlightedOptionId : null"
                            :aria-label="getCountryAriaLabel(state[0]?.country)"
                            aria-haspopup="listbox"
                            aria-autocomplete="list"
                            x-on:click.stop="activeCountryDropdown === 0 ? closeCountryDropdown() : openCountryDropdown(0)"
                            x-on:keydown="handleButtonKeydown($event, 0)"
                            :disabled="isDisabled"
                            class="flex items-center gap-1 py-1.5 pl-3 pr-1.5 text-sm text-gray-950 dark:text-white hover:bg-gray-50 dark:hover:bg-white/5 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-1 disabled:opacity-50 disabled:cursor-not-allowed rounded-l-lg w-23"
                        >
                            <span x-text="getCountryLabel(state[0]?.country)" class="font-medium text-xs"></span>
                            <x-filament::icon icon="heroicon-m-chevron-down" class="size-3.5 text-gray-400" x-bind:class="{ 'rotate-180': activeCountryDropdown === 0 }" aria-hidden="true" />
                        </button>

                        {{-- Country Dropdown --}}
                        <div
                            x-cloak
                            x-float.placement.bottom-start.flip.teleport.offset="{ offset: 4 }"
                            x-on:click.outside="closeCountryDropdown()"
                            x-transition:enter-start="opacity-0"
                            x-transition:leave-end="opacity-0"
                            x-ref="countryPanelSingle"
                            class="absolute z-50 w-64 rounded-lg bg-white shadow-lg ring-1 ring-gray-950/5 transition dark:bg-gray-900 dark:ring-white/10"
                        >
                            <div class="border-b border-gray-100 dark:border-gray-800">
                                <div class="relative">
                                    <x-filament::icon icon="heroicon-m-magnifying-glass" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 size-4 text-gray-400" aria-hidden="true" />
                                    <input
                                        type="text"
                                        role="searchbox"
                                        x-model="countrySearch"
                                        x-on:click.stop
                                        x-on:keydown="handleSearchKeydown($event, 0)"
                                        x-ref="singleCountrySearch"
                                        x-init="$watch('activeCountryDropdown', value => { if (value === 0) $nextTick(() => $refs.singleCountrySearch?.focus()) })"
                                        :aria-controls="getListboxId(0)"
                                        :aria-activedescendant="highlightedOptionId"
                                        aria-label="{{ __('custom-fields::custom-fields.phone.search_country') }}"
                                        aria-autocomplete="list"
                                        placeholder="{{ __('custom-fields::custom-fields.phone.search_country') }}"
                                        class="w-full border-0 bg-transparent py-2.5 pl-9 pr-3 text-sm text-gray-900 placeholder:text-gray-400 focus:ring-0 focus:outline-none dark:text-white dark:placeholder:text-gray-500"
                                    />
                                </div>
                            </div>
                            <ul
                                :id="getListboxId(0)"
                                role="listbox"
                                aria-label="{{ __('custom-fields::custom-fields.phone.country_list') }}"
                                tabindex="-1"
                                class="max-h-60 overflow-y-auto p-1"
                            >
                                <template x-for="([code, label], optIndex) in filteredCountries" :key="'single-' + code">
                                    <li
                                        :id="getOptionId(0, code)"
                                        role="option"
                                        :aria-selected="state[0]?.country === code"
                                        tabindex="-1"
                                    >
                                        <button
                                            type="button"
                                            x-on:click.stop="selectCountry(0, code)"
                                            x-on:mouseenter="highlightedIndex = optIndex"
                                            x-on:focus="highlightedIndex = optIndex"
                                            class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm transition-colors focus:outline-none"
                                            :class="{
                                                'bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-400': state[0]?.country === code,
                                                'bg-gray-100 dark:bg-gray-800': highlightedIndex === optIndex && state[0]?.country !== code,
                                                'text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5': state[0]?.country !== code && highlightedIndex !== optIndex
                                            }"
                                        >
                                            <span x-text="label" class="truncate"></span>
                                            <x-filament::icon icon="heroicon-m-check" x-show="state[0]?.country === code" class="ml-auto size-4 shrink-0 text-primary-600 dark:text-primary-400" x-cloak aria-hidden="true" />
                                        </button>
                                    </li>
                                </template>
                                <template x-if="filteredCountries.length === 0">
                                    <li class="px-2 py-3 text-center text-sm text-gray-500 dark:text-gray-400" role="status">
                                        {{ __('custom-fields::custom-fields.phone.no_results') }}
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </div>

                    <div class="h-5 w-px bg-gray-200 dark:bg-gray-700 shrink-0" aria-hidden="true"></div>

                    <input
                        type="tel"
                        inputmode="tel"
                        autocomplete="tel"
                        x-model="state[0].number"
                        x-on:input="updateEntry(0, 'number', $event.target.value)"
                        :disabled="isDisabled"
                        :aria-label="'{{ __('custom-fields::custom-fields.phone.phone_number') }}'"
                        class="fi-input min-w-0 flex-1 border-none bg-transparent py-1.5 px-3 text-xs text-gray-950 outline-none transition duration-75 placeholder:text-gray-400 focus:ring-0 disabled:text-gray-500 dark:text-white dark:placeholder:text-gray-500 dark:disabled:text-gray-400"
                        placeholder="{{ $placeholder }}"
                    />
                </div>
            </template>

            {{-- Multiple Values Mode --}}
            <template x-if="allowMultiple">
                <div>
                    <button
                        type="button"
                        x-ref="trigger"
                        x-on:click.stop="toggle()"
                        :disabled="isDisabled"
                        :aria-expanded="isOpen ? 'true' : 'false'"
                        aria-haspopup="dialog"
                        :aria-controls="$id('panel')"
                        aria-label="{{ __('custom-fields::custom-fields.phone.manage_phone_numbers') }}"
                        class="flex w-full min-h-[2.25rem] items-center gap-1.5 py-1.5 px-3 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-1 rounded-lg"
                    >
                        <div class="flex min-w-0 flex-1 items-center gap-1 overflow-hidden">
                            <template x-if="!hasValues">
                                <span class="text-sm text-gray-400 dark:text-gray-500">{{ $emptyStateLabel }}</span>
                            </template>
                            <template x-for="(entry, index) in visibleEntries" :key="'visible-' + index">
                                <div class="group/item relative inline-flex min-w-0 max-w-full items-center py-0.5 rounded hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                    <a
                                        :href="getTelLink(entry)"
                                        x-on:click.stop
                                        class="min-w-0 truncate text-sm text-primary-600 dark:text-primary-400 underline decoration-gray-300 dark:decoration-gray-600 decoration-1 underline-offset-2"
                                        x-text="formatDisplay(entry)"
                                    ></a>
                                    <button
                                        type="button"
                                        x-on:click.stop="copyToClipboard(formatDisplay(entry), 'trigger-' + index)"
                                        class="absolute right-0 opacity-0 group-hover/item:opacity-100 transition-opacity duration-300 py-0.5 pl-2 pr-1 rounded-r bg-gradient-to-r from-gray-100/90 via-gray-100/100 to-gray-100 dark:from-gray-700/0 dark:via-gray-700/70 dark:to-gray-700"
                                    >
                                        <x-filament::icon icon="heroicon-m-clipboard-document"
                                            x-show="copiedIndex !== 'trigger-' + index"
                                            class="size-3.5 text-primary-500"
                                            aria-hidden="true"
                                        />
                                        <x-filament::icon icon="heroicon-m-check"
                                            x-show="copiedIndex === 'trigger-' + index"
                                            x-cloak
                                            class="size-3.5 text-green-500"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </div>
                            </template>
                            <template x-if="hiddenCount > 0">
                                <span class="fi-multi-value-more shrink-0 text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">+<span x-text="hiddenCount"></span></span>
                            </template>
                        </div>
                        <x-filament::icon icon="heroicon-m-chevron-down" class="size-4 text-gray-400 dark:text-gray-500 shrink-0 transition-transform duration-200" x-bind:class="{ 'rotate-180': isOpen }" aria-hidden="true" />
                    </button>

                    {{-- Popover Panel --}}
                    <div
                        x-cloak
                        x-float.placement.bottom-start.flip.offset="{ offset: 4 }"
                        x-transition:enter-start="opacity-0"
                        x-transition:leave-end="opacity-0"
                        x-ref="panel"
                        :id="$id('panel')"
                        role="dialog"
                        aria-label="{{ __('custom-fields::custom-fields.phone.edit_phone_numbers') }}"
                        class="absolute z-50 w-full rounded-lg bg-white shadow-lg ring-1 ring-gray-950/5 transition dark:bg-gray-900 dark:ring-white/10"
                    >
                        <div wire:ignore>
                            <div
                                x-show="filledEntries.length > 0"
                                x-sortable
                                x-on:end.stop="reorderEntries($event)"
                                class="max-h-[280px] overflow-y-auto rounded-t-lg [&::-webkit-scrollbar]:w-1.5 [&::-webkit-scrollbar-thumb]:bg-gray-300 [&::-webkit-scrollbar-thumb]:rounded-full dark:[&::-webkit-scrollbar-thumb]:bg-gray-600"
                                role="list"
                                aria-label="{{ __('custom-fields::custom-fields.phone.phone_numbers_list') }}"
                            >
                                <template x-for="(entry, index) in filledEntries" :key="`${entry.number}-${index}`">
                                    <div
                                        :x-sortable-item="index"
                                        class="group flex items-center gap-2 px-3 py-2 border-b border-gray-100 dark:border-gray-800 last:border-b-0 hover:bg-gray-50 dark:hover:bg-gray-800/50 first:rounded-t-lg last:rounded-b-lg transition-colors"
                                        role="listitem"
                                    >
                                        {{-- Drag Handle --}}
                                        <div x-sortable-handle class="shrink-0 cursor-grab active:cursor-grabbing" x-show="filledEntries.length > 1">
                                            <svg class="size-4 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <circle cx="7" cy="5" r="1.5"/>
                                                <circle cx="13" cy="5" r="1.5"/>
                                                <circle cx="7" cy="10" r="1.5"/>
                                                <circle cx="13" cy="10" r="1.5"/>
                                                <circle cx="7" cy="15" r="1.5"/>
                                                <circle cx="13" cy="15" r="1.5"/>
                                            </svg>
                                        </div>

                                        {{-- Formatted Phone with tel link and copy button --}}
                                        <div class="group/value relative inline-flex items-center py-0.5 rounded hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                            <a
                                                :href="getTelLink(entry)"
                                                class="text-sm text-primary-600 dark:text-primary-400 underline decoration-gray-300 dark:decoration-gray-600 decoration-1 underline-offset-2"
                                                x-text="formatDisplay(entry)"
                                            ></a>
                                            <button
                                                type="button"
                                                x-on:click.stop="copyToClipboard(formatDisplay(entry), index)"
                                                class="absolute right-0 opacity-0 group-hover/value:opacity-100 transition-opacity duration-300 py-0.5 pl-2 pr-1 rounded-r bg-gradient-to-r from-gray-100/90 via-gray-100/100 to-gray-100 dark:from-gray-700/0 dark:via-gray-700/70 dark:to-gray-700"
                                            >
                                                <x-filament::icon icon="heroicon-m-clipboard-document"
                                                    x-show="copiedIndex !== index"
                                                    class="size-4 text-primary-500"
                                                    aria-hidden="true"
                                                />
                                                <x-filament::icon icon="heroicon-m-check"
                                                    x-show="copiedIndex === index"
                                                    x-cloak
                                                    class="size-4 text-green-500"
                                                    aria-hidden="true"
                                                />
                                            </button>
                                        </div>

                                        <div class="flex-1"></div>

                                        {{-- Delete Button --}}
                                        <button
                                            type="button"
                                            x-on:click.stop="deleteEntry(entry)"
                                            aria-label="{{ __('custom-fields::custom-fields.phone.remove_phone_number') }}"
                                            class="opacity-0 group-hover:opacity-100 focus:opacity-100 shrink-0 rounded p-1 text-gray-400 hover:text-danger-500 hover:bg-danger-50 dark:hover:bg-danger-500/10 transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                                        >
                                            <x-filament::icon icon="heroicon-m-trash" class="size-4" aria-hidden="true" />
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </div>

                        {{-- Add New Phone Row --}}
                        <template x-if="canAddMore">
                            <div class="flex items-center gap-2 py-2 px-3 border-t border-gray-100 dark:border-gray-800">
                                {{-- Country Selector --}}
                                <div class="relative shrink-0">
                                    <button
                                        type="button"
                                        role="combobox"
                                        x-ref="countryTriggerNew"
                                        :aria-expanded="activeCountryDropdown === 'new' ? 'true' : 'false'"
                                        :aria-controls="getListboxId('new')"
                                        :aria-activedescendant="activeCountryDropdown === 'new' ? highlightedOptionId : null"
                                        :aria-label="getCountryAriaLabel(newEntry.country)"
                                        aria-haspopup="listbox"
                                        aria-autocomplete="list"
                                        x-on:click.stop="activeCountryDropdown === 'new' ? closeCountryDropdown() : openCountryDropdown('new')"
                                        x-on:keydown="handleButtonKeydown($event, 'new')"
                                        :disabled="isDisabled"
                                        class="flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-1 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        <span x-text="getCountryLabel(newEntry.country)"></span>
                                        <x-filament::icon icon="heroicon-m-chevron-down" class="size-3 text-gray-400" x-bind:class="{ 'rotate-180': activeCountryDropdown === 'new' }" aria-hidden="true" />
                                    </button>

                                    {{-- Country Dropdown --}}
                                    <div
                                        x-cloak
                                        x-float.placement.bottom-start.flip.teleport.offset="{ offset: 4 }"
                                        x-on:click.outside="closeCountryDropdown()"
                                        x-transition:enter-start="opacity-0"
                                        x-transition:leave-end="opacity-0"
                                        x-ref="countryPanelNew"
                                        class="absolute z-50 w-64 rounded-lg bg-white shadow-lg ring-1 ring-gray-950/5 transition dark:bg-gray-900 dark:ring-white/10"
                                    >
                                        <div class="border-b border-gray-100 dark:border-gray-800">
                                            <div class="relative">
                                                <x-filament::icon icon="heroicon-m-magnifying-glass" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 size-4 text-gray-400" aria-hidden="true" />
                                                <input
                                                    type="text"
                                                    role="searchbox"
                                                    x-model="countrySearch"
                                                    x-on:click.stop
                                                    x-on:keydown="handleSearchKeydown($event, 'new')"
                                                    x-ref="newCountrySearch"
                                                    x-init="$watch('activeCountryDropdown', value => { if (value === 'new') $nextTick(() => $refs.newCountrySearch?.focus()) })"
                                                    :aria-controls="getListboxId('new')"
                                                    :aria-activedescendant="highlightedOptionId"
                                                    aria-label="{{ __('custom-fields::custom-fields.phone.search_country') }}"
                                                    aria-autocomplete="list"
                                                    placeholder="{{ __('custom-fields::custom-fields.phone.search_country') }}"
                                                    class="w-full border-0 bg-transparent py-2.5 pl-9 pr-3 text-sm text-gray-900 placeholder:text-gray-400 focus:ring-0 focus:outline-none dark:text-white dark:placeholder:text-gray-500"
                                                />
                                            </div>
                                        </div>
                                        <ul
                                            :id="getListboxId('new')"
                                            role="listbox"
                                            aria-label="{{ __('custom-fields::custom-fields.phone.country_list') }}"
                                            tabindex="-1"
                                            class="max-h-48 overflow-y-auto p-1"
                                        >
                                            <template x-for="([code, label], optIndex) in filteredCountries" :key="'new-' + code">
                                                <li
                                                    :id="getOptionId('new', code)"
                                                    role="option"
                                                    :aria-selected="newEntry.country === code"
                                                    tabindex="-1"
                                                >
                                                    <button
                                                        type="button"
                                                        x-on:click.stop="selectCountry('new', code)"
                                                        x-on:mouseenter="highlightedIndex = optIndex"
                                                        x-on:focus="highlightedIndex = optIndex"
                                                        class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm transition-colors focus:outline-none"
                                                        :class="{
                                                            'bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-400': newEntry.country === code,
                                                            'bg-gray-100 dark:bg-gray-800': highlightedIndex === optIndex && newEntry.country !== code,
                                                            'text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5': newEntry.country !== code && highlightedIndex !== optIndex
                                                        }"
                                                    >
                                                        <span x-text="label" class="truncate"></span>
                                                        <x-filament::icon icon="heroicon-m-check" x-show="newEntry.country === code" class="ml-auto size-4 shrink-0 text-primary-600 dark:text-primary-400" x-cloak aria-hidden="true" />
                                                    </button>
                                                </li>
                                            </template>
                                            <template x-if="filteredCountries.length === 0">
                                                <li class="px-2 py-3 text-center text-sm text-gray-500 dark:text-gray-400" role="status">
                                                    {{ __('custom-fields::custom-fields.phone.no_results') }}
                                                </li>
                                            </template>
                                        </ul>
                                    </div>
                                </div>

                                {{-- Phone Input --}}
                                <input
                                    type="tel"
                                    inputmode="tel"
                                    x-model="newEntry.number"
                                    x-ref="newPhoneInput"
                                    x-on:keydown.enter.prevent="addEntry()"
                                    :disabled="isDisabled"
                                    class="flex-1 bg-transparent border-0 p-0 text-xs text-gray-900 dark:text-gray-100 placeholder:text-gray-400 dark:placeholder:text-gray-500 focus:ring-0 focus:outline-none"
                                    placeholder="{{ $addLabel }}..."
                                />

                                {{-- Add Button --}}
                                <button
                                    type="button"
                                    x-on:click.stop="addEntry()"
                                    :disabled="isDisabled || !newEntry.number.trim()"
                                    class="shrink-0 rounded p-1 text-gray-400 hover:text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-500/10 transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                                    aria-label="{{ $addLabel }}"
                                >
                                    <x-filament::icon icon="heroicon-m-arrow-right" class="size-4" aria-hidden="true" />
                                </button>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </x-filament::input.wrapper>
</x-dynamic-component>
