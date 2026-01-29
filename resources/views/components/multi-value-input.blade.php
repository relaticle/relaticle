@props([
    'value' => '',
    'placeholder' => 'Add value...',
    'disabled' => false,
    'inputType' => 'text',
    'borderless' => false,
    'errors' => [], // Per-value errors: ['invalid@' => 'Invalid email format']
    'eventName' => 'input', // Custom event name for change notifications
])

@php
    $inputmode = match($inputType) {
        'email' => 'email',
        'url' => 'url',
        'tel' => 'tel',
        default => 'text',
    };
    $linkPrefix = match($inputType) {
        'email' => 'mailto:',
        'tel' => 'tel:',
        'url' => '',
        default => null,
    };
@endphp

<div
    x-data="{
        values: @js(array_filter(array_map('trim', explode(',', $value ?? '')))),
        errors: @js((array) $errors),
        newValue: '',
        isDisabled: @js($disabled),
        inputType: @js($inputType),
        linkPrefix: @js($linkPrefix),
        eventName: @js($eventName),
        maxVisibleValues: 3,
        copiedIndex: null,
        documentClickListener: null,

        init() {
            this.documentClickListener = (event) => {
                if (this.isOpen() && !this.$el.contains(event.target)) {
                    this.closePanel();
                }
            };
            document.addEventListener('click', this.documentClickListener);
        },

        destroy() {
            if (this.documentClickListener) {
                document.removeEventListener('click', this.documentClickListener);
            }
        },

        get hasValues() {
            return this.values.length > 0;
        },

        get hasErrors() {
            return Object.keys(this.errors).length > 0;
        },

        get errorCount() {
            return Object.keys(this.errors).length;
        },

        get visibleValues() {
            return this.values.slice(0, this.maxVisibleValues);
        },

        get hiddenCount() {
            return Math.max(0, this.values.length - this.maxVisibleValues);
        },

        get commaSeparated() {
            return this.values.join(', ');
        },

        hasError(value) {
            return this.errors.hasOwnProperty(value);
        },

        getError(value) {
            return this.errors[value] || null;
        },

        isOpen() {
            return this.$refs.panel?._x_isShown === true;
        },

        togglePanel() {
            if (this.isDisabled) return;
            this.$refs.panel?.toggle(this.$refs.trigger);
            if (this.isOpen()) {
                this.newValue = '';
                this.$nextTick(() => this.$refs.newInput?.focus());
            }
        },

        openPanel() {
            if (this.isDisabled) return;
            this.$refs.panel?.open(this.$refs.trigger);
            this.newValue = '';
            this.$nextTick(() => this.$refs.newInput?.focus());
        },

        closePanel() {
            this.$refs.panel?.close();
            this.newValue = '';
        },

        addValue() {
            const value = this.newValue.trim();
            if (!value) return;

            if (this.values.includes(value)) {
                this.newValue = '';
                return;
            }

            this.values.push(value);
            this.newValue = '';
            this.emitChange();
            this.$nextTick(() => this.$refs.newInput?.focus());
        },

        deleteValue(valueToDelete) {
            this.values = this.values.filter((v) => v !== valueToDelete);
            // Also remove error for this value
            if (this.errors.hasOwnProperty(valueToDelete)) {
                delete this.errors[valueToDelete];
            }
            this.emitChange();
        },

        handleEnter(e) {
            e.preventDefault();
            this.addValue();
        },

        emitChange() {
            this.$dispatch(this.eventName, this.commaSeparated);
        },

        reorderValues(event) {
            const reordered = this.values.splice(event.oldIndex, 1)[0];
            this.values.splice(event.newIndex, 0, reordered);
            this.values = [...this.values];
            this.emitChange();
        },

        copyToClipboard(text, index) {
            window.navigator.clipboard.writeText(text);
            this.copiedIndex = index;
            setTimeout(() => {
                this.copiedIndex = null;
            }, 2000);
        },

        getLink(value) {
            if (!this.linkPrefix) return null;
            return this.linkPrefix + value;
        }
    }"
    x-on:click.outside="closePanel()"
    x-on:keydown.esc="isOpen() && (closePanel(), $event.stopPropagation())"
    {{ $attributes->merge(['class' => 'relative w-full']) }}
