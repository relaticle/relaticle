<div>
    {{-- Step Progress --}}
    <div class="mb-8">
        <nav aria-label="Progress">
            <ol role="list" class="flex items-center justify-center gap-2">
                @foreach ([1 => 'Select Entities', 2 => 'Import Data', 3 => 'Complete'] as $step => $label)
                    <li class="flex items-center">
                        <span @class([
                            'flex h-8 w-8 items-center justify-center rounded-full text-xs font-medium',
                            'bg-primary-600 text-white' => $currentStep === $step,
                            'bg-primary-100 text-primary-600 dark:bg-primary-500/20 dark:text-primary-400' => $currentStep > $step,
                            'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' => $currentStep < $step,
                        ])>
                            @if ($currentStep > $step)
                                <x-filament::icon icon="heroicon-s-check" class="h-4 w-4" />
                            @else
                                {{ $step }}
                            @endif
                        </span>
                        <span class="ml-2 text-sm font-medium {{ $currentStep >= $step ? 'text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400' }}">
                            {{ $label }}
                        </span>
                        @if ($step < 3)
                            <x-filament::icon icon="heroicon-m-chevron-right" class="mx-4 h-5 w-5 text-gray-300 dark:text-gray-600" />
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>
    </div>

    {{-- Step 1: Entity Selection --}}
    @if ($currentStep === 1)
        <div class="space-y-6">
            <div class="text-center mb-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Select What to Import</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Choose the entity types you want to import. Dependencies will be enforced automatically.
                </p>
            </div>

            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
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
                            'relative rounded-xl border-2 p-4 text-left transition-all duration-200',
                            'border-primary-500 bg-primary-50 dark:bg-primary-500/10' => $isSelected,
                            'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600' => !$isSelected && $canSelect,
                            'border-gray-100 dark:border-gray-800 opacity-50 cursor-not-allowed' => !$canSelect && !$isSelected,
                        ])
                        @if (!$canSelect && !$isSelected) disabled @endif
                    >
                        <div class="flex items-start gap-3">
                            <div @class([
                                'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg',
                                'bg-primary-100 text-primary-600 dark:bg-primary-500/20 dark:text-primary-400' => $isSelected,
                                'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' => !$isSelected,
                            ])>
                                <x-filament::icon :icon="$entity['icon']" class="h-5 w-5" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between">
                                    <h4 class="font-semibold text-gray-900 dark:text-white">{{ $entity['label'] }}</h4>
                                    @if ($isSelected)
                                        <x-filament::icon icon="heroicon-s-check-circle" class="h-5 w-5 text-primary-500" />
                                    @endif
                                </div>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $entity['description'] }}</p>
                                @if (!$canSelect && count($missingDeps) > 0)
                                    <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">
                                        Requires: {{ collect($missingDeps)->map(fn($d) => $this->getEntities()[$d]['label'])->join(', ') }}
                                    </p>
                                @endif
                            </div>
                        </div>
                    </button>
                @endforeach
            </div>

            @if ($this->hasSelectedEntities())
                <div class="mt-6 rounded-lg bg-gray-50 dark:bg-gray-800/50 p-4">
                    <h4 class="text-sm font-medium text-gray-900 dark:text-white mb-2">Import Order</h4>
                    <div class="flex flex-wrap items-center gap-2">
                        @foreach ($this->getImportOrder() as $index => $entityKey)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-white dark:bg-gray-700 px-3 py-1 text-sm border border-gray-200 dark:border-gray-600">
                                <span class="flex h-5 w-5 items-center justify-center rounded-full bg-primary-100 text-primary-600 text-xs font-bold dark:bg-primary-500/20 dark:text-primary-400">
                                    {{ $index + 1 }}
                                </span>
                                {{ $this->getEntities()[$entityKey]['label'] }}
                            </span>
                            @if (!$loop->last)
                                <x-filament::icon icon="heroicon-m-arrow-right" class="h-4 w-4 text-gray-400" />
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="flex justify-end pt-4 border-t dark:border-gray-700">
                <x-filament::button
                    wire:click="nextStep"
                    :disabled="!$this->hasSelectedEntities()"
                    icon="heroicon-m-arrow-right"
                    icon-position="after"
                >
                    Continue to Import
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
            <div class="text-center mb-6">
                <div class="inline-flex items-center justify-center h-12 w-12 rounded-full bg-primary-100 dark:bg-primary-500/20 mb-3">
                    <x-filament::icon :icon="$entity['icon']" class="h-6 w-6 text-primary-600 dark:text-primary-400" />
                </div>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                    Import {{ $entity['label'] }}
                </h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Step {{ $currentIndex + 1 }} of {{ count($order) }}
                </p>
            </div>

            {{-- Progress for entities --}}
            <div class="flex justify-center gap-2 mb-6">
                @foreach ($order as $index => $entityKey)
                    @php
                        $result = $importResults[$entityKey] ?? null;
                        $isCurrent = $entityKey === $currentEntity;
                    @endphp
                    <div @class([
                        'flex flex-col items-center gap-1',
                    ])>
                        <div @class([
                            'h-2 w-12 rounded-full',
                            'bg-primary-500' => $isCurrent,
                            'bg-green-500' => $result && !($result['skipped'] ?? false),
                            'bg-gray-300 dark:bg-gray-600' => $result && ($result['skipped'] ?? false),
                            'bg-gray-200 dark:bg-gray-700' => !$result && !$isCurrent,
                        ])></div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $this->getEntities()[$entityKey]['label'] }}
                        </span>
                    </div>
                @endforeach
            </div>

            <x-filament::section>
                <div class="text-center py-6">
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                        Click the button below to upload your {{ strtolower($entity['label']) }} file.
                        You'll be able to map columns and preview data before importing.
                    </p>

                    <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
                        <x-filament::button
                            wire:click="mountAction('{{ $this->getCurrentImportActionName() }}')"
                            color="primary"
                            icon="heroicon-o-arrow-up-tray"
                        >
                            Import {{ $entity['label'] }}
                        </x-filament::button>

                        <x-filament::button
                            color="gray"
                            wire:click="skipCurrentEntity"
                        >
                            Skip {{ $entity['label'] }}
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>

            {{-- Results so far --}}
            @if (count($importResults) > 0)
                <x-filament::section collapsible collapsed>
                    <x-slot name="heading">Progress So Far</x-slot>

                    <div class="space-y-2">
                        @foreach ($importResults as $entityKey => $result)
                            <div class="flex items-center justify-between py-2 px-3 rounded-lg bg-gray-50 dark:bg-gray-800">
                                <span class="text-sm font-medium text-gray-900 dark:text-white">
                                    {{ $this->getEntities()[$entityKey]['label'] }}
                                </span>
                                @if ($result['skipped'] ?? false)
                                    <span class="text-sm text-gray-500">Skipped</span>
                                @else
                                    <div class="flex items-center gap-3 text-sm">
                                        <span class="text-green-600 dark:text-green-400">
                                            {{ $result['imported'] }} imported
                                        </span>
                                        @if ($result['failed'] > 0)
                                            <span class="text-red-600 dark:text-red-400">
                                                {{ $result['failed'] }} failed
                                            </span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif
        </div>
    @endif

    {{-- Step 3: Complete --}}
    @if ($currentStep === 3)
        @php
            $totals = $this->getTotalCounts();
        @endphp

        <div class="text-center space-y-6">
            <div class="inline-flex items-center justify-center h-16 w-16 rounded-full bg-green-100 dark:bg-green-500/20">
                <x-filament::icon icon="heroicon-o-check-circle" class="h-10 w-10 text-green-600 dark:text-green-400" />
            </div>

            <div>
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Migration Complete!</h3>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    Your data has been successfully imported into Relaticle.
                </p>
            </div>

            {{-- Summary stats --}}
            <div class="grid grid-cols-3 gap-4 max-w-md mx-auto">
                <div class="rounded-lg bg-green-50 dark:bg-green-500/10 p-4">
                    <div class="text-2xl font-bold text-green-600 dark:text-green-400">{{ $totals['imported'] }}</div>
                    <div class="text-sm text-green-700 dark:text-green-300">Imported</div>
                </div>
                <div class="rounded-lg bg-red-50 dark:bg-red-500/10 p-4">
                    <div class="text-2xl font-bold text-red-600 dark:text-red-400">{{ $totals['failed'] }}</div>
                    <div class="text-sm text-red-700 dark:text-red-300">Failed</div>
                </div>
                <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-4">
                    <div class="text-2xl font-bold text-gray-600 dark:text-gray-400">{{ $totals['skipped'] }}</div>
                    <div class="text-sm text-gray-700 dark:text-gray-300">Skipped</div>
                </div>
            </div>

            {{-- Results per entity --}}
            <x-filament::section>
                <x-slot name="heading">Results by Entity</x-slot>

                <div class="divide-y dark:divide-gray-700">
                    @foreach ($importResults as $entityKey => $result)
                        <div class="flex items-center justify-between py-3">
                            <div class="flex items-center gap-3">
                                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-800">
                                    <x-filament::icon :icon="$this->getEntities()[$entityKey]['icon']" class="h-4 w-4 text-gray-500" />
                                </div>
                                <span class="font-medium text-gray-900 dark:text-white">
                                    {{ $this->getEntities()[$entityKey]['label'] }}
                                </span>
                            </div>
                            @if ($result['skipped'] ?? false)
                                <span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-800 px-2.5 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-400">
                                    Skipped
                                </span>
                            @else
                                <div class="flex items-center gap-2">
                                    <span class="inline-flex items-center rounded-full bg-green-100 dark:bg-green-500/20 px-2.5 py-0.5 text-xs font-medium text-green-700 dark:text-green-400">
                                        {{ $result['imported'] }} imported
                                    </span>
                                    @if ($result['failed'] > 0)
                                        <span class="inline-flex items-center rounded-full bg-red-100 dark:bg-red-500/20 px-2.5 py-0.5 text-xs font-medium text-red-700 dark:text-red-400">
                                            {{ $result['failed'] }} failed
                                        </span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>

            <div class="flex justify-center gap-3 pt-4">
                <x-filament::button
                    wire:click="resetWizard"
                    color="gray"
                >
                    Start New Migration
                </x-filament::button>
            </div>
        </div>
    @endif

    <x-filament-actions::modals />
</div>
