<div class="space-y-6">
    {{-- Preview Step Placeholder --}}
    <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
        <div class="text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-success-100 dark:bg-success-900/20">
                <x-heroicon-o-rocket-launch class="h-6 w-6 text-success-600 dark:text-success-400" />
            </div>
            <h3 class="mt-4 text-lg font-medium text-gray-900 dark:text-white">
                Ready to Import
            </h3>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                Review the import summary and start the import process.
            </p>

            {{-- Import Summary --}}
            <div class="mt-6 grid grid-cols-2 gap-4 max-w-xs mx-auto">
                <div class="rounded-lg bg-gray-50 dark:bg-gray-700/50 p-4">
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">
                        {{ number_format($rowCount) }}
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Total Rows
                    </p>
                </div>
                <div class="rounded-lg bg-gray-50 dark:bg-gray-700/50 p-4">
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">
                        {{ count($headers) }}
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Columns
                    </p>
                </div>
            </div>

            <p class="mt-4 text-xs text-gray-400 dark:text-gray-500">
                (Placeholder - import execution coming soon)
            </p>
        </div>
    </div>

    {{-- Navigation --}}
    <div class="flex justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
        <x-filament::button
            color="gray"
            wire:click="$parent.goBack()"
            icon="heroicon-o-arrow-left"
        >
            Back
        </x-filament::button>
        <x-filament::button
            color="success"
            icon="heroicon-o-play"
            icon-position="after"
        >
            Start Import
        </x-filament::button>
    </div>
</div>
