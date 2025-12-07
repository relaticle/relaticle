<div class="space-y-6">
    {{-- File Upload --}}
    <div
        x-data="{ dragging: false }"
        x-on:dragover.prevent="dragging = true"
        x-on:dragleave.prevent="dragging = false"
        x-on:drop.prevent="dragging = false"
        x-bind:class="dragging ? 'border-primary-500 bg-primary-50 dark:bg-primary-950' : 'border-gray-300 dark:border-gray-600'"
        class="relative rounded-xl border-2 border-dashed p-8 text-center transition-colors"
    >
        <input
            type="file"
            id="file-upload"
            wire:model="uploadedFile"
            accept=".csv,.xlsx,.xls,.ods,.txt"
            class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
        >

        <div class="flex flex-col items-center">
            <x-filament::icon
                icon="heroicon-o-arrow-up-tray"
                class="h-10 w-10 text-gray-400 dark:text-gray-500"
            />
            <p class="mt-4 text-sm font-medium text-gray-950 dark:text-white">
                Drop your file here or click to browse
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                CSV, Excel (.xlsx, .xls), OpenDocument (.ods)
            </p>
        </div>
    </div>

    @error('uploadedFile')
        <p class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
    @enderror

    {{-- File Info --}}
    @if ($persistedFilePath && $csvHeaders)
        <x-filament::section compact class="bg-success-50 dark:bg-success-950">
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-s-check-circle" class="h-5 w-5 text-success-500 shrink-0" />
                <div class="min-w-0">
                    <p class="text-sm font-medium text-success-800 dark:text-success-200">File Ready</p>
                    <p class="text-sm text-success-700 dark:text-success-300">
                        {{ count($csvHeaders) }} columns &middot; {{ number_format($rowCount) }} rows
                    </p>
                </div>
            </div>
        </x-filament::section>
    @endif

    {{-- Navigation --}}
    <div class="flex justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
        @if ($returnUrl)
            <x-filament::button
                wire:click="cancelImport"
                color="gray"
                icon="heroicon-m-x-mark"
            >
                Cancel
            </x-filament::button>
        @else
            <div></div>
        @endif
        <x-filament::button
            wire:click="nextStep"
            :disabled="!$this->canProceedToNextStep()"
            icon="heroicon-m-arrow-right"
            icon-position="after"
        >
            Continue
        </x-filament::button>
    </div>
</div>
