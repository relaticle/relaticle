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
        open: false,
        documentClickListener: null,

        get rootEl() {
            return this.$refs.trigger?.closest('[data-multi-value-input]');
        },

        init() {
            this.documentClickListener = (event) => {
                if (!this.isOpen()) return;
                const clickedInTrigger = this.rootEl?.contains(event.target);
                const clickedInPanel = this.$refs.panel?.contains(event.target);
                if (!clickedInTrigger && !clickedInPanel) {
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

        get hiddenErrorCount() {
            return this.values.slice(this.maxVisibleValues).filter(v => this.hasError(v)).length;
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
            return this.open;
        },

        togglePanel() {
            if (this.isDisabled) return;
            this.open = !this.open;
            if (this.open) {
                this.newValue = '';
                this.$nextTick(() => this.$refs.newInput?.focus());
            }
        },

        openPanel() {
            if (this.isDisabled) return;
            this.open = true;
            this.newValue = '';
            this.$nextTick(() => this.$refs.newInput?.focus());
        },

        closePanel() {
            this.open = false;
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
            if (this.errors.hasOwnProperty(valueToDelete)) {
                delete this.errors[valueToDelete];
            }
            this.values = this.values.filter((v) => v !== valueToDelete);
            this.emitChange();
        },

        handleEnter(e) {
            e.preventDefault();
            this.addValue();
        },

        emitChange() {
            this.rootEl?.dispatchEvent(new CustomEvent(this.eventName, {
                detail: this.commaSeparated,
                bubbles: true,
            }));
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
    x-on:keydown.esc="isOpen() && (closePanel(), $event.stopPropagation())"
    x-on:update-errors="errors = $event.detail.errors || {}"
    data-multi-value-input
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
            <template x-for="(value, index) in visibleValues" :key="`${value}-${index}`">
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

            {{-- "+N more" indicator (turns red when hidden items have errors) --}}
            <template x-if="hiddenCount > 0">
                <span
                    :class="hiddenErrorCount > 0
                        ? 'text-danger-600 dark:text-danger-400 font-medium'
                        : 'text-gray-500 dark:text-gray-400'"
                    class="text-xs shrink-0"
                >
                    +<span x-text="hiddenCount"></span>
                </span>
            </template>
        </div>

        {{-- Chevron indicator --}}
        <x-heroicon-m-chevron-down
            class="size-4 text-gray-400 dark:text-gray-500 shrink-0 transition-transform duration-150"
            x-bind:class="{ 'rotate-180': isOpen() }"
            aria-hidden="true"
        />
    </button>

    {{-- Popover Panel - teleported to body to avoid Livewire re-render z-index issues --}}
    <template x-teleport="body">
        <div
            x-cloak
            x-show="open"
            x-anchor.bottom-start.offset.4="$refs.trigger"
            x-ref="panel"
            x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            role="dialog"
            aria-label="Manage values"
            class="fixed z-[9999] min-w-[240px] rounded-lg bg-white shadow-lg ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700 overflow-hidden"
            :style="{ width: $refs.trigger?.offsetWidth + 'px' }"
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
                        :x-sortable-item="value"
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
    </template>
</div>
