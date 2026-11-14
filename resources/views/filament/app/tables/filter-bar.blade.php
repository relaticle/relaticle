<div
    class="fi-filter-bar"
    data-filter-bar
    data-state="{{ json_encode($this->getFilterBarData()) }}"
    x-data="filterBar"
    x-effect="open && measureRoom()"
    x-on:click.document="outside($event)"
    x-on:keydown.escape="escape($event)"
    x-on:focusout="focusLeft($event)"
    wire:key="{{ $this->getId() }}.table.filter-bar"
>
    <div class="fi-filter-bar-scroll" data-filter-scroll>
    <div class="fi-filter-bar-start" x-ref="strip" x-on:scroll.passive="fade()" x-on:focusin="$event.target.matches(':focus-visible') && revealFocus($event.target)" wire:ignore role="group" aria-label="{{ __('filament/panel.tables.filters.bar') }}">
        @include('filament.app.tables.filter-sort')

        <template x-if="groups.length > 0">
        <div class="fi-filter-part">
            <button
                type="button"
                tabindex="0"
                class="fi-filter-ghost"
                data-filter-group
                data-pop-trigger
                x-bind:class="{ 'fi-set': grouping, 'fi-open': isOpen('group', 'main') }"
                x-bind:aria-expanded="isOpen('group', 'main').toString()"
                x-bind:aria-label="grouping ? @js(__('filament/panel.tables.filters.group_set')).replace(':group', () => grouping.label).replace(':direction', () => grouping.direction === 'asc' ? @js(__('filament/panel.tables.filters.sort_ascending')) : @js(__('filament/panel.tables.filters.sort_descending'))) : @js(__('filament/panel.tables.filters.group'))"
                aria-haspopup="menu"
                x-on:click="toggle('group', 'main', $event)"
            >
                <x-filament::icon icon="lucide-layers" x-show="! grouping" />
                <x-filament::icon icon="lucide-arrow-up-narrow-wide" x-show="grouping?.direction === 'asc'" />
                <x-filament::icon icon="lucide-arrow-down-wide-narrow" x-show="grouping?.direction === 'desc'" />
                <span x-text="grouping ? grouping.label : @js(__('filament/panel.tables.filters.group'))"></span>
            </button>

            <template x-if="isOpen('group', 'main')">
            <div class="fi-filter-pop" x-anchor.bottom-start.offset.6.fixed="$el.parentElement" data-pop data-sheet x-bind:data-open="isOpen('group', 'main')" role="menu" aria-label="{{ __('filament/panel.tables.filters.group_by') }}" x-on:keydown="navigate($event)">
                <div class="fi-filter-options">
                    <template x-for="group in groups" x-bind:key="group.id">
                        <button
                            type="button"
                            tabindex="0"
                            role="menuitemradio"
                            class="fi-filter-item"
                            data-pop-item
                            data-group-option
                            x-bind:data-group="group.id"
                            x-bind:aria-checked="(grouping?.id === group.id).toString()"
                            x-on:click="groupBy(group)"
                        >
                            <svg x-show="group.icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path x-bind:d="group.icon"></path></svg>
                            <span class="fi-filter-label" x-text="group.label"></span>
                            <x-filament::icon icon="lucide-check" class="fi-menu-check" x-show="grouping?.id === group.id" />
                        </button>
                    </template>
                </div>
                <template x-if="grouping">
                    <div>
                        <div class="fi-filter-rule" aria-hidden="true"></div>
                        <button type="button" tabindex="0" role="menuitem" class="fi-filter-item" data-pop-item data-group-flip x-on:click="flipGrouping()">
                            <x-filament::icon icon="lucide-arrow-down-wide-narrow" x-show="grouping.direction === 'asc'" />
                            <x-filament::icon icon="lucide-arrow-up-narrow-wide" x-show="grouping.direction === 'desc'" />
                            <span x-text="grouping.direction === 'asc' ? @js(__('filament/panel.tables.filters.sort_flip_to_descending')) : @js(__('filament/panel.tables.filters.sort_flip_to_ascending'))"></span>
                        </button>
                        <button type="button" tabindex="0" role="menuitem" class="fi-filter-item fi-danger" data-pop-item data-group-clear x-on:click="clearGrouping()">
                            <x-filament::icon icon="lucide-x" />
                            {{ __('filament/panel.tables.filters.group_clear') }}
                        </button>
                    </div>
                </template>
            </div>
            </template>
        </div>
        </template>

        <template x-if="sortable.length > 0 || groups.length > 0">
            <span class="fi-filter-sep" aria-hidden="true"></span>
        </template>

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
                <div class="fi-filter-part">
                    <button
                        type="button"
                        tabindex="0"
                        class="fi-filter-seg fi-filter-field"
                        data-filter-segment="field"
                        x-bind:class="{ 'fi-open': isOpen(chip.id, 'field') }"
                        x-bind:aria-expanded="isOpen(chip.id, 'field').toString()"
                        aria-haspopup="listbox"
                        x-bind:aria-label="@js(__('filament/panel.tables.filters.change_field_for')).replace(':chip', () => label(chip))"
                        data-pop-trigger
                        x-on:click="toggle(chip.id, 'field', $event)"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path x-bind:d="fieldOf(chip)?.icon"></path></svg>
                        <span x-text="fieldOf(chip)?.label"></span>
                    </button>

                    <template x-if="isOpen(chip.id, 'field')">
                        @include('filament.app.tables.filter-field-picker', ['id' => 'chip.id', 'label' => __('filament/panel.tables.filters.change_field_for'), 'forChip' => true])
                    </template>
                </div>

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

                    <template x-if="isOpen(chip.id, 'cond')">
                    <div class="fi-filter-pop" x-anchor.bottom-start.offset.6.fixed="$el.parentElement" data-pop data-sheet x-bind:data-open="isOpen(chip.id, 'cond')" role="listbox" x-bind:aria-label="@js(__('filament/panel.tables.filters.conditions_for')).replace(':chip', () => fieldOf(chip).label)" x-on:keydown="navigate($event)">
                        <template x-for="condition in fieldOf(chip)?.conditions ?? []" x-bind:key="condition.value">
                            <button
                                type="button"
                                tabindex="0"
                                role="option"
                                class="fi-filter-item"
                                data-pop-item
                                x-bind:data-condition="condition.value"
                                x-bind:aria-selected="(condition.value === chip.condition).toString()"
                                x-on:click="pickCondition(chip, condition)"
                            >
                                <span x-text="condition.label"></span>
                                <x-filament::icon icon="lucide-check" class="fi-menu-check" x-show="condition.value === chip.condition" />
                            </button>
                        </template>
                    </div>
                    </template>
                </div>

                <template x-if="conditionOf(chip)?.takesValue">
                    <div class="fi-filter-part">
                        <button
                            type="button"
                            tabindex="0"
                            class="fi-filter-seg fi-filter-val"
                            data-filter-segment="value"
                            x-bind:class="{ 'fi-placeholder': ! complete(chip), 'fi-open': isOpen(chip.id, 'val') }"
                            x-bind:title="complete(chip) ? chip.display : null"
                            x-bind:aria-expanded="isOpen(chip.id, 'val').toString()"
                            aria-haspopup="dialog"
                            data-pop-trigger
                            x-on:click="toggle(chip.id, 'val', $event)"
                        >
                            <template x-if="markedValues(chip).length === 0">
                                <span x-text="complete(chip) ? chip.display : @js(__('filament/panel.tables.filters.select_value'))"></span>
                            </template>
                            <template x-if="markedValues(chip).length > 0">
                            <span class="fi-filter-values">
                                <template x-for="(item, index) in markedValues(chip)" x-bind:key="item.id">
                                    <span class="fi-filter-value">
                                        @include('filament.app.tables.filter-mark', ['of' => 'item'])
                                        <span class="fi-filter-value-label" x-text="item.label"></span>
                                        <span x-show="index < markedValues(chip).length - 1" aria-hidden="true">,</span>
                                    </span>
                                </template>
                                <span class="fi-filter-more-values" x-show="hiddenValues(chip) > 0" x-text="'+' + hiddenValues(chip)"></span>
                            </span>
                            </template>
                        </button>


                        <template x-if="isOpen(chip.id, 'val')">
                        <div class="fi-filter-pop" x-anchor.bottom-start.offset.6.fixed="$el.parentElement" data-pop data-sheet x-bind:data-open="isOpen(chip.id, 'val')" role="dialog" x-bind:aria-label="@js(__('filament/panel.tables.filters.value_for')).replace(':chip', () => label(chip))" x-on:keydown="navigate($event)">
                            <template x-if="isListEditor(chip)">
                                <div class="fi-filter-searchable">
                                    <template x-if="showSearch(chip)">
                                        <label class="fi-filter-find">
                                            <x-filament::icon icon="lucide-search" />
                                            <input
                                                type="search"
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
                                    <div class="fi-filter-options" role="listbox" aria-multiselectable="true" x-bind:aria-label="@js(__('filament/panel.tables.filters.value_for')).replace(':chip', () => label(chip))">
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
                                                @include('filament.app.tables.filter-mark', ['of' => 'option', 'tagged' => true])
                                                <span class="fi-filter-label" x-text="option.label"></span>
                                            </button>
                                        </template>
                                        <p class="fi-menu-empty" x-show="listItems(chip).length === 0 && ! searching" x-text="@js(__('filament/panel.tables.filters.no_options'))"></p>
                                    </div>
                                </div>
                            </template>

                            <template x-if="isDateEditor(chip)">
                                <div class="fi-filter-editor">
                                    <input
                                        type="date"
                                        x-bind:value="chip.value === '@today' ? '' : (chip.value ?? '')"
                                        x-bind:aria-label="label(chip)"
                                        data-filter-value
                                        x-on:input="setDate(chip, $event.target.value)"
                                        x-on:keydown.enter.prevent="finish()"
                                    />
                                    <button type="button" class="fi-filter-today" data-filter-today x-on:click="setToday(chip, @js(__('filament/panel.tables.filters.today')))">{{ __('filament/panel.tables.filters.today_shortcut') }}</button>
                                </div>
                            </template>

                            <template x-if="isPeriodEditor(chip)">
                                <div>
                                    <div class="fi-filter-editor">
                                        <input
                                            type="number"
                                            min="1"
                                            step="1"
                                            inputmode="numeric"
                                            x-bind:max="unitOf(periodUnit(chip))?.max"
                                            x-bind:value="chip.value?.amount ?? ''"
                                            placeholder="1"
                                            aria-label="{{ __('filament/panel.tables.filters.amount') }}"
                                            data-filter-amount
                                            x-on:input="setPeriod(chip, { amount: $event.target.value })"
                                            x-on:keydown.enter.prevent="finish()"
                                        />
                                        <select data-filter-unit aria-label="{{ __('filament/panel.tables.filters.unit') }}" x-on:change="setPeriod(chip, { unit: $event.target.value })">
                                            <template x-for="unit in units" x-bind:key="unit.value">
                                                <option x-bind:value="unit.value" x-bind:selected="periodUnit(chip) === unit.value" x-text="unit.label"></option>
                                            </template>
                                        </select>
                                    </div>
                                    <p class="fi-filter-hint">{{ __('filament/panel.tables.filters.stays_relative') }}</p>
                                </div>
                            </template>

                            <template x-if="isUnitEditor(chip)">
                                <div>
                                    <div role="listbox" x-bind:aria-label="label(chip)">
                                        <template x-for="unit in units" x-bind:key="unit.value">
                                            <button
                                                type="button"
                                                tabindex="0"
                                                role="option"
                                                class="fi-filter-item"
                                                data-pop-item
                                                x-bind:data-unit="unit.value"
                                                x-bind:aria-selected="(chip.value?.unit === unit.value).toString()"
                                                x-on:click="pickUnit(chip, unit)"
                                            >
                                                <span x-text="unit.singular"></span>
                                                <x-filament::icon icon="lucide-check" class="fi-menu-check" x-show="chip.value?.unit === unit.value" />
                                            </button>
                                        </template>
                                    </div>
                                    <p class="fi-filter-hint">{{ __('filament/panel.tables.filters.stays_relative') }}</p>
                                </div>
                            </template>

                            <template x-if="! isListEditor(chip) && ! isDateEditor(chip) && ! isPeriodEditor(chip) && ! isUnitEditor(chip)">
                                <div class="fi-filter-editor">
                                    <input
                                        x-bind:type="inputType(chip)"
                                        x-bind:step="fieldOf(chip).kind === 'number' ? 'any' : (fieldOf(chip).kind === 'inactivity' ? '1' : null)"
                                        x-bind:min="fieldOf(chip).kind === 'inactivity' ? '1' : null"
                                        x-bind:max="fieldOf(chip).kind === 'inactivity' ? fieldOf(chip).limit : null"
                                        x-bind:value="chip.value ?? ''"
                                        maxlength="255"
                                        x-bind:placeholder="inputType(chip) === 'number' ? @js(__('filament/panel.tables.filters.number_value')) : @js(__('filament/panel.tables.filters.type_value'))"
                                        x-bind:aria-label="label(chip)"
                                        data-filter-value
                                        x-on:input="setValue(chip, $event.target.value)"
                                        x-on:keydown.enter.prevent="finish()"
                                    />
                                    <span class="fi-filter-suffix" x-show="fieldOf(chip).kind === 'inactivity'" x-text="unitOf('days')?.label"></span>
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
                        x-bind:aria-label="@js(__('filament/panel.tables.filters.more_for')).replace(':chip', () => label(chip))"
                        data-pop-trigger
                        x-on:click="toggle(chip.id, 'more', $event)"
                    >
                        <x-filament::icon icon="lucide-ellipsis-vertical" />
                    </button>

                    <template x-if="isOpen(chip.id, 'more')">
                    <div class="fi-filter-pop" x-anchor.bottom-end.offset.6.fixed="$el.parentElement" data-pop data-sheet x-bind:data-open="isOpen(chip.id, 'more')" role="menu" x-bind:aria-label="@js(__('filament/panel.tables.filters.menu_for')).replace(':chip', () => label(chip))" x-on:keydown="navigate($event)">
                        <button type="button" tabindex="0" role="menuitem" class="fi-filter-item fi-danger" data-pop-item data-filter-remove x-on:click="remove(chip)">
                            <x-filament::icon icon="lucide-trash-2" />
                            {{ __('filament/panel.tables.filters.remove') }}
                        </button>
                    </div>
                    </template>
                </div>
            </div>
        </template>

        <template x-if="deleted">
            <div class="fi-filter-chip" role="group" data-filter-chip data-filter-deleted x-bind:aria-label="@js(__('filament/panel.tables.filters.deleted_mode_for')).replace(':mode', () => deleted === 'only' ? @js(__('filament/panel.tables.filters.deleted_only')) : @js(__('filament/panel.tables.filters.deleted_included')))">
                <span class="fi-filter-seg fi-filter-field">
                    <x-filament::icon icon="lucide-trash-2" />
                    <span>{{ __('filament/panel.tables.filters.deleted_records') }}</span>
                </span>

                <div class="fi-filter-part">
                    <button
                        type="button"
                        tabindex="0"
                        class="fi-filter-seg fi-filter-val"
                        data-filter-segment="value"
                        data-deleted-mode-trigger
                        data-pop-trigger
                        x-bind:class="{ 'fi-open': isOpen('deleted', 'val') }"
                        x-bind:aria-expanded="isOpen('deleted', 'val').toString()"
                        aria-haspopup="menu"
                        x-on:click="toggle('deleted', 'val', $event)"
                    >
                        <span x-text="deleted === 'only' ? @js(__('filament/panel.tables.filters.deleted_only')) : @js(__('filament/panel.tables.filters.deleted_included'))"></span>
                    </button>

                    <template x-if="isOpen('deleted', 'val')">
                    <div class="fi-filter-pop" x-anchor.bottom-start.offset.6.fixed="$el.parentElement" data-pop data-sheet x-bind:data-open="isOpen('deleted', 'val')" role="menu" aria-label="{{ __('filament/panel.tables.filters.deleted_records') }}" x-on:keydown="navigate($event)">
                        <button type="button" tabindex="0" role="menuitemradio" class="fi-filter-item" data-pop-item data-deleted-mode="included" x-bind:aria-checked="(deleted === 'included').toString()" x-on:click="setDeleted('included')">
                            <span>{{ __('filament/panel.tables.filters.deleted_included') }}</span>
                            <x-filament::icon icon="lucide-check" class="fi-menu-check" x-show="deleted === 'included'" />
                        </button>
                        <button type="button" tabindex="0" role="menuitemradio" class="fi-filter-item" data-pop-item data-deleted-mode="only" x-bind:aria-checked="(deleted === 'only').toString()" x-on:click="setDeleted('only')">
                            <span>{{ __('filament/panel.tables.filters.deleted_only') }}</span>
                            <x-filament::icon icon="lucide-check" class="fi-menu-check" x-show="deleted === 'only'" />
                        </button>
                    </div>
                    </template>
                </div>

                <div class="fi-filter-part">
                    <button type="button" tabindex="0" class="fi-filter-seg fi-filter-more" data-deleted-remove aria-label="{{ __('filament/panel.tables.filters.deleted_remove') }}" x-on:click="removeDeleted()">
                        <x-filament::icon icon="lucide-x" />
                    </button>
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
                @include('filament.app.tables.filter-field-picker', ['id' => "'add'", 'label' => __('filament/panel.tables.filters.add_label'), 'forChip' => false])
            </template>
        </div>
    </div>
    </div>
</div>
