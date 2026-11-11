<div class="fi-filter-pop fi-filter-pop-picker" x-anchor.bottom-start.offset.6.fixed="$el.closest('.fi-filter-chip') ?? $el.parentElement" data-pop data-sheet x-bind:data-open="isOpen({{ $id }}, 'field')" x-on:keydown="navigate($event)">
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
    <div role="listbox" x-bind:aria-label="@js($label){{ $forChip ? ".replace(':chip', label(chip))" : '' }}">
        <template x-for="field in matchingFields()" x-bind:key="field.key">
            <button type="button" tabindex="0" role="option" class="fi-filter-item" data-pop-item x-bind:data-field="field.key" x-on:click="pickField(field)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path x-bind:d="field.icon"></path></svg>
                <span x-text="field.label"></span>
            </button>
        </template>
@unless ($forChip)
        <template x-if="canAddDeleted(@js(__('filament/panel.tables.filters.deleted_records')))">
            <div>
                <div class="fi-filter-rule" aria-hidden="true"></div>
                <button type="button" tabindex="0" role="option" class="fi-filter-item" data-pop-item data-filter-add-deleted x-on:click="addDeleted()">
                    <x-filament::icon icon="lucide-trash-2" />
                    <span>{{ __('filament/panel.tables.filters.deleted_records') }}</span>
                </button>
            </div>
        </template>
        <p class="fi-filter-hint" x-show="matchingFields().length === 0 && ! canAddDeleted(@js(__('filament/panel.tables.filters.deleted_records')))">{{ __('filament/panel.tables.filters.no_field') }}</p>
@else
        <p class="fi-filter-hint" x-show="matchingFields().length === 0">{{ __('filament/panel.tables.filters.no_field') }}</p>
@endunless
    </div>
</div>
