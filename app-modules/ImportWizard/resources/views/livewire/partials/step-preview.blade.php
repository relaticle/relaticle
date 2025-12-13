@php
    $totalRows = $this->previewResultData['totalRows'] ?? 0;
    $sampleRows = $previewRows;
    $hasMore = $totalRows > count($sampleRows);
    $showCompanyMatch = in_array($entityType, ['people', 'opportunities']);

    // Calculate update method statistics
    $idBasedUpdates = collect($sampleRows)->where('_update_method', 'id')->count();
    $attributeBasedUpdates = collect($sampleRows)->where('_update_method', 'attribute')->count();

    // Calculate company match statistics
    $companyMatchStats = [];
    if ($showCompanyMatch) {
        $companyMatchStats = [
            'domain' => collect($sampleRows)->where('_company_match_type', 'domain')->count(),
            'name' => collect($sampleRows)->where('_company_match_type', 'name')->count(),
            'ambiguous' => collect($sampleRows)->where('_company_match_type', 'ambiguous')->count(),
            'new' => collect($sampleRows)->where('_company_match_type', 'new')->count(),
        ];
    }
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

    {{-- Update Method Statistics --}}
    @if ($idBasedUpdates > 0 || $attributeBasedUpdates > 0)
        <div class="flex items-center gap-4 text-sm pt-2 border-t border-gray-100 dark:border-gray-800">
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Update Method:</span>
            @if ($idBasedUpdates > 0)
                <div class="flex items-center gap-1.5">
                    <x-filament::badge color="info" size="sm">{{ $idBasedUpdates }}</x-filament::badge>
                    <span class="text-gray-500 dark:text-gray-400">by ID</span>
                </div>
            @endif
            @if ($attributeBasedUpdates > 0)
                <div class="flex items-center gap-1.5">
                    <x-filament::badge color="warning" size="sm">{{ $attributeBasedUpdates }}</x-filament::badge>
                    <span class="text-gray-500 dark:text-gray-400">by name/email</span>
                </div>
            @endif
        </div>
    @endif

    {{-- Company Match Statistics (for People/Opportunities) --}}
    @if ($showCompanyMatch && array_sum($companyMatchStats) > 0)
        <div class="flex items-center gap-4 text-sm pt-2 border-t border-gray-100 dark:border-gray-800">
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Company Matching:</span>
            @if ($companyMatchStats['domain'] > 0)
                <div class="flex items-center gap-1.5">
                    <x-filament::badge color="success" size="sm">{{ $companyMatchStats['domain'] }}</x-filament::badge>
                    <span class="text-gray-500 dark:text-gray-400">domain</span>
                </div>
            @endif
            @if ($companyMatchStats['name'] > 0)
                <div class="flex items-center gap-1.5">
                    <x-filament::badge color="info" size="sm">{{ $companyMatchStats['name'] }}</x-filament::badge>
                    <span class="text-gray-500 dark:text-gray-400">name</span>
                </div>
            @endif
            @if ($companyMatchStats['ambiguous'] > 0)
                <div class="flex items-center gap-1.5">
                    <x-filament::badge color="warning" size="sm">{{ $companyMatchStats['ambiguous'] }}</x-filament::badge>
                    <span class="text-gray-500 dark:text-gray-400">ambiguous</span>
                </div>
            @endif
            @if ($companyMatchStats['new'] > 0)
                <div class="flex items-center gap-1.5">
                    <x-filament::badge color="gray" size="sm">{{ $companyMatchStats['new'] }}</x-filament::badge>
                    <span class="text-gray-500 dark:text-gray-400">new companies</span>
                </div>
            @endif
        </div>
    @endif

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
                            @if ($showCompanyMatch)
                                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Company Match</th>
                            @endif
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
                                @if ($showCompanyMatch)
                                    <td class="px-3 py-2">
                                        @php
                                            $matchType = $row['_company_match_type'] ?? 'new';
                                            $matchCount = $row['_company_match_count'] ?? 0;
                                            $companyName = $row['_company_name'] ?? $row['company_name'] ?? '-';
                                        @endphp
                                        <div class="flex items-center gap-2">
                                            <span class="truncate max-w-[100px] text-gray-950 dark:text-white" title="{{ $companyName }}">
                                                {{ $companyName ?: '-' }}
                                            </span>
                                            @switch($matchType)
                                                @case('domain')
                                                    <x-filament::badge color="success" size="sm" icon="heroicon-m-check">
                                                        Domain
                                                    </x-filament::badge>
                                                    @break
                                                @case('name')
                                                    <x-filament::badge color="info" size="sm">
                                                        Name
                                                    </x-filament::badge>
                                                    @break
                                                @case('ambiguous')
                                                    <x-filament::badge color="warning" size="sm" icon="heroicon-m-exclamation-triangle">
                                                        {{ $matchCount }} matches
                                                    </x-filament::badge>
                                                    @break
                                                @default
                                                    <x-filament::badge color="gray" size="sm">
                                                        New
                                                    </x-filament::badge>
                                            @endswitch
                                        </div>
                                    </td>
                                @endif
                                <td class="px-3 py-2">
                                    @php
                                        $isNew = $row['_is_new'] ?? true;
                                        $updateMethod = $row['_update_method'] ?? null;
                                    @endphp
                                    @if ($isNew)
                                        <x-filament::badge color="success" size="sm" icon="heroicon-m-plus">
                                            New
                                        </x-filament::badge>
                                    @elseif ($updateMethod === 'id')
                                        <x-filament::badge color="info" size="sm" icon="heroicon-m-arrow-path">
                                            Update by ID
                                        </x-filament::badge>
                                    @else
                                        <x-filament::badge color="warning" size="sm" icon="heroicon-m-arrow-path">
                                            Update
                                        </x-filament::badge>
                                    @endif
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
