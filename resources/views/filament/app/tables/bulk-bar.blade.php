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
    <button
        type="button"
        class="fi-bulk-bar-selection"
        x-bind:aria-label="window.pluralize(@js(__('filament/panel.tables.bulk_bar.selected_count')), getSelectedRecordsCount(), { count: getSelectedRecordsCount() }) + '. ' + @js(__('filament/panel.tables.bulk_bar.clear'))"
        x-on:click="deselectAllRecords(); $el.closest('.fi-ta')?.querySelector('thead .fi-ta-selection-cell input[type=checkbox]')?.focus()"
    >
        <span
            class="fi-bulk-bar-count"
            x-text="new Intl.NumberFormat(@js(str_replace('_', '-', app()->getLocale()))).format(getSelectedRecordsCount())"
        ></span>
        <span class="fi-bulk-bar-selected">{{ __('filament/panel.tables.bulk_bar.selected') }}</span>
        <x-filament::icon icon="lucide-x" class="fi-bulk-bar-clear" />
    </button>

    <span
        class="sr-only"
        role="status"
        aria-live="polite"
        x-text="window.pluralize(@js(__('filament/panel.tables.bulk_bar.selected_count')), getSelectedRecordsCount(), { count: getSelectedRecordsCount() })"
    ></span>

    @if ($actions !== [])
        <div class="fi-bulk-bar-actions">
            @foreach ($actions as $action)
                {{ $action }}
            @endforeach
        </div>
    @endif
</div>
