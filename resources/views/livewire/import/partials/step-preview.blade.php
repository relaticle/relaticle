<div class="space-y-6">
    {{-- Summary Stats --}}
    <div class="flex items-center justify-center gap-6">
        <div class="flex items-center gap-2">
            <x-filament::icon icon="heroicon-o-plus-circle" class="h-5 w-5 text-success-500" />
            <span class="text-lg font-semibold text-success-600 dark:text-success-400">{{ number_format($this->getCreateCount()) }}</span>
            <span class="text-sm text-gray-500 dark:text-gray-400">new</span>
        </div>
        <div class="flex items-center gap-2">
            <x-filament::icon icon="heroicon-o-arrow-path" class="h-5 w-5 text-info-500" />
            <span class="text-lg font-semibold text-info-600 dark:text-info-400">{{ number_format($this->getUpdateCount()) }}</span>
            <span class="text-sm text-gray-500 dark:text-gray-400">updates</span>
        </div>
    </div>

    {{-- All Rows Table --}}
    @if (count($previewRows) > 0)
        <x-filament::section>
            <x-slot name="heading">Records to Import</x-slot>
            <x-slot name="headerEnd">
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    {{ number_format(count($previewRows)) }} rows
                </span>
            </x-slot>
            <div class="overflow-x-auto max-h-96 -mx-4 sm:-mx-6">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50 sticky top-0">
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">#</th>
                            @foreach (array_keys($columnMap) as $fieldName)
                                @if ($columnMap[$fieldName] !== '')
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                                        {{ str($fieldName)->headline() }}
                                    </th>
                                @endif
                            @endforeach
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($previewRows as $index => $row)
                            <tr wire:key="preview-row-{{ $index }}">
                                <td class="px-4 py-2 text-gray-500 dark:text-gray-400">
                                    {{ $row['_row_index'] ?? $index + 1 }}
                                </td>
                                @foreach (array_keys($columnMap) as $fieldName)
                                    @if ($columnMap[$fieldName] !== '')
                                        <td class="px-4 py-2 text-gray-950 dark:text-white max-w-xs truncate">
                                            {{ $row[$fieldName] ?? '-' }}
                                        </td>
                                    @endif
                                @endforeach
                                <td class="px-4 py-2">
                                    <x-filament::badge :color="($row['_is_new'] ?? true) ? 'success' : 'info'" size="sm">
                                        {{ ($row['_is_new'] ?? true) ? 'New' : 'Update' }}
                                    </x-filament::badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    {{-- Ready to Import / Warning --}}
    @if ($this->hasRecordsToImport())
        <x-filament::section compact class="bg-primary-50 dark:bg-primary-950">
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-s-check-circle" class="h-5 w-5 text-primary-500 shrink-0" />
                <div class="min-w-0">
                    <p class="text-sm font-medium text-primary-800 dark:text-primary-200">Ready to Import</p>
                    <p class="text-sm text-primary-700 dark:text-primary-300">
                        Click "Start Import" to begin importing {{ number_format($this->getActiveRowCount()) }} records.
                    </p>
                </div>
            </div>
        </x-filament::section>
    @else
        <x-filament::section compact class="bg-warning-50 dark:bg-warning-950">
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-s-exclamation-triangle" class="h-5 w-5 text-warning-500 shrink-0" />
                <div class="min-w-0">
                    <p class="text-sm font-medium text-warning-800 dark:text-warning-200">No Records to Import</p>
                    <p class="text-sm text-warning-700 dark:text-warning-300">
                        There are no records to import. Go back to review your column mappings and data.
                    </p>
                </div>
            </div>
        </x-filament::section>
    @endif

    {{-- Navigation --}}
    <div class="flex justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
        <x-filament::button
            wire:click="previousStep"
            color="gray"
            icon="heroicon-m-arrow-left"
        >
            Back
        </x-filament::button>
        <x-filament::button
            wire:click="executeImport"
            :disabled="!$this->hasRecordsToImport()"
            icon="heroicon-m-arrow-up-tray"
            icon-position="after"
        >
            Start Import
        </x-filament::button>
    </div>
</div>
