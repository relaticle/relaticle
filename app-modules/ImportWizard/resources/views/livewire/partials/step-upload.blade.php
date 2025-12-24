<div class="space-y-6">
    {{-- File Upload Container --}}
    @if ($persistedFilePath && $csvHeaders)
        @php
            $fileName = $uploadedFile?->getClientOriginalName() ?? 'import.csv';
            $fileExtension = strtoupper(pathinfo($fileName, PATHINFO_EXTENSION));
            $fileSizeFormatted = Number::fileSize($uploadedFile?->getSize() ?? 0, precision: 1);
        @endphp
        <div class="rounded-xl border border-dashed border-gray-200 dark:border-gray-700 p-12 flex items-center justify-center min-h-[400px]">
            {{-- File Info Card --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 w-full max-w-sm">
                {{-- File Header --}}
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $fileName }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">.{{ $fileExtension }} file - {{ $fileSizeFormatted }}</p>
                    </div>
                    <button
                        type="button"
                        wire:click="removeFile"
                        class="p-1.5 rounded-lg text-gray-400 hover:text-danger-500 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                    >
                        <x-filament::icon icon="heroicon-o-trash" class="h-4 w-4" />
                    </button>
                </div>

                {{-- Divider --}}
                <div class="border-t border-dashed border-gray-200 dark:border-gray-700 my-4"></div>

                {{-- Stats --}}
                <div class="grid grid-cols-2 gap-3">
                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3">
                        <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                            <x-filament::icon icon="heroicon-o-view-columns" class="h-3.5 w-3.5" />
                            <span>Columns found</span>
                        </div>
                        <p class="text-xl font-semibold text-gray-950 dark:text-white mt-1">{{ count($csvHeaders) }}</p>
                    </div>
                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3">
                        <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                            <x-filament::icon icon="heroicon-o-queue-list" class="h-3.5 w-3.5" />
                            <span>Rows found</span>
                        </div>
                        <p class="text-xl font-semibold text-gray-950 dark:text-white mt-1">{{ number_format($rowCount) }}</p>
                    </div>
                </div>
            </div>
        </div>
    @else
        <div
            x-data="{ dragging: false }"
            x-on:dragover.prevent="dragging = true"
            x-on:dragleave.prevent="dragging = false"
            x-on:drop.prevent="dragging = false"
            x-bind:class="dragging ? 'border-primary-500 bg-primary-50 dark:bg-primary-950' : 'border-gray-300 dark:border-gray-800 bg-gray-100 dark:bg-gray-900 dark:border-gray-700'"
            class="relative  rounded-xl border border-dashed p-12 text-center transition-colors flex flex-col items-center justify-center min-h-[400px]"
        >
            <input
                type="file"
                id="file-upload"
                wire:model="uploadedFile"
                accept=".csv,.txt"
                class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10"
            >

            <div class="flex flex-col items-center">
                <x-filament::icon
                    icon="heroicon-o-arrow-up-tray"
                    class="h-12 w-12 text-gray-400 dark:text-gray-600"
                />
                <p class="mt-6 text-sm text-gray-700 dark:text-gray-400">
                    Drop your .CSV file onto this area to upload
                </p>
                <div class="flex items-center gap-4 mt-6 w-full max-w-xs">
                    <div class="flex-1 border-t border-dashed border-gray-300 dark:border-gray-600"></div>
                    <span class="text-sm text-gray-500 dark:text-gray-500">or</span>
                    <div class="flex-1 border-t border-dashed border-gray-300 dark:border-gray-600"></div>
                </div>
                <x-filament::button
                    color="gray"
                    class="mt-6 pointer-events-none"
                >
                    Choose a file
                </x-filament::button>
            </div>

            @error('uploadedFile')
                <p class="mt-4 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
            @enderror
        </div>
    @endif

    {{-- Navigation --}}
    <div class="flex justify-end pt-4 border-t border-gray-200 dark:border-gray-700">
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
