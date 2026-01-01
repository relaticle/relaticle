<div
    x-data="previewTable({{ Js::from($previewConfig) }})"
    class="space-y-6"
    wire:ignore
>
    {{-- Summary Stats --}}
    <div>
        <div class="flex items-center gap-6 text-sm">
            <div class="flex items-center gap-1.5">
                <span x-show="isProcessing" x-cloak>
                    <x-filament::loading-indicator class="h-5 w-5 text-success-500" />
                </span>
                <span x-show="!isProcessing" x-cloak>
                    <x-filament::icon icon="heroicon-m-plus-circle" class="h-5 w-5 text-success-500" />
                </span>
                <span class="font-medium text-success-600 dark:text-success-400" x-text="creates.toLocaleString()"></span>
                <span class="text-gray-500 dark:text-gray-400">will be created</span>
            </div>
            <div class="flex items-center gap-1.5">
                <span x-show="isProcessing" x-cloak>
                    <x-filament::loading-indicator class="h-5 w-5 text-info-500" />
                </span>
                <span x-show="!isProcessing" x-cloak>
                    <x-filament::icon icon="heroicon-m-arrow-path" class="h-5 w-5 text-info-500" />
                </span>
                <span class="font-medium text-info-600 dark:text-info-400" x-text="updates.toLocaleString()"></span>
                <span class="text-gray-500 dark:text-gray-400">will be updated</span>
            </div>
            <div x-show="isProcessing" x-cloak class="flex items-center gap-1.5 ml-auto text-gray-500 dark:text-gray-400">
                <span class="text-xs" x-text="`${processed.toLocaleString()}/${totalRows.toLocaleString()} rows`"></span>
            </div>
            <div x-show="isReady" x-cloak class="flex items-center gap-1.5 ml-auto">
                <x-filament::icon icon="heroicon-m-check-circle" class="h-5 w-5 text-success-500" />
                <span class="text-sm text-success-600 dark:text-success-400">Ready to import</span>
            </div>
        </div>
    </div>

    {{-- Sample Rows Table --}}
    <template x-if="rows.length > 0">
        <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
            <div class="px-3 py-2 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Preview</span>
                <span class="text-xs text-gray-400">
                    Showing <span x-text="currentRowCount.toLocaleString()"></span> of <span x-text="totalRows.toLocaleString()"></span> rows
                </span>
            </div>
            <div x-ref="scrollContainer" class="overflow-x-auto max-h-96 overflow-y-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800/50 sticky top-0 z-10">
                        <tr>
                            <th class="px-3 py-2 w-12 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">#</th>
                            <th class="px-3 py-2 w-10"></th>
                            <template x-for="col in columns" :key="col.key">
                                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400" x-text="col.label"></th>
                            </template>
                            <template x-if="showCompanyMatch">
                                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Company Match</th>
                            </template>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <template x-for="(row, index) in rows" :key="row._row_index || index">
                            <tr>
                                <td class="px-3 py-2 text-xs text-gray-400" x-text="row._row_index || (index + 1)"></td>
                                <td class="px-3 py-2" x-html="getActionIcon(row._action || 'create')"></td>
                                <template x-for="col in columns" :key="col.key">
                                    <td class="px-3 py-2 text-gray-950 dark:text-white max-w-xs truncate" x-text="row[col.key] || '-'"></td>
                                </template>
                                <template x-if="showCompanyMatch">
                                    <td class="px-3 py-2">
                                        <div class="flex items-center gap-2">
                                            <span class="truncate max-w-[100px] text-gray-950 dark:text-white" x-text="row._company_name || row.company_name || '-'"></span>
                                            <span x-html="getMatchBadge(row._company_match_type || 'none')"></span>
                                        </div>
                                    </td>
                                </template>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div x-show="loadingMore" x-cloak class="px-3 py-4 text-center text-gray-500 border-t border-gray-200 dark:border-gray-700">
                <x-filament::loading-indicator class="h-5 w-5 mx-auto" />
            </div>
        </div>
    </template>
</div>
