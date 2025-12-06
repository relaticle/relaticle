<x-filament-panels::page>
    <x-filament::tabs label="Import Center tabs">
        <x-filament::tabs.item
            :active="$activeTab === 'quick-import'"
            wire:click="setActiveTab('quick-import')"
            icon="heroicon-o-bolt"
        >
            Quick Import
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$activeTab === 'history'"
            wire:click="setActiveTab('history')"
            icon="heroicon-o-clock"
        >
            Import History
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$activeTab === 'migration'"
            wire:click="setActiveTab('migration')"
            icon="heroicon-o-arrows-right-left"
        >
            Migration Wizard
        </x-filament::tabs.item>
    </x-filament::tabs>

    <div class="mt-6">
        @if ($activeTab === 'quick-import')
            <div class="space-y-6">
                {{-- Entity Cards Grid --}}
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($this->getEntityTypes() as $key => $entity)
                        <div class="group relative rounded-xl border border-gray-200 bg-white p-5 transition-all duration-200 hover:border-gray-300 hover:shadow-sm dark:border-gray-800 dark:bg-gray-900 dark:hover:border-gray-700">
                            <div class="flex items-start gap-4">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600 transition-colors group-hover:bg-primary-50 group-hover:text-primary-600 dark:bg-gray-800 dark:text-gray-400 dark:group-hover:bg-primary-500/10 dark:group-hover:text-primary-400 pointer-events-none">
                                    <x-filament::icon
                                        :icon="$entity['icon']"
                                        class="h-5 w-5"
                                    />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h3 class="font-semibold text-gray-900 dark:text-white">
                                        {{ $entity['label'] }}
                                    </h3>
                                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                        {{ $entity['description'] }}
                                    </p>
                                </div>
                            </div>
                            <div class="mt-4">
                                <x-filament::button
                                    wire:click="mountAction('import{{ ucfirst($key) }}')"
                                    color="gray"
                                    size="sm"
                                    class="w-full"
                                >
                                    Import {{ $entity['label'] }}
                                </x-filament::button>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Import Tips --}}
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-5 dark:border-gray-800 dark:bg-gray-900/50">
                    <div class="flex items-start gap-3">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400">
                            <x-filament::icon
                                icon="heroicon-o-light-bulb"
                                class="h-4 w-4"
                            />
                        </div>
                        <div class="min-w-0">
                            <h4 class="font-medium text-gray-900 dark:text-white">Import Tips</h4>
                            <ul class="mt-2 space-y-1.5 text-sm text-gray-600 dark:text-gray-400">
                                <li class="flex items-start gap-2">
                                    <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-gray-400 dark:bg-gray-600"></span>
                                    <span>Supports CSV, Excel (.xlsx, .xls), and OpenDocument (.ods) formats</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-gray-400 dark:bg-gray-600"></span>
                                    <span>Column headers are automatically matched - adjust mappings before import</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-gray-400 dark:bg-gray-600"></span>
                                    <span>Custom fields appear automatically in column mapping</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-gray-400 dark:bg-gray-600"></span>
                                    <span>Link records to existing entities by name</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        @elseif ($activeTab === 'history')
            {{ $this->table }}
        @elseif ($activeTab === 'migration')
            <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <div class="border-b border-gray-200 px-6 py-5 dark:border-gray-800">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">
                            <x-filament::icon
                                icon="heroicon-o-arrows-right-left"
                                class="h-5 w-5"
                            />
                        </div>
                        <div>
                            <h3 class="font-semibold text-gray-900 dark:text-white">CRM Migration Wizard</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Import multiple entity types in the correct order</p>
                        </div>
                    </div>
                </div>
                <div class="p-6">
                    <livewire:import.migration-wizard />
                </div>
            </div>
        @endif
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
