<template x-if="sortable.length > 0">
<div class="fi-filter-part">
    <button
        type="button"
        tabindex="0"
        class="fi-filter-ghost"
        data-filter-sort
        data-pop-trigger
        x-bind:class="{ 'fi-set': sorts.length > 0, 'fi-open': isOpen('sort', 'main') || isOpen('sort', 'pick') }"
        x-bind:aria-expanded="(isOpen('sort', 'main') || isOpen('sort', 'pick')).toString()"
        x-bind:aria-label="sortSummary(@js(__('filament/panel.tables.filters.sort')), @js(__('filament/panel.tables.filters.sort_set')), @js(__('filament/panel.tables.filters.sort_set_more')), @js(__('filament/panel.tables.filters.sort_ascending')), @js(__('filament/panel.tables.filters.sort_descending')))"
        aria-haspopup="dialog"
        x-on:click="isOpen('sort', 'pick') ? close(false) : toggle('sort', 'main', $event)"
    >
        <x-filament::icon icon="lucide-arrow-up-down" x-show="sorts.length === 0" />
        <x-filament::icon icon="lucide-arrow-up-narrow-wide" x-show="sorts[0]?.direction === 'asc'" />
        <x-filament::icon icon="lucide-arrow-down-wide-narrow" x-show="sorts[0]?.direction === 'desc'" />
        <template x-if="sorts.length === 0"><span class="max-sm:hidden">{{ __('filament/panel.tables.filters.sort') }}</span></template>
        <template x-if="sorts.length > 0">
            <span class="fi-filter-sorted inline-flex min-w-0 gap-1 max-sm:hidden" data-filter-sort-label>
                <span class="fi-filter-muted">{{ __('filament/panel.tables.filters.sorted_by') }}</span>
                <span class="fi-filter-sorted-column" x-text="sorts[0].label"></span>
                <span class="fi-filter-muted" x-show="sorts.length > 1" x-text="@js(__('filament/panel.tables.filters.sort_more')).replace(':count', () => sorts.length - 1)" data-filter-sort-more></span>
            </span>
        </template>
    </button>

    <template x-if="isOpen('sort', 'main') || isOpen('sort', 'pick')">
        <div
            class="fi-filter-pop"
            x-bind:class="{ 'fi-filter-pop-picker': choosingSort() }"
            x-anchor.bottom-start.offset.6.fixed="$el.parentElement"
            data-pop
            data-sheet
            data-sort-pop
            x-bind:data-open="isOpen('sort', 'main') || isOpen('sort', 'pick')"
            role="dialog"
            aria-label="{{ __('filament/panel.tables.filters.sort_by') }}"
            x-on:keydown="navigate($event)"
        >
            <template x-if="choosingSort()">
                <div>
                    <label class="fi-filter-find">
                        <x-filament::icon icon="lucide-search" />
                        <input
                            type="text"
                            x-model="find"
                            data-filter-find
                            placeholder="{{ __('filament/panel.tables.filters.find_sort') }}"
                            aria-label="{{ __('filament/panel.tables.filters.find_sort') }}"
                            x-on:keydown.enter.prevent="pickFirstSort()"
                        />
                    </label>
                    <div class="fi-filter-options" role="listbox" aria-label="{{ __('filament/panel.tables.filters.sort_by') }}">
                        <template x-for="column in sortChoices()" x-bind:key="column.key">
                            <button
                                type="button"
                                tabindex="0"
                                role="option"
                                class="fi-filter-item"
                                data-pop-item
                                data-sort-column
                                x-bind:data-column="column.key"
                                x-bind:class="{ 'fi-on': column.key === sortTarget }"
                                x-bind:aria-selected="(column.key === sortTarget).toString()"
                                x-on:click="pickSort(column)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path x-bind:d="column.icon"></path></svg>
                                <span class="fi-filter-label" x-text="column.label"></span>
                                <x-filament::icon icon="lucide-check" class="fi-menu-check" x-show="column.key === sortTarget" />
                            </button>
                        </template>
                        <p class="fi-filter-hint" x-show="sortChoices().length === 0">{{ __('filament/panel.tables.filters.no_field') }}</p>
                    </div>
                </div>
            </template>

            <template x-if="! choosingSort()">
                <div class="fi-filter-sorts">
                    <div class="fi-filter-sort-list" x-sort="reorderSort($key, $position)" x-sort:config="{ animation: 150 }">
                        <template x-for="sort in sorts" x-bind:key="sort.column">
                            <div class="fi-filter-sort-row" x-sort:item="sort.column" data-sort-row x-bind:data-column="sort.column">
                                <button
                                    type="button"
                                    tabindex="0"
                                    class="fi-filter-sort-grip"
                                    x-sort:handle
                                    data-pop-item
                                    data-sort-grip
                                    x-bind:aria-label="@js(__('filament/panel.tables.filters.sort_move')).replace(':column', () => sort.label)"
                                    x-on:keydown.arrow-up.stop.prevent="moveSort(sort.column, -1)"
                                    x-on:keydown.arrow-down.stop.prevent="moveSort(sort.column, 1)"
                                >
                                    <x-filament::icon icon="lucide-grip-vertical" />
                                </button>
                                <button
                                    type="button"
                                    tabindex="0"
                                    class="fi-filter-sort-select fi-filter-sort-field"
                                    data-pop-item
                                    data-sort-field
                                    x-bind:aria-label="@js(__('filament/panel.tables.filters.sort_field')).replace(':column', () => sort.label)"
                                    x-on:click="chooseSort(sort.column)"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path x-bind:d="sortIcon(sort.column)"></path></svg>
                                    <span class="fi-filter-label" x-text="sort.label"></span>
                                    <x-filament::icon icon="lucide-chevron-down" class="fi-filter-sort-chevron" />
                                </button>
                                <button
                                    type="button"
                                    tabindex="0"
                                    class="fi-filter-sort-select fi-filter-sort-direction"
                                    data-pop-item
                                    data-sort-direction
                                    x-bind:aria-label="@js(__('filament/panel.tables.filters.sort_direction')).replace(':column', () => sort.label).replace(':direction', () => sort.direction === 'asc' ? @js(__('filament/panel.tables.filters.sort_ascending')) : @js(__('filament/panel.tables.filters.sort_descending')))"
                                    x-on:click="flipSortAt(sort.column)"
                                >
                                    <x-filament::icon icon="lucide-arrow-up-narrow-wide" x-show="sort.direction === 'asc'" />
                                    <x-filament::icon icon="lucide-arrow-down-wide-narrow" x-show="sort.direction === 'desc'" />
                                    <span x-text="sort.direction === 'asc' ? @js(__('filament/panel.tables.filters.sort_ascending_label')) : @js(__('filament/panel.tables.filters.sort_descending_label'))"></span>
                                </button>
                                <button
                                    type="button"
                                    tabindex="0"
                                    class="fi-filter-sort-remove"
                                    data-pop-item
                                    data-sort-remove
                                    x-bind:aria-label="@js(__('filament/panel.tables.filters.sort_remove')).replace(':column', () => sort.label)"
                                    x-on:click="removeSort(sort.column)"
                                >
                                    <x-filament::icon icon="lucide-x" />
                                </button>
                            </div>
                        </template>
                    </div>
                    <button
                        type="button"
                        tabindex="0"
                        class="fi-filter-sort-add"
                        data-pop-item
                        data-sort-add
                        x-show="sorts.length < sortable.length"
                        x-on:click="chooseSort(null)"
                    >
                        <x-filament::icon icon="lucide-plus" />
                        {{ __('filament/panel.tables.filters.sort_add') }}
                    </button>
                    <div class="fi-filter-rule" aria-hidden="true"></div>
                    <a
                        href="{{ \Relaticle\Documentation\Support\DocUrl::helpPage('getting-started', 'find-anything-with-search-and-filters') }}#sort-and-group-a-list"
                        target="_blank"
                        class="fi-filter-item fi-filter-sort-learn"
                        data-pop-item
                        data-sort-learn
                    >
                        <x-filament::icon icon="lucide-circle-help" />
                        <span>{{ __('filament/panel.tables.filters.sort_learn') }}</span>
                    </a>
                </div>
            </template>
        </div>
    </template>
</div>
</template>
