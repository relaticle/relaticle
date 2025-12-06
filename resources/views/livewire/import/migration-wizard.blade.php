<div>
    {{-- Step Progress --}}
    <div class="mb-8">
        <div class="flex items-center justify-between">
            @foreach ([1 => 'Select', 2 => 'Import', 3 => 'Complete'] as $step => $label)
                <div class="flex items-center {{ $step < 3 ? 'flex-1' : '' }}">
                    <div class="flex items-center gap-2">
                        <span @class([
                            'flex h-8 w-8 items-center justify-center rounded-full text-xs font-medium transition-colors',
                            'bg-primary-600 text-white' => $currentStep === $step,
                            'bg-primary-100 text-primary-600 dark:bg-primary-500/20 dark:text-primary-400' => $currentStep > $step,
                            'bg-gray-100 text-gray-400 dark:bg-gray-800 dark:text-gray-500' => $currentStep < $step,
                        ])>
                            @if ($currentStep > $step)
                                <x-filament::icon icon="heroicon-s-check" class="h-4 w-4" />
                            @else
                                {{ $step }}
                            @endif
                        </span>
                        <span @class([
                            'text-sm font-medium hidden sm:block',
                            'text-gray-900 dark:text-white' => $currentStep >= $step,
                            'text-gray-400 dark:text-gray-500' => $currentStep < $step,
                        ])>
                            {{ $label }}
                        </span>
                    </div>
                    @if ($step < 3)
                        <div @class([
                            'mx-4 h-px flex-1',
                            'bg-primary-200 dark:bg-primary-500/30' => $currentStep > $step,
                            'bg-gray-200 dark:bg-gray-700' => $currentStep <= $step,
                        ])></div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- Step 1: Entity Selection --}}
    @if ($currentStep === 1)
        <div class="space-y-6">
            <div class="text-center">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Select What to Import</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Dependencies are enforced automatically
                </p>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->getEntities() as $key => $entity)
                    @php
                        $isSelected = $selectedEntities[$key] ?? false;
                        $canSelect = $this->canSelectEntity($key);
                        $missingDeps = $this->getMissingDependencies($key);
                    @endphp

                    <button
                        type="button"
                        wire:click="toggleEntity('{{ $key }}')"
                        @class([
                            'group relative flex items-start gap-3 rounded-xl border-2 p-4 text-left transition-all',
                            'border-primary-500 bg-primary-50/50 dark:border-primary-500 dark:bg-primary-500/5' => $isSelected,
                            'border-gray-200 bg-white hover:border-gray-300 dark:border-gray-700 dark:bg-gray-800/50 dark:hover:border-gray-600' => !$isSelected && $canSelect,
                            'border-gray-100 bg-gray-50 opacity-50 cursor-not-allowed dark:border-gray-800 dark:bg-gray-900/50' => !$canSelect && !$isSelected,
                        ])
                        @if (!$canSelect && !$isSelected) disabled @endif
                    >
                        <div @class([
                            'flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors pointer-events-none',
                            'bg-primary-100 text-primary-600 dark:bg-primary-500/20 dark:text-primary-400' => $isSelected,
                            'bg-gray-100 text-gray-500 group-hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-400 dark:group-hover:bg-gray-600' => !$isSelected && $canSelect,
                            'bg-gray-100 text-gray-400 dark:bg-gray-800 dark:text-gray-500' => !$canSelect && !$isSelected,
                        ])>
                            <x-filament::icon :icon="$entity['icon']" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between">
                                <h4 @class([
                                    'font-medium',
                                    'text-primary-700 dark:text-primary-300' => $isSelected,
                                    'text-gray-900 dark:text-white' => !$isSelected,
                                ])>{{ $entity['label'] }}</h4>
                                @if ($isSelected)
                                    <x-filament::icon icon="heroicon-s-check-circle" class="h-5 w-5 text-primary-500" />
                                @endif
                            </div>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400 line-clamp-2">{{ $entity['description'] }}</p>
                            @if (!$canSelect && count($missingDeps) > 0)
                                <p class="mt-1.5 text-xs text-amber-600 dark:text-amber-400">
                                    Requires {{ collect($missingDeps)->map(fn($d) => $this->getEntities()[$d]['label'])->join(', ') }}
                                </p>
                            @endif
                        </div>
                    </button>
                @endforeach
            </div>

            @if ($this->hasSelectedEntities())
                <div class="rounded-xl bg-gray-50 p-4 dark:bg-gray-800/50">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-3">Import Order</p>
                    <div class="flex flex-wrap items-center gap-2">
                        @foreach ($this->getImportOrder() as $index => $entityKey)
                            <span class="inline-flex items-center gap-1.5 rounded-lg bg-white px-2.5 py-1.5 text-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                                <span class="flex h-5 w-5 items-center justify-center rounded-md bg-primary-100 text-xs font-semibold text-primary-600 dark:bg-primary-500/20 dark:text-primary-400">
                                    {{ $index + 1 }}
                                </span>
                                <span class="text-gray-700 dark:text-gray-300">{{ $this->getEntities()[$entityKey]['label'] }}</span>
                            </span>
                            @if (!$loop->last)
                                <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4 text-gray-300 dark:text-gray-600" />
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="flex justify-end pt-4 border-t border-gray-200 dark:border-gray-700">
                <x-filament::button
                    wire:click="nextStep"
                    :disabled="!$this->hasSelectedEntities()"
                    icon="heroicon-m-arrow-right"
                    icon-position="after"
                >
                    Continue
                </x-filament::button>
            </div>
        </div>
    @endif

    {{-- Step 2: Import Data --}}
    @if ($currentStep === 2 && $currentEntity)
        @php
            $entity = $this->getEntities()[$currentEntity];
            $order = $this->getImportOrder();
            $currentIndex = array_search($currentEntity, $order);
        @endphp

        <div class="space-y-6">
            {{-- Current Entity Header --}}
            <div class="text-center">
                <div class="inline-flex items-center justify-center h-12 w-12 rounded-xl bg-primary-100 dark:bg-primary-500/20 mb-3">
                    <x-filament::icon :icon="$entity['icon']" class="h-6 w-6 text-primary-600 dark:text-primary-400" />
                </div>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                    Import {{ $entity['label'] }}
                </h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Step {{ $currentIndex + 1 }} of {{ count($order) }}
                </p>
            </div>

            {{-- Entity Progress Bar --}}
            <div class="flex justify-center gap-1.5">
                @foreach ($order as $index => $entityKey)
                    @php
                        $result = $importResults[$entityKey] ?? null;
                        $isCurrent = $entityKey === $currentEntity;
                        $isProcessing = $result && ($result['processing'] ?? false);
                        $isJobFailed = $result && ($result['job_failed'] ?? false);
                        $isCompleted = $result && !($result['skipped'] ?? false) && !$isProcessing && !$isJobFailed;
                        $isSkipped = $result && ($result['skipped'] ?? false);
                    @endphp
                    <div class="flex flex-col items-center gap-1.5" title="{{ $this->getEntities()[$entityKey]['label'] }}">
                        <div @class([
                            'h-1.5 w-10 rounded-full transition-colors',
                            'bg-primary-500' => $isCurrent,
                            'bg-green-500' => $isCompleted,
                            'bg-red-500' => $isJobFailed,
                            'bg-amber-500 animate-pulse' => $isProcessing,
                            'bg-gray-300 dark:bg-gray-600' => $isSkipped,
                            'bg-gray-200 dark:bg-gray-700' => !$result && !$isCurrent,
                        ])></div>
                    </div>
                @endforeach
            </div>

            {{-- Import Actions --}}
            <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800/50">
                <p class="text-sm text-gray-600 dark:text-gray-400 text-center mb-5">
                    Upload your {{ strtolower($entity['label']) }} file. You can map columns and preview data before importing.
                </p>

                <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
                    <x-filament::button
                        wire:click="mountAction('{{ $this->getCurrentImportActionName() }}')"
                        icon="heroicon-o-arrow-up-tray"
                    >
                        Upload File
                    </x-filament::button>

                    <x-filament::button
                        color="gray"
                        wire:click="skipCurrentEntity"
                    >
                        Skip
                    </x-filament::button>
                </div>
            </div>

            {{-- Progress So Far --}}
            @if (count($importResults) > 0)
                <div
                    x-data="{ open: false }"
                    class="rounded-xl border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800/30"
                >
                    <button
                        type="button"
                        class="flex w-full items-center justify-between px-4 py-3 text-sm font-medium text-gray-700 dark:text-gray-300"
                        @click="open = !open"
                    >
                        <span>Progress</span>
                        <x-filament::icon
                            icon="heroicon-m-chevron-down"
                            class="h-4 w-4 transition-transform duration-200"
                            ::class="{ 'rotate-180': open }"
                        />
                    </button>
                    <div
                        x-show="open"
                        x-collapse
                        class="border-t border-gray-200 dark:border-gray-700 px-4 py-3 space-y-2"
                    >
                        @foreach ($importResults as $entityKey => $result)
                            <div class="flex items-center justify-between py-1.5">
                                <span class="text-sm text-gray-700 dark:text-gray-300">
                                    {{ $this->getEntities()[$entityKey]['label'] }}
                                </span>
                                @if ($result['skipped'] ?? false)
                                    <span class="text-xs text-gray-400 dark:text-gray-500">Skipped</span>
                                @elseif ($result['job_failed'] ?? false)
                                    <span class="inline-flex items-center gap-1 text-xs text-red-600 dark:text-red-400">
                                        <x-filament::icon icon="heroicon-s-x-circle" class="h-3.5 w-3.5" />
                                        Failed
                                    </span>
                                @elseif ($result['processing'] ?? false)
                                    <span class="inline-flex items-center gap-1 text-xs text-amber-600 dark:text-amber-400">
                                        <x-filament::loading-indicator class="h-3.5 w-3.5" />
                                        Processing
                                    </span>
                                @else
                                    <span class="text-xs text-green-600 dark:text-green-400">
                                        {{ $result['imported'] }} imported
                                        @if ($result['failed'] > 0)
                                            <span class="text-red-500">({{ $result['failed'] }} failed)</span>
                                        @endif
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Cancel --}}
            <div class="flex justify-start pt-4 border-t border-gray-200 dark:border-gray-700">
                <x-filament::button
                    color="danger"
                    outlined
                    size="sm"
                    wire:click="cancelMigration"
                    wire:confirm="Are you sure? Completed imports will remain."
                >
                    Cancel Migration
                </x-filament::button>
            </div>
        </div>
    @endif

    {{-- Step 3: Complete --}}
    @if ($currentStep === 3)
        @php
            $queuedCount = collect($importResults)->filter(fn ($r) => ($r['processing'] ?? false))->count();
            $skippedCount = collect($importResults)->filter(fn ($r) => ($r['skipped'] ?? false))->count();
        @endphp

        <div class="text-center space-y-6">
            <div class="inline-flex items-center justify-center h-14 w-14 rounded-2xl bg-green-100 dark:bg-green-500/20">
                <x-filament::icon icon="heroicon-o-check-circle" class="h-8 w-8 text-green-600 dark:text-green-400" />
            </div>

            <div>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Imports Queued</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    @if ($queuedCount > 0)
                        {{ $queuedCount }} {{ Str::plural('import', $queuedCount) }} will be processed in order.
                    @endif
                    @if ($skippedCount > 0)
                        {{ $skippedCount }} skipped.
                    @endif
                </p>
            </div>

            {{-- Results Summary --}}
            <div class="max-w-sm mx-auto">
                <div class="rounded-xl border border-gray-200 divide-y divide-gray-200 dark:border-gray-700 dark:divide-gray-700 overflow-hidden">
                    @foreach ($importResults as $entityKey => $result)
                        <div class="flex items-center justify-between px-4 py-3 bg-white dark:bg-gray-800/50">
                            <div class="flex items-center gap-2.5">
                                <x-filament::icon :icon="$this->getEntities()[$entityKey]['icon']" class="h-4 w-4 text-gray-400" />
                                <span class="text-sm text-gray-700 dark:text-gray-300">
                                    {{ $this->getEntities()[$entityKey]['label'] }}
                                </span>
                            </div>
                            @if ($result['skipped'] ?? false)
                                <span class="text-xs text-gray-400 dark:text-gray-500">Skipped</span>
                            @else
                                <span class="inline-flex items-center gap-1 text-xs text-green-600 dark:text-green-400">
                                    <x-filament::icon icon="heroicon-s-check" class="h-3.5 w-3.5" />
                                    Queued
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            <p class="text-sm text-gray-500 dark:text-gray-400">
                Track progress in <span class="font-medium text-gray-700 dark:text-gray-300">Import History</span>
            </p>

            <div class="flex justify-center gap-3 pt-4">
                <x-filament::button
                    wire:click="$parent.setActiveTab('history')"
                >
                    View History
                </x-filament::button>
                <x-filament::button
                    wire:click="resetWizard"
                    color="gray"
                >
                    New Migration
                </x-filament::button>
            </div>
        </div>
    @endif

    <x-filament-actions::modals />
</div>
