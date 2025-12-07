<div class="space-y-6">
    {{-- Summary Stats --}}
    <div class="grid gap-4 sm:grid-cols-3">
        <x-filament::section compact>
            <div class="text-2xl font-bold text-gray-950 dark:text-white">{{ $this->columnAnalyses->count() }}</div>
            <div class="text-sm text-gray-500 dark:text-gray-400">Columns Mapped</div>
        </x-filament::section>
        <x-filament::section compact @class([
            'bg-danger-50 dark:bg-danger-950' => $this->getTotalIssueCount() > 0,
            'bg-success-50 dark:bg-success-950' => $this->getTotalIssueCount() === 0,
        ])>
            <div @class([
                'text-2xl font-bold',
                'text-danger-600 dark:text-danger-400' => $this->getTotalIssueCount() > 0,
                'text-success-600 dark:text-success-400' => $this->getTotalIssueCount() === 0,
            ])>{{ $this->getTotalIssueCount() }}</div>
            <div class="text-sm text-gray-500 dark:text-gray-400">Issues Found</div>
        </x-filament::section>
        <x-filament::section compact class="bg-info-50 dark:bg-info-950">
            <div class="text-2xl font-bold text-info-600 dark:text-info-400">{{ $this->getTotalCorrectionsCount() }}</div>
            <div class="text-sm text-gray-500 dark:text-gray-400">Corrections Applied</div>
        </x-filament::section>
    </div>

    {{-- Column Analysis Cards --}}
    <div class="space-y-3">
        @foreach ($this->columnAnalyses as $analysis)
            <x-filament::section :compact="$expandedColumn !== $analysis->mappedToField" collapsible :collapsed="$expandedColumn !== $analysis->mappedToField">
                <x-slot name="heading">
                    <button
                        wire:click="toggleColumn('{{ $analysis->mappedToField }}')"
                        class="flex items-center gap-2 text-left"
                    >
                        <span class="font-medium text-gray-950 dark:text-white">{{ $analysis->csvColumnName }}</span>
                        <x-filament::icon icon="heroicon-m-arrow-right" class="h-4 w-4 text-gray-400" />
                        <span class="text-gray-500 dark:text-gray-400">{{ $analysis->mappedToField }}</span>
                    </button>
                </x-slot>
                <x-slot name="headerEnd">
                    <div class="flex items-center gap-2">
                        <x-filament::badge color="gray" size="sm">
                            {{ $analysis->uniqueCount }} values
                        </x-filament::badge>
                        @if ($analysis->isRequired)
                            <x-filament::badge color="gray" size="sm">
                                Required
                            </x-filament::badge>
                        @endif
                        @if ($analysis->hasIssues())
                            <x-filament::badge color="danger" size="sm">
                                {{ $analysis->issueCount() }} issues
                            </x-filament::badge>
                        @endif
                    </div>
                </x-slot>

                {{-- Expanded Content --}}
                @if ($expandedColumn === $analysis->mappedToField)
                    {{-- Search --}}
                    <div class="mb-4">
                        <x-filament::input.wrapper>
                            <x-filament::input
                                type="text"
                                wire:model.live.debounce.300ms="reviewSearch"
                                placeholder="Search values..."
                            />
                        </x-filament::input.wrapper>
                    </div>

                    {{-- Values Table --}}
                    <div class="overflow-x-auto max-h-80 -mx-4 sm:-mx-6">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-900/50 sticky top-0">
                                <tr class="border-b border-gray-200 dark:border-gray-700">
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Value</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Count</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Status</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @php
                                    $paginatedValues = $analysis->paginatedValues($reviewPage, 50, $reviewSearch);
                                    $issueValues = $analysis->issueValues();
                                @endphp

                                @forelse ($paginatedValues as $value => $count)
                                    @php
                                        $hasIssue = in_array($value, $issueValues, true);
                                        $hasCorrection = $this->hasCorrectionForValue($analysis->mappedToField, $value);
                                        $correctedValue = $this->getCorrectedValue($analysis->mappedToField, $value);
                                    @endphp
                                    <tr @class([
                                        'bg-danger-50 dark:bg-danger-950/50' => $hasIssue && !$hasCorrection,
                                        'bg-success-50 dark:bg-success-950/50' => $hasCorrection,
                                    ])>
                                        <td class="px-4 py-2 text-gray-950 dark:text-white">
                                            @if ($hasCorrection)
                                                <span class="line-through text-gray-400">{{ $value ?: '(blank)' }}</span>
                                                <span class="ml-2 text-success-600 dark:text-success-400">&rarr; {{ $correctedValue }}</span>
                                            @else
                                                {{ $value ?: '(blank)' }}
                                            @endif
                                        </td>
                                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">
                                            {{ number_format($count) }}
                                        </td>
                                        <td class="px-4 py-2">
                                            @if ($hasCorrection)
                                                <x-filament::icon icon="heroicon-s-check-circle" class="h-4 w-4 text-success-500" />
                                            @elseif ($hasIssue)
                                                <x-filament::icon icon="heroicon-s-x-circle" class="h-4 w-4 text-danger-500" />
                                            @else
                                                <x-filament::icon icon="heroicon-s-check-circle" class="h-4 w-4 text-gray-300 dark:text-gray-600" />
                                            @endif
                                        </td>
                                        <td class="px-4 py-2">
                                            @if ($hasIssue || $hasCorrection)
                                                <x-filament::link
                                                    wire:click="openCorrectionModal('{{ $analysis->mappedToField }}', '{{ addslashes($value) }}')"
                                                    tag="button"
                                                    size="sm"
                                                >
                                                    {{ $hasCorrection ? 'Edit' : 'Fix' }}
                                                </x-filament::link>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-4 py-4 text-center text-gray-500 dark:text-gray-400">
                                            No values found
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{-- Pagination --}}
                    @php
                        $totalPages = $analysis->totalPages(50, $reviewSearch);
                    @endphp
                    @if ($totalPages > 1)
                        <div class="mt-4 flex items-center justify-between">
                            <span class="text-sm text-gray-500 dark:text-gray-400">
                                Page {{ $reviewPage }} of {{ $totalPages }}
                            </span>
                            <div class="flex gap-2">
                                <x-filament::button
                                    wire:click="$set('reviewPage', {{ max(1, $reviewPage - 1) }})"
                                    :disabled="$reviewPage === 1"
                                    size="sm"
                                    color="gray"
                                >
                                    Previous
                                </x-filament::button>
                                <x-filament::button
                                    wire:click="$set('reviewPage', {{ min($totalPages, $reviewPage + 1) }})"
                                    :disabled="$reviewPage === $totalPages"
                                    size="sm"
                                    color="gray"
                                >
                                    Next
                                </x-filament::button>
                            </div>
                        </div>
                    @endif
                @endif
            </x-filament::section>
        @endforeach
    </div>

    {{-- Issues Warning --}}
    @if ($this->hasValidationIssues())
        <x-filament::section compact class="bg-warning-50 dark:bg-warning-950">
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-s-exclamation-triangle" class="h-5 w-5 text-warning-500 shrink-0" />
                <div class="min-w-0">
                    <p class="text-sm font-medium text-warning-800 dark:text-warning-200">Issues Detected</p>
                    <p class="text-sm text-warning-700 dark:text-warning-300">
                        Some values may cause import problems. You can fix them now or proceed anyway.
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
            wire:click="nextStep"
            icon="heroicon-m-arrow-right"
            icon-position="after"
        >
            Continue
        </x-filament::button>
    </div>
</div>
