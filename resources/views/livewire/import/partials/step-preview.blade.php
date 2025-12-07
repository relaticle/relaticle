@php
    $totalRows = $this->previewResultData['totalRows'] ?? 0;
    $sampleRows = $previewRows;
    $hasMore = $totalRows > count($sampleRows);
@endphp

<div class="space-y-6">
    {{-- Summary Stats --}}
    <div class="flex items-center gap-6 text-sm">
        <div class="flex items-center gap-1.5">
            <x-filament::icon icon="heroicon-o-document-text" class="h-4 w-4 text-gray-400" />
            <span class="font-medium text-gray-700 dark:text-gray-300">{{ number_format($totalRows) }}</span>
            <span class="text-gray-500 dark:text-gray-400">total rows</span>
        </div>
        <div class="flex items-center gap-1.5">
            <x-filament::icon icon="heroicon-o-plus-circle" class="h-4 w-4 text-success-500" />
            <span class="font-medium text-success-600 dark:text-success-400">{{ number_format($this->getCreateCount()) }}</span>
            <span class="text-gray-500 dark:text-gray-400">new</span>
        </div>
        <div class="flex items-center gap-1.5">
            <x-filament::icon icon="heroicon-o-arrow-path" class="h-4 w-4 text-info-500" />
            <span class="font-medium text-info-600 dark:text-info-400">{{ number_format($this->getUpdateCount()) }}</span>
            <span class="text-gray-500 dark:text-gray-400">updates</span>
        </div>
    </div>

    {{-- Sample Rows Table --}}
    @if (count($sampleRows) > 0)
        <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
            {{-- Table Header --}}
            <div class="px-3 py-2 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Sample Preview</span>
                @if ($hasMore)
                    <span class="text-xs text-gray-400">Showing {{ count($sampleRows) }} of {{ number_format($totalRows) }} rows</span>
                @endif
            </div>
            <div class="overflow-x-auto max-h-80">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800/50 sticky top-0">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">#</th>
                            @foreach (array_keys($columnMap) as $fieldName)
                                @if ($columnMap[$fieldName] !== '')
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                                        {{ str($fieldName)->headline() }}
                                    </th>
                                @endif
                            @endforeach
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($sampleRows as $index => $row)
                            <tr wire:key="preview-row-{{ $index }}">
                                <td class="px-3 py-2 text-gray-500 dark:text-gray-400">
                                    {{ $row['_row_index'] ?? $index + 1 }}
                                </td>
                                @foreach (array_keys($columnMap) as $fieldName)
                                    @if ($columnMap[$fieldName] !== '')
                                        <td class="px-3 py-2 text-gray-950 dark:text-white max-w-xs truncate">
                                            {{ $row[$fieldName] ?? '-' }}
                                        </td>
                                    @endif
                                @endforeach
                                <td class="px-3 py-2">
                                    <x-filament::badge :color="($row['_is_new'] ?? true) ? 'success' : 'info'" size="sm">
                                        {{ ($row['_is_new'] ?? true) ? 'New' : 'Update' }}
                                    </x-filament::badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Navigation --}}
    <div class="flex justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
        <x-filament::button wire:click="previousStep" color="gray">Back</x-filament::button>
        <x-filament::button
            wire:click="executeImport"
            :disabled="!$this->hasRecordsToImport()"
        >
            Start Import
        </x-filament::button>
    </div>
</div>
