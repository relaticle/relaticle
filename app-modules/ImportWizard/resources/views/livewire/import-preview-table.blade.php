<div
    x-data="previewTable({{ Js::from($previewConfig) }})"
    class="space-y-6"
    wire:ignore
>
    {{-- Summary Stats --}}
    <div>
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
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
            <template x-for="(count, relName) in newRelationships" :key="relName">
                <template x-if="count > 0">
                    <div class="flex items-center gap-1.5">
                        <span x-show="isProcessing" x-cloak>
                            <x-filament::loading-indicator class="h-5 w-5 text-warning-500" />
                        </span>
                        <span x-show="!isProcessing" x-cloak>
                            <x-filament::icon icon="heroicon-m-plus-circle" class="h-5 w-5 text-warning-500" />
                        </span>
                        <span class="font-medium text-warning-600 dark:text-warning-400" x-text="count.toLocaleString()"></span>
                        <span class="text-gray-500 dark:text-gray-400">new <span x-text="getRelationshipLabel(relName)"></span></span>
                    </div>
                </template>
            </template>
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
            <div x-ref="scrollContainer" class="overflow-x-auto max-h-96 overflow-y-auto relative">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800/50 sticky top-0 z-10">
                        <tr>
                            <th class="px-3 py-2 w-12 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">#</th>
                            <th class="px-3 py-2 w-10"></th>
                            <template x-for="col in columns" :key="col.key">
                                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400" x-text="col.label"></th>
                            </template>
                            {{-- Individual relationship columns --}}
                            <template x-for="relCol in relationshipColumns" :key="relCol.key">
                                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400" x-text="relCol.label"></th>
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
                                {{-- Individual relationship column values --}}
                                <template x-for="relCol in relationshipColumns" :key="relCol.key">
                                    <td class="px-3 py-2">
                                        <template x-if="row._relationships && row._relationships[relCol.key]">
                                            <span
                                                class="inline-flex items-center gap-1 px-1.5 py-0.5 text-xs rounded-md max-w-[120px]"
                                                :class="{
                                                    'bg-success-100 text-success-700 dark:bg-success-900/50 dark:text-success-300': row._relationships[relCol.key].matchType !== 'new' && row._relationships[relCol.key].matchType !== 'none',
                                                    'bg-warning-100 text-warning-700 dark:bg-warning-900/50 dark:text-warning-300': row._relationships[relCol.key].matchType === 'new',
                                                }"
                                            >
                                                <span class="truncate" x-text="row._relationships[relCol.key].matchedName || row._relationships[relCol.key].inputValue || '-'"></span>
                                                <template x-if="row._relationships[relCol.key].matchType === 'new'">
                                                    <span class="text-[10px] opacity-75">(new)</span>
                                                </template>
                                            </span>
                                        </template>
                                        <template x-if="!row._relationships || !row._relationships[relCol.key]">
                                            <span class="text-gray-400">-</span>
                                        </template>
                                    </td>
                                </template>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <div
                    x-show="loadingMore"
                    x-cloak
                    class="sticky bottom-0 left-0 right-0 px-3 py-3 text-center bg-white/95 dark:bg-gray-900/95 backdrop-blur-sm border-t border-gray-200 dark:border-gray-700"
                >
                    <x-filament::loading-indicator class="h-5 w-5 mx-auto" />
                </div>
            </div>
        </div>
    </template>
</div>
