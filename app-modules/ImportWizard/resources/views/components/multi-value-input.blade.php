@props([
    'value' => '',
    'placeholder' => 'Add value...',
    'disabled' => false,
    'inputType' => 'text',
    'borderless' => false,
    'errors' => [],
    'eventName' => 'input',
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
            'flex w-full min-h-[2rem] items-center gap-1.5 py-1 text-left focus:outline-none rounded transition-colors',
            'px-2.5 border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 hover:border-gray-300 dark:hover:border-gray-600' => !$borderless,
            'px-2 border-0 bg-transparent hover:bg-gray-50 dark:hover:bg-gray-800/50' => $borderless,
        ])
    >
        {{-- Content area --}}
        <div class="flex flex-1 items-center gap-1.5 overflow-hidden">
            {{-- Empty State --}}
            <template x-if="!hasValues">
                <span class="text-sm text-gray-400 dark:text-gray-500">
                    {{ $placeholder }}
                </span>
            </template>

            {{-- Visible Values as Tags --}}
            <template x-for="(value, index) in visibleValues" :key="`trigger-${value}-${index}`">
                <span
                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium truncate max-w-[120px]"
                    :class="hasError(value)
                        ? 'bg-danger-50 text-danger-700 dark:bg-danger-950/50 dark:text-danger-400'
                        : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300'"
                    :title="hasError(value) ? getError(value) : value"
                >
                    <template x-if="hasError(value)">
                        <x-heroicon-o-exclamation-triangle class="size-3 shrink-0" aria-hidden="true" />
                    </template>
                    <span class="truncate" x-text="value"></span>
                </span>
            </template>

            {{-- "+N more" indicator --}}
            <template x-if="hiddenCount > 0">
                <span class="text-xs text-gray-500 dark:text-gray-400 shrink-0">
                    +<span x-text="hiddenCount"></span>
                </span>
            </template>
        </div>

        {{-- Error count badge --}}
        <template x-if="hasErrors">
            <span
                x-tooltip="{ content: errorCount + ' invalid ' + (errorCount === 1 ? 'value' : 'values'), theme: $store.theme }"
                class="inline-flex items-center justify-center size-5 text-xs font-medium rounded-full bg-danger-100 text-danger-600 dark:bg-danger-900/50 dark:text-danger-400 shrink-0"
            >
                <span x-text="errorCount"></span>
            </span>
        </template>

        {{-- Chevron indicator --}}
        <x-heroicon-m-chevron-down
            class="size-4 text-gray-400 dark:text-gray-500 shrink-0 transition-transform duration-150"
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
        class="absolute z-50 w-full min-w-[240px] rounded-lg bg-white shadow-lg ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700 overflow-hidden"
    >
        {{-- Existing Values List --}}
        <template x-if="hasValues">
            <div
                x-sortable
                x-on:end.stop="reorderValues($event)"
                class="max-h-[240px] overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800"
            >
                <template x-for="(value, index) in values" :key="`${value}-${index}`">
                    <div
                        :x-sortable-item="index"
                        class="group flex items-center gap-2 px-3 py-2"
                    >
                        {{-- Drag Handle --}}
                        <div
                            x-sortable-handle
                            class="shrink-0 cursor-grab active:cursor-grabbing text-gray-300 dark:text-gray-600 hover:text-gray-400 dark:hover:text-gray-500"
                            x-show="values.length > 1"
                        >
                            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <circle cx="7" cy="5" r="1.5"/>
                                <circle cx="13" cy="5" r="1.5"/>
                                <circle cx="7" cy="10" r="1.5"/>
                                <circle cx="13" cy="10" r="1.5"/>
                                <circle cx="7" cy="15" r="1.5"/>
                                <circle cx="13" cy="15" r="1.5"/>
                            </svg>
                        </div>

                        {{-- Value content --}}
                        <div class="flex-1 min-w-0">
                            {{-- Invalid value as badge --}}
                            <template x-if="hasError(value)">
                                <span
                                    x-tooltip="{ content: getError(value), theme: $store.theme }"
                                    class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-xs font-medium bg-danger-50 text-danger-700 dark:bg-danger-950/50 dark:text-danger-400 cursor-help max-w-full"
                                >
                                    <x-heroicon-o-exclamation-triangle class="size-3 shrink-0" aria-hidden="true" />
                                    <span class="truncate" x-text="value"></span>
                                </span>
                            </template>

                            {{-- Valid value as link or text --}}
                            <template x-if="!hasError(value) && linkPrefix">
                                <a
                                    :href="getLink(value)"
                                    class="text-sm text-primary-600 dark:text-primary-400 hover:underline truncate"
                                    x-text="value"
                                ></a>
                            </template>
                            <template x-if="!hasError(value) && !linkPrefix">
                                <span class="text-sm text-gray-900 dark:text-white truncate" x-text="value"></span>
                            </template>
                        </div>

                        {{-- Delete Button --}}
                        <button
                            type="button"
                            x-on:click.stop="deleteValue(value)"
                            :aria-label="'Delete ' + value"
                            class="shrink-0 text-gray-400 hover:text-danger-500"
                        >
                            <x-heroicon-m-x-mark class="size-4" aria-hidden="true" />
                        </button>
                    </div>
                </template>
            </div>
        </template>

        {{-- Add New Value --}}
        <div class="flex items-center gap-2 px-3 py-2.5 bg-gray-50 dark:bg-gray-800/50">
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
                class="shrink-0 rounded-md px-2 py-1 text-xs font-medium text-primary-600 hover:bg-primary-50 dark:text-primary-400 dark:hover:bg-primary-950/50 transition-colors disabled:opacity-40 disabled:cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-primary-500"
            >
                Add
            </button>
        </div>
    </div>
</div>
