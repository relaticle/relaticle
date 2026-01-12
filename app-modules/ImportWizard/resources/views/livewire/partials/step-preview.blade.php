@php
    $totalRows = $this->previewResultData['totalRows'] ?? 0;

    // Build relationship fields metadata for preview
    $relationshipFieldsMeta = collect($this->relationshipFields)
        ->map(fn ($field) => ['label' => $field->label, 'icon' => $field->icon])
        ->all();
@endphp

<div class="space-y-6">
    {{-- Nested Livewire component isolates Alpine from parent morphing --}}
    @livewire('import-preview-table', [
        'sessionId' => $sessionId,
        'entityType' => $entityType,
        'columnMap' => $columnMap,
        'fieldLabels' => $this->fieldLabels,
        'previewRows' => $previewRows,
        'totalRows' => $totalRows,
        'relationshipMappings' => $relationshipMappings,
        'relationshipFieldsMeta' => $relationshipFieldsMeta,
    ], key('preview-table-' . $sessionId))

    {{-- Navigation buttons outside nested component --}}
    <div class="flex justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
        <x-filament::button wire:click="previousStep" color="gray">Back</x-filament::button>
        {{ $this->startImportAction }}
    </div>
</div>
