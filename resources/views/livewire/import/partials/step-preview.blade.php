<div class="space-y-6">
    {{-- Summary Stats - Left aligned, smaller --}}
    <div class="flex items-center gap-4 text-sm">
        <div class="flex items-center gap-1.5">
            <x-filament::icon icon="heroicon-o-plus-circle" class="h-4 w-4 text-success-500" />
            <span class="font-medium text-success-600 dark:text-success-400">{{ number_format($this->getCreateCount()) }}</span>
            <span class="text-gray-500 dark:text-gray-400">will be created</span>
        </div>
        <div class="flex items-center gap-1.5">
            <x-filament::icon icon="heroicon-o-arrow-path" class="h-4 w-4 text-info-500" />
            <span class="font-medium text-info-600 dark:text-info-400">{{ number_format($this->getUpdateCount()) }}</span>
            <span class="text-gray-500 dark:text-gray-400">will be updated</span>
        </div>
    </div>

    {{-- All Rows Table --}}
    @if (count($previewRows) > 0)
        <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
            <div class="overflow-x-auto max-h-96">
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
                        @foreach ($previewRows as $index => $row)
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
