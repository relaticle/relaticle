@php
    $actions = array_filter($group->getActions(), fn (\Filament\Actions\Action | \Filament\Actions\ActionGroup $action): bool => $action->isVisible());
@endphp

<div
    x-cloak
    x-show="getSelectedRecordsCount()"
    role="toolbar"
    aria-label="{{ __('filament/panel.tables.bulk_bar.label') }}"
    class="fi-bulk-bar"
>
    <div class="fi-bulk-bar-selection">
        <span
            class="fi-bulk-bar-count"
            role="status"
            aria-live="polite"
            x-text="window.pluralize(@js(__('filament/panel.tables.bulk_bar.selected')), getSelectedRecordsCount(), { count: new Intl.NumberFormat(@js(str_replace('_', '-', app()->getLocale()))).format(getSelectedRecordsCount()) })"
        ></span>

        <button
            type="button"
            class="fi-bulk-bar-clear"
            aria-label="{{ __('filament/panel.tables.bulk_bar.clear') }}"
            x-on:click="deselectAllRecords"
        >
            <x-filament::icon icon="lucide-x" />
        </button>
    </div>

    <button
        type="button"
        class="fi-bulk-bar-select-all"
        x-show="canSelectAllRecords()"
        x-on:click="selectAllRecords"
        x-text="window.pluralize(@js(__('filament/panel.tables.bulk_bar.select_all')), Number($refs.allSelectableRecordsCount.value), { count: new Intl.NumberFormat(@js(str_replace('_', '-', app()->getLocale()))).format($refs.allSelectableRecordsCount.value) })"
    ></button>

    @if ($actions !== [])
        <div class="fi-bulk-bar-actions">
            @foreach ($actions as $action)
                {{ $action }}
            @endforeach
        </div>
    @endif
</div>
