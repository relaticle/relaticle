<div class="space-y-6">
    {{-- Summary Stats --}}
    @if ($this->previewResult)
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-filament::section compact class="bg-success-50 dark:bg-success-950">
                <div class="text-2xl font-bold text-success-600 dark:text-success-400">{{ number_format($this->previewResult->createCount) }}</div>
                <div class="text-sm text-gray-500 dark:text-gray-400">New Records</div>
            </x-filament::section>
            <x-filament::section compact class="bg-info-50 dark:bg-info-950">
                <div class="text-2xl font-bold text-info-600 dark:text-info-400">{{ number_format($this->previewResult->updateCount) }}</div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Updates</div>
            </x-filament::section>
            <x-filament::section compact>
                <div class="text-2xl font-bold text-gray-600 dark:text-gray-400">{{ number_format($this->previewResult->skipCount) }}</div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Skipped</div>
            </x-filament::section>
            <x-filament::section compact @class([
                'bg-danger-50 dark:bg-danger-950' => $this->previewResult->errorCount > 0,
            ])>
                <div @class([
                    'text-2xl font-bold',
                    'text-danger-600 dark:text-danger-400' => $this->previewResult->errorCount > 0,
                    'text-gray-600 dark:text-gray-400' => $this->previewResult->errorCount === 0,
                ])>{{ number_format($this->previewResult->errorCount) }}</div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Errors</div>
            </x-filament::section>
        </div>

        {{-- Duplicate Handling --}}
        <x-filament::section compact>
            <div class="flex items-center gap-4">
                <label for="duplicate-handling" class="text-sm font-medium text-gray-700 dark:text-gray-300 shrink-0">
                    When duplicates are found
                </label>
                <div class="flex-1 max-w-xs">
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="duplicate-handling" wire:model.live="duplicateHandling">
                            @foreach ($this->getDuplicateHandlingOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
            </div>
        </x-filament::section>

        {{-- Sample Records Tabs --}}
        <x-filament::tabs>
            <x-filament::tabs.item
                :active="true"
                alpine-active="activeTab === 'creates'"
                x-on:click="activeTab = 'creates'"
                x-data="{ activeTab: 'creates' }"
            >
                New Records
                <x-slot name="badge">{{ count($this->previewResult->sampleCreates) }}</x-slot>
            </x-filament::tabs.item>
            <x-filament::tabs.item
                alpine-active="activeTab === 'updates'"
                x-on:click="activeTab = 'updates'"
            >
                Updates
                <x-slot name="badge">{{ count($this->previewResult->sampleUpdates) }}</x-slot>
            </x-filament::tabs.item>
            @if (count($this->previewResult->errors) > 0)
                <x-filament::tabs.item
                    alpine-active="activeTab === 'errors'"
                    x-on:click="activeTab = 'errors'"
                    badge-color="danger"
                >
                    Errors
                    <x-slot name="badge">{{ count($this->previewResult->errors) }}</x-slot>
                </x-filament::tabs.item>
            @endif
        </x-filament::tabs>

        <div x-data="{ activeTab: 'creates' }">
            {{-- Creates Tab --}}
            <div x-show="activeTab === 'creates'" x-cloak>
                @if (count($this->previewResult->sampleCreates) > 0)
                    <x-filament::section compact>
                        <div class="overflow-x-auto -mx-4 sm:-mx-6">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 dark:border-gray-700">
                                        @foreach (array_keys($this->previewResult->sampleCreates[0] ?? []) as $header)
                                            <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                                                {{ $header }}
                                            </th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    @foreach ($this->previewResult->sampleCreates as $record)
                                        <tr>
                                            @foreach ($record as $value)
                                                <td class="px-4 py-2 text-gray-950 dark:text-white truncate max-w-[200px]">
                                                    {{ $value ?? '-' }}
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-filament::section>
                @else
                    <x-filament::section compact>
                        <p class="text-center text-gray-500 dark:text-gray-400 py-4">
                            No new records will be created
                        </p>
                    </x-filament::section>
                @endif
            </div>

            {{-- Updates Tab --}}
            <div x-show="activeTab === 'updates'" x-cloak>
                @if (count($this->previewResult->sampleUpdates) > 0)
                    <x-filament::section compact>
                        <div class="overflow-x-auto -mx-4 sm:-mx-6">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 dark:border-gray-700">
                                        @foreach (array_keys($this->previewResult->sampleUpdates[0] ?? []) as $header)
                                            <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                                                {{ $header }}
                                            </th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    @foreach ($this->previewResult->sampleUpdates as $record)
                                        <tr>
                                            @foreach ($record as $value)
                                                <td class="px-4 py-2 text-gray-950 dark:text-white truncate max-w-[200px]">
                                                    {{ $value ?? '-' }}
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-filament::section>
                @else
                    <x-filament::section compact>
                        <p class="text-center text-gray-500 dark:text-gray-400 py-4">
                            No existing records will be updated
                        </p>
                    </x-filament::section>
                @endif
            </div>

            {{-- Errors Tab --}}
            @if (count($this->previewResult->errors) > 0)
                <div x-show="activeTab === 'errors'" x-cloak>
                    <div class="space-y-2">
                        @foreach ($this->previewResult->errors as $error)
                            <x-filament::section compact class="bg-danger-50 dark:bg-danger-950">
                                <div class="flex items-start gap-2">
                                    <x-filament::icon icon="heroicon-s-x-circle" class="h-4 w-4 text-danger-500 mt-0.5 shrink-0" />
                                    <div>
                                        <p class="text-sm font-medium text-danger-800 dark:text-danger-200">
                                            Row {{ $error['row'] ?? 'Unknown' }}
                                        </p>
                                        <p class="text-sm text-danger-700 dark:text-danger-300">
                                            {{ $error['message'] ?? 'Unknown error' }}
                                        </p>
                                    </div>
                                </div>
                            </x-filament::section>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- Import Summary --}}
        <x-filament::section compact class="bg-primary-50 dark:bg-primary-950">
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-s-information-circle" class="h-5 w-5 text-primary-500 shrink-0" />
                <div class="min-w-0">
                    <p class="text-sm font-medium text-primary-800 dark:text-primary-200">Ready to Import</p>
                    <p class="text-sm text-primary-700 dark:text-primary-300">
                        @php
                            $totalToProcess = $this->previewResult->createCount + $this->previewResult->updateCount;
                        @endphp
                        @if ($totalToProcess > 0)
                            This will process {{ number_format($totalToProcess) }} records
                            ({{ number_format($this->previewResult->createCount) }} new, {{ number_format($this->previewResult->updateCount) }} updates).
                            @if ($this->previewResult->skipCount > 0)
                                {{ number_format($this->previewResult->skipCount) }} will be skipped.
                            @endif
                        @else
                            No records will be imported.
                        @endif
                    </p>
                </div>
            </div>
        </x-filament::section>
    @else
        {{-- Loading State --}}
        <div class="flex items-center justify-center py-12">
            <div class="text-center">
                <x-filament::loading-indicator class="h-8 w-8 mx-auto text-primary-500" />
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">Generating preview...</p>
            </div>
        </div>
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
