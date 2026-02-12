<div
    class="flex flex-col h-full overflow-hidden"
    @if($this->isImporting) wire:poll.2s="checkImportProgress" @endif
>
    <div class="flex-1 flex flex-col overflow-hidden min-h-[20rem]">
        @if($this->isCompleted)
            <div class="flex items-center gap-3 px-4 py-3 mb-3 shrink-0 bg-gray-50 dark:bg-white/5 border border-gray-200 dark:border-white/10 rounded-xl">
                <x-heroicon-o-check-circle class="h-5 w-5 text-success-600 dark:text-success-400 shrink-0" />
                <p class="text-sm font-medium text-gray-900 dark:text-white">Import Complete</p>

                @if($this->results)
                    <div class="flex items-center gap-4 text-xs ml-auto shrink-0">
                        <span class="flex items-center gap-1.5">
                            <span class="font-semibold text-success-700 dark:text-success-300">{{ number_format($this->results['created'] ?? 0) }}</span>
                            <span class="text-gray-500 dark:text-gray-400">created</span>
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span class="font-semibold text-primary-700 dark:text-primary-300">{{ number_format($this->results['updated'] ?? 0) }}</span>
                            <span class="text-gray-500 dark:text-gray-400">updated</span>
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span class="font-semibold text-gray-600 dark:text-gray-300">{{ number_format($this->results['skipped'] ?? 0) }}</span>
                            <span class="text-gray-500 dark:text-gray-400">skipped</span>
                        </span>
                    </div>
                @endif
            </div>
        @elseif($this->isImporting)
            <div class="flex items-center gap-3 px-4 py-3 mb-3 shrink-0 bg-gray-50 dark:bg-white/5 border border-gray-200 dark:border-white/10 rounded-xl">
                <x-heroicon-o-arrow-path class="h-5 w-5 text-primary-600 dark:text-primary-400 animate-spin shrink-0" />

                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between mb-1.5">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                            Importing...
                            <span class="text-xs font-normal text-gray-500 dark:text-gray-400">
                                {{ number_format($this->processedCount) }} of {{ number_format($this->totalRowCount) }} rows
                            </span>
                        </p>
                        <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ $this->progressPercent }}%</span>
                    </div>
                    <div class="h-1.5 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                        <div
                            class="h-full bg-primary-500 rounded-full transition-all duration-500 ease-out"
                            style="width: {{ $this->progressPercent }}%"
                        ></div>
                    </div>
                </div>
            </div>
        @endif

        <div class="border border-gray-200 dark:border-gray-700 rounded-xl bg-white dark:bg-gray-900 flex flex-col flex-1 overflow-hidden">
            <div class="px-3 py-2 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700 rounded-t-xl shrink-0">
                <div class="flex gap-1 text-xs">
                    <button
                        wire:click="setActiveTab('all')"
                        @class([
                            'flex items-center gap-1.5 px-2.5 py-1.5 rounded transition-colors font-medium',
                            'bg-primary-100 dark:bg-primary-900/50 text-primary-700 dark:text-primary-300' => $activeTab === 'all',
                            'text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800' => $activeTab !== 'all',
                        ])
                    >
                        <x-filament::icon :icon="$entityType->icon()" class="w-3.5 h-3.5"/>
                        {{ $entityType->label() }}
                        <span class="text-gray-400 dark:text-gray-500">{{ number_format($this->totalRowCount) }}</span>
                    </button>

                    @foreach($this->relationshipTabs as $tab)
                        <button
                            wire:key="tab-{{ $tab['key'] }}"
                            wire:click="setActiveTab({{ Js::from($tab['key']) }})"
                            @class([
                                'flex items-center gap-1.5 px-2.5 py-1.5 rounded transition-colors font-medium',
                                'bg-primary-100 dark:bg-primary-900/50 text-primary-700 dark:text-primary-300' => $activeTab === $tab['key'],
                                'text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800' => $activeTab !== $tab['key'],
                            ])
                        >
                            <x-filament::icon :icon="$tab['icon']" class="w-3.5 h-3.5"/>
                            {{ $tab['label'] }}
                            <span class="text-gray-400 dark:text-gray-500">{{ number_format($tab['count']) }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            @if($activeTab === 'all')
                <div class="px-3 py-2 border-b border-gray-200 dark:border-gray-700 shrink-0">
                    <div class="flex items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
                        <span class="flex items-center gap-1">
                            <x-filament::icon icon="heroicon-m-plus" class="w-3.5 h-3.5 text-success-500"/>
                            <span class="font-medium text-gray-700 dark:text-gray-300">{{ number_format($this->createCount) }}</span>
                            will be created
                        </span>
                        <span class="flex items-center gap-1">
                            <x-filament::icon icon="heroicon-m-arrow-path" class="w-3.5 h-3.5 text-primary-500"/>
                            <span class="font-medium text-gray-700 dark:text-gray-300">{{ number_format($this->updateCount) }}</span>
                            will be updated
                        </span>
                    </div>
                </div>

                <div class="flex-1 overflow-auto">
                    <div class="min-w-max">
                        <div class="flex items-stretch text-[10px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 sticky top-0 z-10">
                            <div class="w-8 shrink-0 border-r border-gray-200 dark:border-gray-700 py-1.5"></div>
                            @foreach($this->columns as $column)
                                <div class="w-36 shrink-0 px-2 flex items-center gap-1 border-r border-gray-200 dark:border-gray-700 py-1.5" title="{{ $column->getLabel() }}">
                                    <x-filament::icon :icon="$column->getIcon()" class="w-3 h-3 shrink-0"/>
                                    <span class="truncate">{{ $column->getLabel() }}</span>
                                </div>
                            @endforeach
                        </div>

                        @forelse($this->previewRows as $row)
                            <div
                                wire:key="row-{{ $row->row_number }}"
                                class="flex items-stretch border-b border-gray-100 dark:border-gray-800 text-sm"
                            >
                                <div class="w-8 shrink-0 flex items-center justify-center border-r border-gray-200 dark:border-gray-700 py-2">
                                    @if($row->match_action)
                                        <x-filament::icon
                                            :icon="$row->match_action->icon()"
                                            @class(['w-4 h-4', $row->match_action->color()])
                                        />
                                    @endif
                                </div>
                                @foreach($this->columns as $column)
                                    <div class="w-36 shrink-0 px-2 min-w-0 flex flex-col justify-center border-r border-gray-200 dark:border-gray-700 py-2">
                                        @if($column->isEntityLinkMapping())
                                            @php
                                                $relMatch = $row->relationships?->first(fn ($m) => $m->relationship === $column->entityLink);
                                                $rawValue = $row->raw_data->get($column->source);
                                            @endphp
                                            @if($relMatch && filled($rawValue))
                                                <span class="inline-flex items-center gap-1 text-xs truncate" title="{{ $rawValue }}">
                                                    @if($relMatch->isExisting())
                                                        <x-filament::icon icon="heroicon-m-link" class="w-3.5 h-3.5 text-primary-500 shrink-0"/>
                                                    @else
                                                        <x-filament::icon icon="heroicon-m-plus" class="w-3.5 h-3.5 text-success-500 shrink-0"/>
                                                    @endif
                                                    <span class="text-gray-900 dark:text-white truncate">{{ Str::limit((string) $rawValue, 28) }}</span>
                                                </span>
                                            @endif
                                        @else
                                            @php $value = $row->getFinalValue($column->source) @endphp
                                            @if(filled($value))
                                                <span class="text-gray-900 dark:text-white text-xs truncate" title="{{ $value }}">{{ Str::limit((string) $value, 30) }}</span>
                                            @endif
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @empty
                            <div class="flex items-center justify-center py-8 text-sm text-gray-500 dark:text-gray-400">
                                No rows to display
                            </div>
                        @endforelse
                    </div>
                </div>

                @if($this->previewRows->lastPage() > 1)
                    <div class="px-3 py-2 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 shrink-0 flex items-center justify-between">
                        <button
                            wire:click="previousPage"
                            @disabled($this->previewRows->onFirstPage())
                            class="px-3 py-1 text-xs font-medium rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                        >
                            Previous
                        </button>
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            {{ number_format($this->previewRows->firstItem()) }}–{{ number_format($this->previewRows->lastItem()) }} of {{ number_format($this->previewRows->total()) }}
                        </span>
                        <button
                            wire:click="nextPage"
                            @disabled(!$this->previewRows->hasMorePages())
                            class="px-3 py-1 text-xs font-medium rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                        >
                            Next
                        </button>
                    </div>
                @endif
            @else
                <div class="px-3 py-2 border-b border-gray-200 dark:border-gray-700 shrink-0">
                    <div class="flex items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
                        <span class="flex items-center gap-1">
                            <x-filament::icon icon="heroicon-m-link" class="w-3.5 h-3.5 text-primary-500"/>
                            <span class="font-medium text-gray-700 dark:text-gray-300">{{ number_format($this->relationshipStats['link']) }}</span>
                            will be linked
                        </span>
                        <span class="flex items-center gap-1">
                            <x-filament::icon icon="heroicon-m-plus" class="w-3.5 h-3.5 text-success-500"/>
                            <span class="font-medium text-gray-700 dark:text-gray-300">{{ number_format($this->relationshipStats['create']) }}</span>
                            will be created
                        </span>
                    </div>
                </div>

                <div class="flex items-stretch text-[10px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 bg-gray-50/50 dark:bg-gray-800/30 border-b border-gray-200 dark:border-gray-700">
                    <div class="w-8 shrink-0 border-r border-gray-200 dark:border-gray-700 py-1.5"></div>
                    <div class="w-2/5 px-2 py-1.5 border-r border-gray-200 dark:border-gray-700 flex items-center gap-1">
                        <x-filament::icon icon="heroicon-m-tag" class="w-3 h-3 shrink-0"/>
                        Value
                    </div>
                    <div class="w-2/5 px-2 py-1.5 border-r border-gray-200 dark:border-gray-700 flex items-center gap-1">
                        <x-filament::icon icon="heroicon-m-arrow-path-rounded-square" class="w-3 h-3 shrink-0"/>
                        Resolution
                    </div>
                    <div class="flex-1 px-2 py-1.5 flex items-center justify-end gap-1">
                        <x-filament::icon icon="heroicon-m-queue-list" class="w-3 h-3 shrink-0"/>
                        Rows
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto">
                    @forelse($this->relationshipSummary as $entry)
                        <div class="flex items-stretch border-b border-gray-100 dark:border-gray-800 text-sm">
                            <div class="w-8 shrink-0 flex items-center justify-center border-r border-gray-200 dark:border-gray-700 py-2">
                                @if($entry['action'] === 'link')
                                    <x-filament::icon icon="heroicon-m-link" class="w-4 h-4 text-primary-500"/>
                                @else
                                    <x-filament::icon icon="heroicon-m-plus" class="w-4 h-4 text-success-500"/>
                                @endif
                            </div>
                            <div class="w-2/5 min-w-0 px-2 py-2 flex items-center border-r border-gray-200 dark:border-gray-700">
                                <span class="text-gray-900 dark:text-white truncate text-xs">{{ Str::limit($entry['name'], 30) }}</span>
                            </div>
                            <div class="w-2/5 px-2 py-2 flex items-center border-r border-gray-200 dark:border-gray-700">
                                @if($entry['action'] === 'link')
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[11px] font-medium bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300">
                                        <x-filament::icon icon="heroicon-m-link" class="w-3 h-3"/>
                                        Link existing
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[11px] font-medium bg-success-50 dark:bg-success-900/30 text-success-700 dark:text-success-300">
                                        <x-filament::icon icon="heroicon-m-plus" class="w-3 h-3"/>
                                        Create new
                                    </span>
                                @endif
                            </div>
                            <div class="flex-1 px-2 py-2 flex items-center justify-end">
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $entry['count'] }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="flex items-center justify-center py-8 text-sm text-gray-500 dark:text-gray-400">
                            No relationships to display
                        </div>
                    @endforelse
                </div>

                @if($this->relationshipSummary->lastPage() > 1)
                    <div class="px-3 py-2 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 shrink-0 flex items-center justify-between">
                        <button
                            wire:click="previousPage"
                            @disabled($this->relationshipSummary->onFirstPage())
                            class="px-3 py-1 text-xs font-medium rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                        >
                            Previous
                        </button>
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            {{ number_format($this->relationshipSummary->firstItem()) }}–{{ number_format($this->relationshipSummary->lastItem()) }} of {{ number_format($this->relationshipSummary->total()) }}
                        </span>
                        <button
                            wire:click="nextPage"
                            @disabled(!$this->relationshipSummary->hasMorePages())
                            class="px-3 py-1 text-xs font-medium rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                        >
                            Next
                        </button>
                    </div>
                @endif
            @endif
        </div>
    </div>

    <x-filament-actions::modals />

    @if(! $this->isImporting && ! $this->isCompleted)
        <div class="flex justify-end gap-3 pt-4 mt-6 border-t border-gray-200 dark:border-gray-700 pb-1">
            <x-filament::button
                color="gray"
                wire:click="$parent.goBack()"
            >
                Back
            </x-filament::button>
            {{ $this->startImportAction }}
        </div>
    @endif
</div>
