<div class="flex flex-col h-full">
    {{-- File Upload Container --}}
    <div class="flex-1 flex flex-col min-h-[12rem]">
    @if ($isParsed)
        @php
            $fileName = $uploadedFile?->getClientOriginalName() ?? 'import.csv';
            $fileExtension = strtoupper(pathinfo($fileName, PATHINFO_EXTENSION));
            $fileSizeFormatted = Number::fileSize($uploadedFile?->getSize() ?? 0, precision: 1);
        @endphp
        <div class="flex-1 rounded-xl border border-dashed border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900 p-12 flex items-center justify-center">
            {{-- File Info Card --}}
            <div x-data class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 w-full max-w-sm">
                {{-- Hidden file input for replace --}}
                <input x-ref="replaceInput" type="file" wire:model="uploadedFile" accept=".csv" class="hidden" />

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
                        <p class="text-xl font-semibold text-gray-950 dark:text-white mt-1">{{ count($headers) }}</p>
                    </div>
                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3">
                        <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                            <x-filament::icon icon="heroicon-o-queue-list" class="h-3.5 w-3.5" />
                            <span>Rows found</span>
                        </div>
                        <p class="text-xl font-semibold text-gray-950 dark:text-white mt-1">{{ number_format($rowCount) }}</p>
                    </div>
                </div>

                {{-- Column Preview --}}
                @if (count($headers) > 0)
                    <div class="mt-4 pt-4 border-t border-dashed border-gray-200 dark:border-gray-700">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">Column preview</p>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach (array_slice($headers, 0, 5) as $header)
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                                    {{ Str::limit($header, 15) }}
                                </span>
                            @endforeach
                            @if (count($headers) > 5)
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs bg-gray-100 dark:bg-gray-700 text-gray-500">
                                    +{{ count($headers) - 5 }} more
                                </span>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @else
        <div
            x-data="{ dragging: false }"
            x-on:dragover.prevent="dragging = true"
            x-on:dragleave.prevent="dragging = false"
            x-on:drop.prevent="
                dragging = false;
                const file = $event.dataTransfer.files[0];
                if (file) {
                    $refs.fileInput.files = $event.dataTransfer.files;
                    $refs.fileInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
            "
            x-bind:class="dragging
                ? 'border-primary-500 bg-primary-50 dark:bg-primary-950 ring-2 ring-primary-500/20'
                : 'border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-900'"
            class="flex-1 relative rounded-xl border border-dashed p-12 transition-all duration-200 grid place-items-center"
        >
            {{-- Hidden file input (NOT covering the zone) --}}
            <input
                x-ref="fileInput"
                type="file"
                wire:model="uploadedFile"
                accept=".csv"
                class="hidden"
                aria-label="Choose CSV file to upload"
            >

            {{-- Content (hidden during loading) --}}
            <div class="flex flex-col items-center text-center" wire:loading.remove wire:target="uploadedFile">
                <x-filament::icon
                    icon="heroicon-o-arrow-up-tray"
                    class="h-12 w-12 text-gray-400 dark:text-gray-600 mx-auto"
                />
                <p class="mt-6 text-sm text-gray-700 dark:text-gray-400">
                    Drop your .CSV file onto this area to upload
                </p>
                <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                    Max 10,000 rows • Max 10MB
                </p>
                <div class="flex items-center gap-4 mt-6 w-full max-w-xs">
                    <div class="flex-1 border-t border-dashed border-gray-300 dark:border-gray-600"></div>
                    <span class="text-sm text-gray-500 dark:text-gray-500">or</span>
                    <div class="flex-1 border-t border-dashed border-gray-300 dark:border-gray-600"></div>
                </div>
                <x-filament::button
                    color="gray"
                    class="mt-6"
                    x-on:click.prevent="$refs.fileInput.click()"
                >
                    Choose a .CSV file
                </x-filament::button>

                {{-- Validation Error --}}
                @error('uploadedFile')
                    <p class="mt-3 text-sm text-danger-600 dark:text-danger-400 text-center">{{ $message }}</p>
                @enderror
            </div>

            {{-- Loading (absolute centered) --}}
            <div wire:loading.flex wire:target="uploadedFile" class="absolute inset-0 items-center justify-center">
                <div class="flex flex-col items-center text-center">
                    <x-filament::loading-indicator class="h-10 w-10 text-primary-500" />
                    <p class="mt-4 text-sm font-medium text-gray-700 dark:text-gray-300">Analyzing your file...</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Detecting columns and counting rows</p>
                </div>
            </div>

        </div>
    @endif
    </div>

    {{-- Navigation --}}
    <div class="flex justify-end pt-4 mt-6 border-t border-gray-200 dark:border-gray-700 pb-1">
        <x-filament::button
            wire:click="continueToMapping"
            :disabled="!$isParsed"
            wire:loading.attr="disabled"
        >
            <span wire:loading.remove wire:target="continueToMapping">Continue</span>
            <span wire:loading wire:target="continueToMapping">Processing...</span>
        </x-filament::button>
    </div>
</div>
