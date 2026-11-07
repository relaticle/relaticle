@use(App\Filament\Actions\PreviewRecordAction)
@php
    $step = fn (?string $key, int $position): string => PreviewRecordAction::open(['position' => $position], ['table' => true, 'recordKey' => $key]);
@endphp

<div class="fi-record-preview-header">
    <x-filament::icon-button
        icon="lucide-x"
        color="gray"
        :label="__('filament::components/modal.actions.close.label')"
        :x-on:click="'$dispatch(\'close-modal\', { id: ' . Js::from($modalId) . ' })'"
        class="fi-record-preview-close"
    />

    @if ($position !== null)
        <x-filament::icon-button
            icon="lucide-chevron-up"
            color="gray"
            size="sm"
            :label="__('filament/record-page.preview.previous')"
            :tooltip="__('filament/record-page.preview.previous')"
            :disabled="$previousKey === null"
            :x-on:click="$previousKey === null ? null : $step($previousKey, $position - 1)"
            class="fi-record-preview-header-btn"
        />
        <x-filament::icon-button
            icon="lucide-chevron-down"
            color="gray"
            size="sm"
            :label="__('filament/record-page.preview.next')"
            :tooltip="__('filament/record-page.preview.next')"
            :disabled="$nextKey === null"
            :x-on:click="$nextKey === null ? null : $step($nextKey, $position + 1)"
            class="fi-record-preview-header-btn"
        />
        <span class="fi-record-preview-position">
            {{ __('filament/record-page.preview.position', ['position' => Number::format($position + 1), 'total' => Number::format($total), 'label' => $pluralLabel]) }}
        </span>
    @endif

    <div class="ms-auto flex">
        <x-filament::icon-button
            icon="lucide-maximize-2"
            color="gray"
            size="sm"
            tag="a"
            :href="$recordUrl"
            :label="__('filament/record-page.actions.open_record')"
            :tooltip="__('filament/record-page.actions.open_record')"
            class="fi-record-preview-header-btn"
        />
    </div>
</div>
