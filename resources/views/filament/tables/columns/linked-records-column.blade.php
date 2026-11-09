@php
    $records = $getLinkedRecords();
@endphp

@include('filament.tables.columns.partials.first-record-and-count', [
    'records' => $records,
    'placeholder' => $getPlaceholder(),
    'label' => __('filament/components/linked-records.label'),
    'showAllLabel' => __('filament/components/linked-records.show_all', ['count' => count($records)]),
])