>
    {{-- Trigger Button --}}
    <button
        type="button"
        x-ref="trigger"
        x-on:click.stop="togglePanel()"
        x-on:keydown.enter.prevent="togglePanel()"
        x-on:keydown.space.prevent="togglePanel()"
        :disabled="isDisabled"
        :aria-expanded="isOpen() ? 'true' : 'false'"
        aria-haspopup="dialog"
        @class([
            'flex w-full min-h-[2rem] items-center gap-1.5 py-1 text-left focus:outline-none rounded',
            'px-2.5 border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800' => !$borderless,
            'px-2 border-0 bg-transparent' => $borderless,
        ])
    >
        {{-- Content area --}}
        <div class="flex flex-1 items-center gap-x-2 overflow-hidden">
            {{-- Empty State --}}
            <template x-if="!hasValues">
                <span class="text-sm text-gray-400 dark:text-gray-500">
                    {{ $placeholder }}
                </span>
            </template>

            {{-- Visible Values --}}
            <template x-for="(value, index) in visibleValues" :key="`trigger-${value}-${index}`">
                <div class="group/item relative inline-flex items-center py-0.5 rounded transition-colors"
                     :class="hasError(value) ? 'bg-danger-50 dark:bg-danger-950/30' : 'hover:bg-gray-100 dark:hover:bg-gray-700'">
                    {{-- Error indicator --}}
                    <template x-if="hasError(value)">
                        <span
                            x-tooltip="{ content: getError(value), theme: $store.theme }"
                            class="mr-1 cursor-help"
                        >
                            <x-heroicon-m-exclamation-triangle class="size-3.5 text-danger-500" />
                        </span>
                    </template>
                    <template x-if="linkPrefix && !hasError(value)">
                        <a
                            :href="getLink(value)"
                            x-on:click.stop
                            class="text-sm text-primary-600 dark:text-primary-400 underline decoration-gray-300 dark:decoration-gray-600 decoration-1 underline-offset-2 truncate max-w-[100px]"
                            x-text="value"
                        ></a>
                    </template>
                    <template x-if="!linkPrefix || hasError(value)">
                        <span
                            class="text-sm truncate max-w-[100px]"
                            :class="hasError(value) ? 'text-danger-700 dark:text-danger-400' : 'text-gray-900 dark:text-white'"
                            x-text="value"
                        ></span>
                    </template>
                    <button
                        type="button"
                        x-on:click.stop="copyToClipboard(value, 'trigger-' + index)"
                        :aria-label="'Copy ' + value + ' to clipboard'"
                        class="absolute right-0 opacity-0 group-hover/item:opacity-100 focus:opacity-100 transition-opacity duration-300 py-0.5 pl-2 pr-1 rounded-r bg-gradient-to-r from-gray-100/90 via-gray-100/100 to-gray-100 dark:from-gray-700/0 dark:via-gray-700/70 dark:to-gray-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-1"
                    >
                        <x-heroicon-m-clipboard-document
                            x-show="copiedIndex !== 'trigger-' + index"
                            class="size-3.5 text-primary-500"
                            aria-hidden="true"
                        />
                        <x-heroicon-m-check
                            x-show="copiedIndex === 'trigger-' + index"
                            x-cloak
                            class="size-3.5 text-green-500"
                            aria-hidden="true"
                        />
                    </button>
                </div>
            </template>

            {{-- "+N more" indicator --}}
            <template x-if="hiddenCount > 0">
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    +<span x-text="hiddenCount"></span> more
                </span>
            </template>
        </div>

        {{-- Error count badge --}}
        <template x-if="hasErrors">
            <span class="inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-medium rounded-full bg-danger-100 text-danger-700 dark:bg-danger-900/50 dark:text-danger-400">
                <span x-text="errorCount"></span>
            </span>
        </template>

        {{-- Chevron indicator --}}
        <x-heroicon-m-chevron-down
            class="size-4 text-gray-400 dark:text-gray-500 shrink-0 transition-transform duration-200"
            x-bind:class="{ 'rotate-180': isOpen() }"
            aria-hidden="true"
        />
    </button>

    {{-- Popover Panel --}}
    <div
        x-cloak
        x-float.placement.bottom-start.flip.offset="{ offset: 4 }"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        x-ref="panel"
        role="dialog"
        aria-label="Manage values"
        class="absolute z-50 w-full min-w-[200px] rounded-lg bg-white shadow-lg ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden"
    >
        {{-- Existing Values List --}}
        <template x-if="hasValues">
            <div
                x-sortable
                x-on:end.stop="reorderValues($event)"
                class="max-h-[200px] overflow-y-auto"
            >
                <template x-for="(value, index) in values" :key="`${value}-${index}`">
                    <div
                        :x-sortable-item="index"
                        class="group flex items-center gap-2 px-3 py-2 border-b border-gray-100 dark:border-gray-800 last:border-b-0 transition-colors"
                        :class="hasError(value) ? 'bg-danger-50 dark:bg-danger-950/30' : 'hover:bg-gray-50 dark:hover:bg-gray-800/50'"
                    >
                        {{-- Drag Handle --}}
                        <div x-sortable-handle class="shrink-0 cursor-grab active:cursor-grabbing" x-show="values.length > 1">
                            <svg class="size-4 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <circle cx="7" cy="5" r="1.5"/>
                                <circle cx="13" cy="5" r="1.5"/>
                                <circle cx="7" cy="10" r="1.5"/>
                                <circle cx="13" cy="10" r="1.5"/>
                                <circle cx="7" cy="15" r="1.5"/>
                                <circle cx="13" cy="15" r="1.5"/>
                            </svg>
                        </div>

                        {{-- Error indicator --}}
                        <template x-if="hasError(value)">
                            <span
                                x-tooltip="{ content: getError(value), theme: $store.theme }"
                                class="shrink-0 cursor-help"
                            >
                                <x-heroicon-m-exclamation-triangle class="size-4 text-danger-500" />
                            </span>
                        </template>

                        {{-- Value with optional link and copy button --}}
                        <div class="group/value relative inline-flex items-center py-0.5 rounded hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors flex-1 min-w-0">
                            <template x-if="linkPrefix && !hasError(value)">
                                <a
                                    :href="getLink(value)"
                                    class="text-sm text-primary-600 dark:text-primary-400 underline decoration-gray-300 dark:decoration-gray-600 decoration-1 underline-offset-2 truncate"
                                    x-text="value"
                                ></a>
                            </template>
                            <template x-if="!linkPrefix || hasError(value)">
                                <span
                                    class="text-sm truncate"
                                    :class="hasError(value) ? 'text-danger-700 dark:text-danger-400' : 'text-gray-900 dark:text-white'"
                                    x-text="value"
                                ></span>
                            </template>
                            <button
                                type="button"
                                x-on:click.stop="copyToClipboard(value, index)"
                                :aria-label="'Copy ' + value + ' to clipboard'"
                                class="absolute right-0 opacity-0 group-hover/value:opacity-100 focus:opacity-100 transition-opacity duration-300 py-0.5 pl-2 pr-1 rounded-r bg-gradient-to-r from-gray-100/90 via-gray-100/100 to-gray-100 dark:from-gray-700/0 dark:via-gray-700/70 dark:to-gray-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-1"
                            >
                                <x-heroicon-m-clipboard-document
                                    x-show="copiedIndex !== index"
                                    class="size-4 text-primary-500"
                                    aria-hidden="true"
                                />
                                <x-heroicon-m-check
                                    x-show="copiedIndex === index"
                                    x-cloak
                                    class="size-4 text-green-500"
                                    aria-hidden="true"
                                />
                            </button>
                        </div>

                        {{-- Delete Button --}}
                        <button
                            type="button"
                            x-on:click.stop="deleteValue(value)"
                            :aria-label="'Delete ' + value"
                            class="opacity-0 group-hover:opacity-100 focus:opacity-100 shrink-0 rounded p-1 text-gray-400 hover:text-danger-500 hover:bg-danger-50 dark:hover:bg-danger-500/10 transition-all focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-1"
                        >
                            <x-heroicon-m-trash class="size-4" aria-hidden="true" />
                        </button>
                    </div>
                </template>
            </div>
        </template>

        {{-- Add New Value --}}
        <div class="flex items-center gap-2 px-3 py-2 border-t border-gray-100 dark:border-gray-800">
            {{-- Plus Icon --}}
            <x-heroicon-m-plus class="size-4 text-gray-400 shrink-0" aria-hidden="true" />

            <input
                type="text"
                inputmode="{{ $inputmode }}"
                x-model="newValue"
                x-ref="newInput"
                x-on:keydown.enter="handleEnter($event)"
                aria-label="Add new value"
                class="flex-1 bg-transparent border-0 p-0 text-sm text-gray-900 dark:text-gray-100 placeholder:text-gray-400 dark:placeholder:text-gray-500 focus:ring-0 focus:outline-none"
                placeholder="{{ $placeholder }}"
            />
            <button
                type="button"
                x-on:click.stop="addValue()"
                :disabled="!newValue.trim()"
                aria-label="Add value"
                class="shrink-0 rounded p-1 text-gray-400 hover:text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-500/10 transition-all disabled:opacity-50 disabled:cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-1"
            >
                <x-heroicon-m-arrow-right class="size-4" aria-hidden="true" />
            </button>
        </div>
    </div>
</div>
