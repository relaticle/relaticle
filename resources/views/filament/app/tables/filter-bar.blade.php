<div
    class="fi-filter-bar"
    data-filter-bar
    data-state="{{ json_encode($this->getFilterBarData()) }}"
    x-data="filterBar"
    x-on:click.document="outside($event)"
    x-on:keydown.escape="escape($event)"
    x-on:focusout="focusLeft($event)"
    wire:key="{{ $this->getId() }}.table.filter-bar"
>
    <div class="fi-filter-bar-start" wire:ignore role="group" aria-label="{{ __('filament/panel.tables.filters.bar') }}">
        <button type="button" tabindex="0" disabled class="fi-filter-ghost" data-filter-sort>
            <x-filament::icon icon="lucide-arrow-up-down" />
            {{ __('filament/panel.tables.filters.sort') }}
        </button>

        <span class="fi-filter-sep" aria-hidden="true"></span>

        <template x-for="chip in chips" x-bind:key="chip.id">
            <div
                class="fi-filter-chip"
                role="group"
                data-filter-chip
                x-bind:data-field="chip.field"
                x-bind:data-chip-id="chip.id"
                x-bind:data-complete="complete(chip)"
                x-bind:class="{ 'fi-pending': ! complete(chip) }"
                x-bind:aria-label="label(chip)"
            >
                <span class="fi-filter-seg fi-filter-field">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path x-bind:d="fieldOf(chip)?.icon"></path></svg>
                    <span x-text="fieldOf(chip)?.label"></span>
                </span>

                <div class="fi-filter-part">
                    <button
                        type="button"
                        tabindex="0"
                        class="fi-filter-seg fi-filter-cond"
                        data-filter-segment="condition"
                        x-bind:class="{ 'fi-placeholder': ! chip.condition, 'fi-open': isOpen(chip.id, 'cond') }"
                        x-bind:aria-expanded="isOpen(chip.id, 'cond').toString()"
                        aria-haspopup="listbox"
                        data-pop-trigger
                        x-on:click="toggle(chip.id, 'cond', $event)"
                    >
                        <span x-text="conditionOf(chip)?.label ?? @js(__('filament/panel.tables.filters.select_condition'))"></span>
                    </button>

                    <div class="fi-filter-pop" data-pop data-sheet x-cloak x-show="isOpen(chip.id, 'cond')" x-bind:data-open="isOpen(chip.id, 'cond')" role="listbox" x-on:keydown="navigate($event)">
                        <template x-for="condition in fieldOf(chip)?.conditions ?? []" x-bind:key="condition.value">
                            <button
                                type="button"
                                tabindex="0"
                                role="option"
                                class="fi-filter-item"
                                data-pop-item
                                x-bind:data-condition="condition.value"
                                x-bind:class="{ 'fi-on': condition.value === chip.condition }"
                                x-bind:aria-selected="(condition.value === chip.condition).toString()"
                                x-on:click="pickCondition(chip, condition)"
                            >
                                <span x-text="condition.label"></span>
                                <x-filament::icon icon="lucide-check" class="fi-filter-tick" x-show="condition.value === chip.condition" />
                            </button>
                        </template>
                    </div>
                </div>

                <template x-if="conditionOf(chip)?.takesValue">
                    <div class="fi-filter-part">
                        <template x-if="fieldOf(chip).editable">
                            <button
                                type="button"
                                tabindex="0"
                                class="fi-filter-seg fi-filter-val"
                                data-filter-segment="value"
                                x-bind:class="{ 'fi-placeholder': ! complete(chip), 'fi-open': isOpen(chip.id, 'val') }"
                                x-bind:aria-expanded="isOpen(chip.id, 'val').toString()"
                                aria-haspopup="dialog"
                                data-pop-trigger
                                x-on:click="toggle(chip.id, 'val', $event)"
                            >
                                <span x-text="complete(chip) ? chip.display : @js(__('filament/panel.tables.filters.select_value'))"></span>
                            </button>
                        </template>

                        <template x-if="! fieldOf(chip).editable">
                            <span class="fi-filter-seg fi-filter-val" data-filter-segment="value">
                                <span x-text="chip.display"></span>
                            </span>
                        </template>

                        <div class="fi-filter-pop" data-pop data-sheet x-cloak x-show="isOpen(chip.id, 'val')" x-bind:data-open="isOpen(chip.id, 'val')" role="dialog" x-on:keydown="navigate($event)">
                            <div class="fi-filter-editor">
                                <input
                                    x-bind:type="fieldOf(chip).kind === 'number' ? 'number' : 'text'"
                                    x-bind:step="fieldOf(chip).kind === 'number' ? 'any' : null"
                                    x-bind:value="chip.value ?? ''"
                                    maxlength="255"
                                    placeholder="{{ __('filament/panel.tables.filters.type_value') }}"
                                    x-bind:aria-label="label(chip)"
                                    data-filter-value
                                    x-on:input="setValue(chip, $event.target.value)"
                                    x-on:keydown.enter.prevent="finish()"
                                />
                            </div>
                        </div>
                    </div>
                </template>

                <div class="fi-filter-part">
                    <button
                        type="button"
                        tabindex="0"
                        class="fi-filter-seg fi-filter-more"
                        data-filter-more
                        x-bind:class="{ 'fi-open': isOpen(chip.id, 'more') }"
                        x-bind:aria-expanded="isOpen(chip.id, 'more').toString()"
                        aria-haspopup="menu"
                        aria-label="{{ __('filament/panel.tables.filters.more') }}"
                        data-pop-trigger
                        x-on:click="toggle(chip.id, 'more', $event)"
                    >
                        <x-filament::icon icon="lucide-ellipsis-vertical" />
                    </button>

                    <div class="fi-filter-pop fi-filter-pop-end" data-pop data-sheet x-cloak x-show="isOpen(chip.id, 'more')" x-bind:data-open="isOpen(chip.id, 'more')" role="menu" x-on:keydown="navigate($event)">
                        <button type="button" tabindex="0" role="menuitem" class="fi-filter-item fi-danger" data-pop-item data-filter-remove x-on:click="remove(chip)">
                            <x-filament::icon icon="lucide-trash-2" />
                            {{ __('filament/panel.tables.filters.remove') }}
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <div class="fi-filter-part">
            <button
                type="button"
                tabindex="0"
                class="fi-filter-ghost"
                data-filter-add
                x-bind:class="{ 'fi-open': isOpen('add', 'field') }"
                x-bind:aria-expanded="isOpen('add', 'field').toString()"
                aria-haspopup="listbox"
                aria-label="{{ __('filament/panel.tables.filters.add_label') }}"
                data-pop-trigger
                x-on:click="toggle('add', 'field', $event)"
            >
                <x-filament::icon icon="lucide-plus" />
                <span x-show="chips.length === 0">{{ __('filament/panel.tables.filters.add') }}</span>
            </button>

            <div class="fi-filter-pop fi-filter-pop-picker" data-pop data-sheet x-cloak x-show="isOpen('add', 'field')" x-bind:data-open="isOpen('add', 'field')" x-on:keydown="navigate($event)">
                <label class="fi-filter-find">
                    <x-filament::icon icon="lucide-search" />
                    <input
                        type="text"
                        x-model="find"
                        data-filter-find
                        placeholder="{{ __('filament/panel.tables.filters.find_field') }}"
                        aria-label="{{ __('filament/panel.tables.filters.find_field') }}"
                        x-on:keydown.enter.prevent="pickFirst()"
                    />
                </label>
                <div role="listbox" aria-label="{{ __('filament/panel.tables.filters.add_label') }}">
                    <template x-for="field in matchingFields()" x-bind:key="field.key">
                        <button type="button" tabindex="0" role="option" class="fi-filter-item" data-pop-item x-bind:data-field="field.key" x-on:click="addChip(field)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path x-bind:d="field.icon"></path></svg>
                            <span x-text="field.label"></span>
                        </button>
                    </template>
                    <p class="fi-filter-hint" x-show="matchingFields().length === 0">{{ __('filament/panel.tables.filters.no_field') }}</p>
                </div>
            </div>
        </div>
    </div>

    <div class="fi-filter-bar-end">
        @include('filament.app.tables.view-save')
    </div>
</div>
