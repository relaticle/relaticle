@php
    $fieldWrapperView = $getFieldWrapperView();
    $isDisabled = $isDisabled();
    $statePath = $getStatePath();
    $inputType = $getInputType();
    $allowMultiple = $getAllowMultiple();
    $maxValues = $getMaxValues();
    $addLabel = $getAddLabel();
    $emptyStateLabel = $getEmptyStateLabel();
    $placeholder = $getPlaceholder();
    $inputmode = match($inputType) { 'email' => 'email', 'url' => 'url', default => 'text' };
    $linkPrefix = match ($inputType) {
        'email' => 'mailto:',
        'url' => '',
        default => null,
    };
    $isLinked = $linkPrefix !== null;
    $key = $getKey();
    $invalidValueMessage = match ($inputType) {
        'email' => __('filament/inline-edit.invalid_email'),
        'url' => __('filament/inline-edit.invalid_url'),
        default => '',
    };
    $invalidValueMessage = is_string($invalidValueMessage) ? $invalidValueMessage : '';
@endphp

<x-dynamic-component
    :component="$fieldWrapperView"
    :field="$field"
    class="fi-fo-multi-value-input-wrp"
>
    <x-filament::input.wrapper
        :disabled="$isDisabled"
        :valid="! $errors->has($statePath)"
        :attributes="
            \Filament\Support\prepare_inherited_attributes($attributes)
                ->class([
                    'fi-fo-multi-value-input',
                    'fi-fo-multi-value-plain' => ! $isLinked,
                ])
        "
    >
        <div
            wire:key="{{ $key }}-{{ $isDisabled ? 'disabled' : 'enabled' }}"
            wire:ignore.self
            x-cloak
            x-data="{
                state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
                newValue: '',
                addInvalid: false,
                inputType: @js($inputType),
                linkPrefix: @js($linkPrefix),
                componentKey: @js($key),
                allowMultiple: @js($allowMultiple),
                maxValues: @js($maxValues),
                isDisabled: @js($isDisabled),
                maxVisibleValues: 3,
                copiedIndex: null,
                documentClickListener: null,

                init() {
                    if (!Array.isArray(this.state)) {
                        this.state = this.state ? [this.state] : [];
                    }
                    this.state = this.state.filter(v => v && v.trim() !== '');

                    this.documentClickListener = (event) => {
                        if (!this.isOpen() || this.$el.closest('.fi-inline-field-editor')) {
                            return;
                        }

                        const target = event.target;
                        if (this.$el.contains(target) || this.$refs.panel?.contains(target)) {
                            return;
                        }

                        this.closePanel();
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
                        return this.state.length === 0;
                    }
                    return this.state.length < this.maxValues;
                },

                get hasValues() {
                    return this.state.length > 0;
                },

                get singleValue() {
                    return this.state[0] || '';
                },

                get visibleValues() {
                    return this.state.slice(0, this.maxVisibleValues);
                },

                get hiddenCount() {
                    return Math.max(0, this.state.length - this.maxVisibleValues);
                },

                isOpen() {
                    return this.$refs.panel?._x_isShown === true;
                },

                valueHref(value) {
                    if (this.linkPrefix === null) {
                        return null;
                    }

                    if (this.inputType === 'url') {
                        return value.indexOf('://') === -1 ? ('https://' + value) : value;
                    }

                    return this.linkPrefix + value;
                },

                matchPanelWidth() {
                    if (! this.$refs.panel || ! this.$refs.trigger) {
                        return;
                    }

                    this.$refs.panel.style.width = `${this.$refs.trigger.offsetWidth}px`;
                },

                togglePanel() {
                    if (this.isDisabled) return;
                    this.$refs.panel?.toggle(this.$refs.trigger);
                    if (this.isOpen()) {
                        this.newValue = '';
                        this.addInvalid = false;
                        this.$nextTick(() => {
                            this.matchPanelWidth();
                            this.$refs.newInput?.focus();
                        });
                    }
                },

                openPanel() {
                    if (this.isDisabled) return;
                    this.$refs.panel?.open(this.$refs.trigger);
                    this.newValue = '';
                    this.addInvalid = false;
                    this.$nextTick(() => {
                        this.matchPanelWidth();
                        this.$refs.newInput?.focus();
                    });
                },

                closePanel() {
                    this.$refs.panel?.close();
                    this.newValue = '';
                    this.addInvalid = false;
                },

                isValidValue(value) {
                    if (this.inputType === 'email') {
                        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
                    }
                    if (this.inputType === 'url') {
                        try {
                            const parsed = new URL(value.indexOf('://') === -1 ? ('https://' + value) : value);

                            return parsed.hostname.indexOf('.') !== -1;
                        } catch (e) {
                            return false;
                        }
                    }

                    return true;
                },

                addValue() {
                    const value = this.newValue.trim();
                    if (!value || !this.canAddMore) return;

                    if (! this.isValidValue(value)) {
                        this.addInvalid = true;
                        this.$nextTick(() => this.$refs.newInput?.focus());

                        return false;
                    }

                    if (this.state.includes(value)) {
                        new FilamentNotification()
                            .title('{{ __('custom-fields::custom-fields.validation.duplicate_value') }}')
                            .body('{{ __('custom-fields::custom-fields.validation.value_already_exists') }}')
                            .warning()
                            .send();
                        return;
                    }

                    this.addInvalid = false;
                    this.state.push(value);
                    this.newValue = '';

                    if (!this.allowMultiple) {
                        this.closePanel();
                    } else {
                        this.$nextTick(() => {
                            this.$refs.newInput?.focus();
                        });
                    }

                    return true;
                },

                setSingleValue(value) {
                    const trimmed = value.trim();
                    if (trimmed && ! this.isValidValue(trimmed)) {
                        this.addInvalid = true;

                        return false;
                    }
                    this.addInvalid = false;
                    this.state = trimmed ? [trimmed] : [];
                },

                writeSingleValue(value) {
                    this.addInvalid = false;
                    const trimmed = value.trim();
                    this.state = trimmed ? [trimmed] : [];
                },

                deleteValue(valueToDelete) {
                    this.state = this.state.filter((v) => v !== valueToDelete);
                    if (this.state.length === 0 && !this.allowMultiple) {
                        this.closePanel();
                    }
                },

                handleEnter(e) {
                    e.preventDefault();
                    this.addValue();
                },

                reorderValues(event) {
                    const reordered = this.state.splice(event.oldIndex, 1)[0];
                    this.state.splice(event.newIndex, 0, reordered);
                    this.state = [...this.state];
                },

                copyToClipboard(text, index) {
                    window.navigator.clipboard.writeText(text);
                    this.copiedIndex = index;
                    this.announceToScreenReader('Copied ' + text + ' to clipboard');
                    setTimeout(() => {
                        this.copiedIndex = null;
                    }, 2000);
                },

                announceToScreenReader(message) {
                    if (this.$refs.announcer) {
                        this.$refs.announcer.textContent = message;
                    }
                }
            }"
            x-on:keydown.esc="isOpen() && (closePanel(), $event.stopPropagation())"
            class="relative w-full"
        >
            {{-- Screen reader live region --}}
            <div x-ref="announcer" aria-live="polite" aria-atomic="true" class="sr-only"></div>

            {{-- Single Value Mode: simple inline input --}}
            <template x-if="!allowMultiple && state.length <= 1">
                <div class="w-full">
                    <input
                        type="text"
                        inputmode="{{ $inputmode }}"
                        spellcheck="false"
                        autocomplete="off"
                        autocorrect="off"
                        autocapitalize="off"
                        :value="singleValue"
                        x-on:input="writeSingleValue($event.target.value)"
                        x-on:keydown.enter.prevent="setSingleValue($event.target.value)"
                        :disabled="isDisabled"
                        :aria-invalid="addInvalid"
                        :class="{ 'fi-fo-multi-value-add-invalid': addInvalid }"
                        class="fi-input block w-full border-none bg-transparent py-1.5 px-3 text-sm text-gray-950 outline-none transition duration-75 placeholder:text-gray-400 focus:ring-0 disabled:text-gray-500 dark:text-white dark:placeholder:text-gray-500 dark:disabled:text-gray-400"
                        placeholder="{{ $placeholder }}"
                    />
                    @if (filled($invalidValueMessage))
                        <p
                            x-show="addInvalid"
                            class="fi-fo-multi-value-add-error px-3 pb-1 text-xs"
                        >
                            {{ $invalidValueMessage }}
                        </p>
                    @endif
                </div>
            </template>

            {{-- Multiple Values Mode OR Single mode with legacy data: Values with popover --}}
            <template x-if="allowMultiple || state.length > 1">
                <div>
                    {{-- Trigger Area --}}
                    <button
                        type="button"
                        x-ref="trigger"
                        x-on:click.stop="togglePanel()"
                        x-on:keydown.enter.prevent="togglePanel()"
                        x-on:keydown.space.prevent="togglePanel()"
                        :disabled="isDisabled"
                        :aria-expanded="isOpen() ? 'true' : 'false'"
                        aria-haspopup="dialog"
                        :aria-controls="$id('panel')"
                        class="flex w-full min-h-[2.25rem] items-center gap-1.5 py-1.5 px-3 text-left focus:outline-none rounded"
                    >
                        {{-- Content area (empty state or values) --}}
                        <div class="flex min-w-0 flex-1 items-center gap-1 overflow-hidden">
                            {{-- Empty State --}}
                            <template x-if="!hasValues">
                                <span class="text-sm text-gray-400 dark:text-gray-500">
                                    {{ $emptyStateLabel }}
                                </span>
                            </template>

                            <template x-for="(value, index) in visibleValues" :key="`trigger-${value}-${index}`">
                                <div class="group/item relative inline-flex min-w-0 max-w-full items-center py-0.5 rounded">
                                    @if ($isLinked)
                                        <a
                                            :href="valueHref(value)"
                                            x-on:click.stop
                                            class="min-w-0 truncate text-sm text-primary-600 dark:text-primary-400 underline decoration-gray-300 dark:decoration-gray-600 decoration-1 underline-offset-2"
                                            x-text="value"
                                        ></a>
                                        <button
                                            type="button"
                                            x-on:click.stop="copyToClipboard(value, 'trigger-' + index)"
                                            :aria-label="'Copy ' + value + ' to clipboard'"
                                            class="absolute right-0 opacity-0 group-hover/item:opacity-100 focus:opacity-100 transition-opacity duration-300 py-0.5 pl-2 pr-1 rounded-r bg-gradient-to-r from-gray-100/90 via-gray-100/100 to-gray-100 dark:from-gray-700/0 dark:via-gray-700/70 dark:to-gray-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-1"
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
                                    @else
                                        <span class="fi-multi-value-chip" x-text="value"></span>
                                    @endif
                                </div>
                            </template>

                            <template x-if="hiddenCount > 0">
                                <span class="fi-multi-value-more shrink-0 text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">+<span x-text="hiddenCount"></span></span>
                            </template>
                        </div>

                        {{-- Chevron indicator --}}
                        <x-filament::icon icon="heroicon-m-chevron-down"
                            class="size-4 text-gray-400 dark:text-gray-500 shrink-0 transition-transform duration-200"
                            x-bind:class="{ 'rotate-180': isOpen() }"
                            aria-hidden="true"
                        />
                    </button>

                    <div
                        x-cloak
                        x-float.placement.bottom-start.flip.shift.teleport.offset="{ offset: 4 }"
                        x-transition:enter-start="opacity-0"
                        x-transition:leave-end="opacity-0"
                        x-ref="panel"
                        x-on:keydown.esc.stop="closePanel()"
                        :id="$id('panel')"
                        role="dialog"
                        aria-label="Manage values"
                        class="fi-fo-multi-value-panel absolute z-50 w-full rounded-lg bg-white shadow-lg ring-1 ring-gray-950/5 transition dark:bg-gray-900 dark:ring-white/10"
                    >
                        <div class="fi-fo-multi-value-panel-list" wire:ignore>
                            <template x-if="hasValues">
                                <div
                                    x-sortable
                                    x-on:end.stop="reorderValues($event)"
                                    class="rounded-t-lg"
                                >
                                    <template x-for="(value, index) in state" :key="`${value}-${index}`">
                                        <div
                                            :x-sortable-item="index"
                                            class="fi-fo-multi-value-panel-row group flex items-center border-b border-gray-100 dark:border-gray-800 last:border-b-0 hover:bg-gray-50 dark:hover:bg-gray-800/50 first:rounded-t-lg last:rounded-b-lg transition-colors"
                                        >
                                            <div x-sortable-handle class="shrink-0 cursor-grab active:cursor-grabbing" x-show="state.length > 1">
                                                <svg class="size-3.5 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <circle cx="7" cy="5" r="1.5"/>
                                                    <circle cx="13" cy="5" r="1.5"/>
                                                    <circle cx="7" cy="10" r="1.5"/>
                                                    <circle cx="13" cy="10" r="1.5"/>
                                                    <circle cx="7" cy="15" r="1.5"/>
                                                    <circle cx="13" cy="15" r="1.5"/>
                                                </svg>
                                            </div>

                                            <div class="group/value relative inline-flex min-w-0 items-center py-0.5">
                                                @if ($isLinked)
                                                    <a
                                                        :href="valueHref(value)"
                                                        class="min-w-0 truncate text-sm text-primary-600 dark:text-primary-400 underline decoration-gray-300 dark:decoration-gray-600 decoration-1 underline-offset-2"
                                                        x-text="value"
                                                    ></a>
                                                    <button
                                                        type="button"
                                                        x-on:click.stop="copyToClipboard(value, index)"
                                                        :aria-label="'Copy ' + value + ' to clipboard'"
                                                        class="absolute right-0 opacity-0 group-hover/value:opacity-100 focus:opacity-100 transition-opacity duration-300 py-0.5 pl-2 pr-1 rounded-r bg-gradient-to-r from-gray-100/90 via-gray-100/100 to-gray-100 dark:from-gray-700/0 dark:via-gray-700/70 dark:to-gray-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-1"
                                                    >
                                                        <x-filament::icon icon="heroicon-m-clipboard-document"
                                                            x-show="copiedIndex !== index"
                                                            class="size-3.5 text-primary-500"
                                                            aria-hidden="true"
                                                        />
                                                        <x-filament::icon icon="heroicon-m-check"
                                                            x-show="copiedIndex === index"
                                                            x-cloak
                                                            class="size-3.5 text-green-500"
                                                            aria-hidden="true"
                                                        />
                                                    </button>
                                                @else
                                                    <span class="fi-multi-value-chip" x-text="value"></span>
                                                @endif
                                            </div>

                                            <div class="flex-1"></div>

                                            <button
                                                type="button"
                                                x-on:click.stop="deleteValue(value)"
                                                :aria-label="'Delete ' + value"
                                                class="opacity-0 group-hover:opacity-100 focus:opacity-100 shrink-0 rounded p-1 text-gray-400 hover:text-danger-500 hover:bg-danger-50 dark:hover:bg-danger-500/10 transition-all focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-1"
                                            >
                                                <x-filament::icon icon="heroicon-m-trash" class="size-3.5" aria-hidden="true" />
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>

                        <template x-if="canAddMore">
                            <div class="fi-fo-multi-value-panel-add">
                                <div
                                    class="flex items-center gap-2 px-2 py-1.5 border-t border-gray-100 dark:border-gray-800"
                                    :class="{ 'fi-fo-multi-value-add-invalid': addInvalid }"
                                >
                                {{-- Plus Icon (Left) --}}
                                <x-filament::icon icon="heroicon-m-plus" class="size-3.5 text-gray-400 shrink-0" aria-hidden="true" />

                                <input
                                    type="text"
                                    inputmode="{{ $inputmode }}"
                                    spellcheck="false"
                                    autocomplete="off"
                                    autocorrect="off"
                                    autocapitalize="off"
                                    x-model="newValue"
                                    x-ref="newInput"
                                    x-on:input="addInvalid = false"
                                    x-on:keydown.enter="handleEnter($event)"
                                    aria-label="{{ $addLabel }}"
                                    :aria-invalid="addInvalid"
                                    class="flex-1 bg-transparent border-0 p-0 text-xs text-gray-900 dark:text-gray-100 placeholder:text-gray-400 dark:placeholder:text-gray-500 focus:ring-0 focus:outline-none"
                                    placeholder="{{ $addLabel }}..."
                                />
                                <button
                                    type="button"
                                    x-on:click.stop="addValue()"
                                    :disabled="!newValue.trim()"
                                    aria-label="Add value"
                                    class="shrink-0 rounded p-1 text-gray-400 hover:text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-500/10 transition-all disabled:opacity-50 disabled:cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-1"
                                >
                                    <x-filament::icon icon="heroicon-m-arrow-right" class="size-3.5" aria-hidden="true" />
                                </button>
                                </div>
                                @if (filled($invalidValueMessage))
                                    <p
                                        x-show="addInvalid"
                                        class="fi-fo-multi-value-add-error px-3 pb-2 text-xs"
                                    >
                                        {{ $invalidValueMessage }}
                                    </p>
                                @endif
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </x-filament::input.wrapper>
</x-dynamic-component>
