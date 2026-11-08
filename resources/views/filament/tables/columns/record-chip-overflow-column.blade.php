@php
    $chips = $getChips();
@endphp

@include('filament.tables.columns.partials.first-record-and-count', [
    'records' => array_map(fn (\App\Filament\Components\RecordChip $chip): array => ['chip' => $chip, 'url' => null], $chips),
    'placeholder' => $getPlaceholder(),
    'label' => $getLabel(),
    'showAllLabel' => __('filament/panel.record_chips.show_all', ['count' => count($chips), 'label' => str($getLabel())->lower()]),
])
