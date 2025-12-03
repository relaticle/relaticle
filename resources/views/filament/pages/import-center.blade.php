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
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->getEntityTypes() as $key => $entity)
                    <x-filament::section>
                        <x-slot name="heading">
                            <div class="flex items-center gap-2">
                                <x-filament::icon
                                    :icon="$entity['icon']"
                                    class="h-5 w-5 text-gray-500 dark:text-gray-400"
                                />
                                {{ $entity['label'] }}
                            </div>
                        </x-slot>

                        <x-slot name="description">
                            {{ $entity['description'] }}
                        </x-slot>

                        <div class="flex items-center">
                            <x-filament::button
                                wire:click="mountAction('import{{ ucfirst($key) }}')"
                                color="primary"
                            >
                                Import {{ $entity['label'] }}
                            </x-filament::button>
                        </div>
                    </x-filament::section>
                @endforeach
            </div>

            <div class="mt-6">
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-filament::icon
                                icon="heroicon-o-information-circle"
                                class="h-5 w-5 text-blue-500"
                            />
                            Import Tips
                        </div>
                    </x-slot>

                    <div class="prose prose-sm dark:prose-invert max-w-none">
                        <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
                            <li><strong>File formats:</strong> CSV, Excel (.xlsx, .xls), and OpenDocument (.ods) are supported</li>
                            <li><strong>Column mapping:</strong> Headers are automatically matched - you can adjust mappings before import</li>
                            <li><strong>Duplicates:</strong> Choose how to handle existing records (skip, update, or create new)</li>
                            <li><strong>Custom fields:</strong> Your custom fields will appear in the column mapping automatically</li>
                            <li><strong>Relationships:</strong> Link records to existing companies, people, or opportunities by name</li>
                        </ul>
                    </div>
                </x-filament::section>
            </div>
        @elseif ($activeTab === 'history')
            <x-filament::section>
                <x-slot name="heading">
                    Recent Imports
                </x-slot>

                <x-slot name="description">
                    View the status and results of your recent imports
                </x-slot>

                {{ $this->table }}
            </x-filament::section>
        @elseif ($activeTab === 'migration')
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-filament::icon
                            icon="heroicon-o-arrows-right-left"
                            class="h-5 w-5 text-primary-500"
                        />
                        CRM Migration Wizard
                    </div>
                </x-slot>

                <x-slot name="description">
                    Import multiple entity types in the correct order with guided steps
                </x-slot>

                <livewire:import.migration-wizard />
            </x-filament::section>
        @endif
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
