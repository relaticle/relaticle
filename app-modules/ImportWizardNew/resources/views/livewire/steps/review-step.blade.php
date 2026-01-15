<div class="space-y-6">
    {{-- Review Step Placeholder --}}
    <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
        <div class="text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-primary-100 dark:bg-primary-900/20">
                <x-heroicon-o-magnifying-glass class="h-6 w-6 text-primary-600 dark:text-primary-400" />
            </div>
            <h3 class="mt-4 text-lg font-medium text-gray-900 dark:text-white">
                Value Review
            </h3>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                Review unique values and fix any invalid data.
            </p>

            {{-- Show row count --}}
            <div class="mt-4 inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200">
                {{ number_format($rowCount) }} rows to review
            </div>

            <p class="mt-4 text-xs text-gray-400 dark:text-gray-500">
                (Placeholder - value review UI coming soon)
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
            wire:click="$parent.nextStep()"
            icon="heroicon-o-arrow-right"
            icon-position="after"
        >
            Continue to Preview
        </x-filament::button>
    </div>
</div>
