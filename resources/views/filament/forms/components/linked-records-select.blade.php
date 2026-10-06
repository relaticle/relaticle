@php
    /** @var \App\Filament\Components\Forms\LinkedRecordsSelect $field */
    $id = $field->getId();
    $key = $field->getKey();
    $statePath = $field->getStatePath();
    $isDisabled = $field->isDisabled();
    $label = $field->getLabel();
@endphp

<div
    wire:ignore
    wire:key="{{ $field->getLivewireKey() }}.{{ (int) $isDisabled }}"
    x-data="linkedRecordsPicker({
        id: @js($id),
        state: $wire.{{ $field->applyStateBindingModifiers("\$entangle('{$statePath}')") }},
        selected: @js($field->getSelectedRecordsForJs()),
        isDisabled: @js($isDisabled),
        messages: @js([
            'searching' => __('filament/components/linked-records.searching'),
            'failed' => __('filament/components/linked-records.failed'),
            'none' => __('filament/components/linked-records.none'),
            'empty' => __('filament/components/linked-records.empty'),
            'linked' => __('filament/components/linked-records.linked'),
            'more' => __('filament/components/linked-records.more'),
            'result' => __('filament/components/linked-records.result'),
            'results' => __('filament/components/linked-records.results'),
            'remove' => __('filament/components/linked-records.remove'),
        ]),
        fetch: (search) => Livewire.fireAction(
            $wire.__instance,
            'callSchemaComponentMethod',
            [@js($key), 'getRecordGroupsForJs', { search }],
            { async: true },
        ),
    })"
    x-on:keydown.escape="if (open) { $event.stopPropagation(); closePanel(true) }"
    x-on:click.outside="closePanel()"
    x-on:focusout="if (! $el.contains($event.relatedTarget)) closePanel()"
    x-on:pointerenter.once="warm()"
    x-on:focusin.once="warm()"
    @class(['fi-linked-records', 'fi-linked-records-disabled' => $isDisabled])
>
    <span x-ref="anchor" class="fi-linked-records-anchor" aria-hidden="true"></span>

    <div
        x-on:mousedown="if (open) $event.preventDefault()"
        x-on:click="open ? closePanel(true) : openPanel()"
        class="fi-linked-records-field"
    >
        <div x-ref="chips" class="fi-linked-records-chips">
            <template x-for="(option, index) in selectedOptions" x-bind:key="option.value">
                <a
                    x-bind:href="option.url"
                    x-bind:title="option.name"
                    x-bind:class="{ 'fi-overflowed': index >= visibleCount, 'fi-shrinkable': visibleCount === 1 }"
                    x-html="option.html"
                    x-on:click.stop
                    target="_blank"
                    rel="noopener"
                    tabindex="-1"
                    class="fi-linked-records-chip"
                ></a>
            </template>

            <span x-show="selectedOptions.length === 0" class="fi-linked-records-placeholder">
                {{ $field->getPlaceholder() }}
            </span>

            <span
                x-ref="count"
                x-bind:class="{ 'fi-overflowed': hiddenCount === 0 }"
                x-text="`+${hiddenCount}`"
                class="fi-linked-records-count"
            ></span>
        </div>

        <button
            x-ref="trigger"
            x-on:keydown.down.prevent="openPanel()"
            x-bind:aria-expanded="open"
            id="{{ $id }}"
            type="button"
            aria-haspopup="listbox"
            aria-controls="{{ $id }}-listbox"
            aria-label="{{ $label }}"
            class="fi-linked-records-trigger"
            @disabled($isDisabled)
        >
            <x-filament::icon icon="ri-arrow-down-s-line" />
        </button>
    </div>

    <div
        x-ref="panel"
        x-cloak
        x-float.placement.bottom-start.flip.offset.size="{ offset: 6, size: { padding: 16 } }"
        x-on:mousedown="if ($event.target !== $refs.search) $event.preventDefault()"
        x-on:click.stop
        class="fi-linked-records-panel"
    >
        <div class="fi-linked-records-panel-body">
            <div class="fi-linked-records-search">
                <x-filament::icon icon="ri-search-line" />

                <input
                    x-ref="search"
                    x-model="query"
                    x-on:input="pending()"
                    x-on:input.debounce.{{ $field->getSearchDebounce() }}ms="search()"
                    x-on:keydown.down.prevent="move(1)"
                    x-on:keydown.up.prevent="move(-1)"
                    x-on:keydown.enter.prevent="$event.isComposing || choose()"
                    x-on:keydown.backspace="$event.repeat || removeLast()"
                    x-bind:aria-activedescendant="optionId(activeValue)"
                    type="text"
                    role="combobox"
                    aria-autocomplete="list"
                    aria-expanded="true"
                    aria-controls="{{ $id }}-listbox"
                    aria-label="{{ __('filament/components/linked-records.search') }}"
                    placeholder="{{ __('filament/components/linked-records.search') }}"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                />

                <x-filament::loading-indicator x-show="loading" x-cloak />
            </div>

            <div x-show="selectedOptions.length > 0" tabindex="-1" class="fi-linked-records-selected">
                <template x-for="option in selectedOptions" x-bind:key="option.value">
                    <span class="fi-linked-records-chip">
                        <span x-html="option.html"></span>

                        <button
                            x-on:click="remove(option.value)"
                            x-bind:aria-label="messages.remove.replace(':name', option.name)"
                            type="button"
                            tabindex="-1"
                            class="fi-linked-records-remove"
                        >
                            <x-filament::icon icon="ri-close-line" />
                        </button>
                    </span>
                </template>
            </div>

            <div
                x-bind:aria-busy="loading"
                id="{{ $id }}-listbox"
                role="listbox"
                tabindex="-1"
                aria-multiselectable="true"
                aria-label="{{ $label }}"
                class="fi-linked-records-list"
            >
                <template x-for="group in visibleGroups" x-bind:key="group.label">
                    <div role="group" x-bind:aria-label="group.label" class="fi-linked-records-group">
                        <div x-text="group.label" aria-hidden="true" class="fi-linked-records-group-label"></div>

                        <template x-for="option in group.options" x-bind:key="option.value">
                            <div
                                x-bind:id="optionId(option.value)"
                                x-bind:class="{ 'fi-active': activeValue === option.value }"
                                x-on:click="link(option)"
                                x-on:mousemove="activeValue = option.value"
                                role="option"
                                aria-selected="false"
                                class="fi-linked-records-option"
                            >
                                <span x-html="option.html" class="fi-linked-records-option-label"></span>
                                <span x-show="option.hint" x-text="option.hint" class="fi-linked-records-option-hint"></span>
                            </div>
                        </template>
                    </div>
                </template>

                <p x-show="message" x-text="message" class="fi-linked-records-message"></p>
            </div>

            <div x-text="announcement" role="status" aria-live="polite" class="sr-only"></div>
        </div>
    </div>
</div>
