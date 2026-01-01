@php
    $totalRows = $this->previewResultData['totalRows'] ?? 0;
@endphp

<div class="space-y-6">
    {{-- Nested Livewire component isolates Alpine from parent morphing --}}
    @livewire('import-preview-table', [
        'sessionId' => $sessionId,
        'entityType' => $entityType,
        'columnMap' => $columnMap,
        'previewRows' => $previewRows,
        'totalRows' => $totalRows,
    ], key('preview-table-' . $sessionId))
</div>

{{-- Navigation buttons outside nested component --}}
<div class="flex justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
    <x-filament::button wire:click="previousStep" color="gray">Back</x-filament::button>
    {{ $this->startImportAction }}
</div>
