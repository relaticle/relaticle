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
    <div class="fi-filter-bar-scroll" data-filter-scroll>
    <div class="fi-filter-bar-start" x-ref="strip" x-on:scroll.passive="fade()" x-on:focusin="$event.target.matches(':focus-visible') && revealFocus($event.target)" wire:ignore role="group" aria-label="{{ __('filament/panel.tables.filters.bar') }}">
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
                    <template x-if="fieldOf(chip).editable">
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
                    </template>

                    <template x-if="! fieldOf(chip).editable">
                        <span class="fi-filter-seg fi-filter-cond" data-filter-segment="condition">
                            <span x-text="conditionOf(chip)?.label"></span>
                        </span>
                    </template>

                    <template x-if="isOpen(chip.id, 'cond')">
                    <div class="fi-filter-pop" x-anchor.bottom-start.offset.6.fixed="$el.closest('.fi-filter-chip')" data-pop data-sheet x-bind:data-open="isOpen(chip.id, 'cond')" role="listbox" x-bind:aria-label="@js(__('filament/panel.tables.filters.conditions_for')).replace(':chip', fieldOf(chip).label)" x-on:keydown="navigate($event)">
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
                    </template>
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

                        <template x-if="isOpen(chip.id, 'val')">
                        <div class="fi-filter-pop" x-anchor.bottom-start.offset.6.fixed="$el.closest('.fi-filter-chip')" data-pop data-sheet x-bind:data-open="isOpen(chip.id, 'val')" role="dialog" x-bind:aria-label="@js(__('filament/panel.tables.filters.value_for')).replace(':chip', label(chip))" x-on:keydown="navigate($event)">
                            <template x-if="isListEditor(chip)">
                                <div class="fi-filter-checklist">
                                    <template x-if="showSearch(chip)">
                                        <label class="fi-filter-find">
                                            <x-filament::icon icon="lucide-search" />
                                            <input
                                                type="text"
                                                x-model="find"
                                                maxlength="100"
                                                data-filter-search
                                                placeholder="{{ __('filament/panel.tables.filters.search_options') }}"
                                                aria-label="{{ __('filament/panel.tables.filters.search_options') }}"
                                                x-on:input="typed(chip)"
                                                x-on:keydown.enter.prevent="finish()"
                                            />
                                        </label>
                                    </template>
                                    <div class="fi-filter-options" role="listbox" aria-multiselectable="true" x-bind:aria-label="@js(__('filament/panel.tables.filters.value_for')).replace(':chip', label(chip))">
                                        <template x-for="option in listItems(chip)" x-bind:key="option.id">
                                            <button
                                                type="button"
                                                tabindex="0"
                                                role="option"
                                                class="fi-filter-item"
                                                data-pop-item
                                                data-filter-option
                                                x-bind:data-option="option.id"
                                                x-bind:aria-selected="isChecked(chip, option.id).toString()"
                                                x-on:click="toggleOption(chip, option)"
                                            >
                                                <span class="fi-filter-box" x-bind:class="{ 'fi-on': isChecked(chip, option.id) }">
                                                    <x-filament::icon icon="lucide-check" x-show="isChecked(chip, option.id)" />
                                                </span>
                                                <span class="fi-filter-dot" x-show="optionColor(option)" x-bind:style="{ '--option-color': optionColor(option) }"></span>
                                                <span class="fi-filter-label" x-text="option.label"></span>
                                            </button>
                                        </template>
                                        <p class="fi-filter-hint" x-show="listItems(chip).length === 0 && ! searching" x-text="@js(__('filament/panel.tables.filters.no_options'))"></p>
                                    </div>
                                </div>
                            </template>

                            <template x-if="! isListEditor(chip)">
                                <div class="fi-filter-editor">
                                    <input
                                        x-bind:type="fieldOf(chip).kind === 'number' ? 'number' : 'text'"
                                        x-bind:step="fieldOf(chip).kind === 'number' ? 'any' : null"
                                        x-bind:value="chip.value ?? ''"
                                        maxlength="255"
                                        x-bind:placeholder="fieldOf(chip).kind === 'number' ? @js(__('filament/panel.tables.filters.number_value')) : @js(__('filament/panel.tables.filters.type_value'))"
                                        x-bind:aria-label="label(chip)"
                                        data-filter-value
                                        x-on:input="setValue(chip, $event.target.value)"
                                        x-on:keydown.enter.prevent="finish()"
                                    />
                                </div>
                            </template>
                        </div>
                        </template>
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
                        x-bind:aria-label="@js(__('filament/panel.tables.filters.more_for')).replace(':chip', label(chip))"
                        data-pop-trigger
                        x-on:click="toggle(chip.id, 'more', $event)"
                    >
                        <x-filament::icon icon="lucide-ellipsis-vertical" />
                    </button>

                    <template x-if="isOpen(chip.id, 'more')">
                    <div class="fi-filter-pop" x-anchor.bottom-end.offset.6.fixed="$el.closest('.fi-filter-chip')" data-pop data-sheet x-bind:data-open="isOpen(chip.id, 'more')" role="menu" x-bind:aria-label="@js(__('filament/panel.tables.filters.menu_for')).replace(':chip', label(chip))" x-on:keydown="navigate($event)">
                        <button type="button" tabindex="0" role="menuitem" class="fi-filter-item fi-danger" data-pop-item data-filter-remove x-on:click="remove(chip)">
                            <x-filament::icon icon="lucide-trash-2" />
                            {{ __('filament/panel.tables.filters.remove') }}
                        </button>
                    </div>
                    </template>
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

            <template x-if="isOpen('add', 'field')">
            <div class="fi-filter-pop fi-filter-pop-picker" x-anchor.bottom-start.offset.6.fixed="$el.parentElement" data-pop data-sheet x-bind:data-open="isOpen('add', 'field')" x-on:keydown="navigate($event)">
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
            </template>
        </div>
    </div>
    </div>

    <div class="fi-filter-bar-end">
        @include('filament.app.tables.view-save')
    </div>
</div>
