<div class="space-y-6">
    @if (!$isParsed)
        {{-- File Upload Zone --}}
        <div
            x-data="{ dragging: false }"
            x-on:dragover.prevent="dragging = true"
            x-on:dragleave.prevent="dragging = false"
            x-on:drop.prevent="dragging = false"
            class="relative"
        >
            <label
                for="uploadedFile"
                :class="dragging ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-gray-300 dark:border-gray-600'"
                class="flex flex-col items-center justify-center w-full h-64 border-2 border-dashed rounded-lg cursor-pointer bg-gray-50 dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
            >
                <div class="flex flex-col items-center justify-center pt-5 pb-6">
                    <div class="p-3 mb-3 rounded-full bg-gray-100 dark:bg-gray-700">
                        <x-heroicon-o-arrow-up-tray class="w-8 h-8 text-gray-400" />
                    </div>
                    <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">
                        <span class="font-semibold">Click to upload</span> or drag and drop
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        CSV file (max {{ config('import-wizard-new.max_rows', 10000) }} rows)
                    </p>
                </div>
                <input
                    id="uploadedFile"
                    type="file"
                    wire:model="uploadedFile"
                    accept=".csv,.txt"
                    class="hidden"
                />
            </label>

            {{-- Loading State --}}
            <div wire:loading wire:target="uploadedFile" class="absolute inset-0 flex items-center justify-center bg-white/80 dark:bg-gray-800/80 rounded-lg">
                <div class="flex items-center space-x-3">
                    <x-filament::loading-indicator class="h-6 w-6 text-primary-500" />
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Processing file...</span>
                </div>
            </div>
        </div>

        {{-- Error Message --}}
        @error('uploadedFile')
            <div class="rounded-md bg-danger-50 dark:bg-danger-900/20 p-4">
                <div class="flex">
                    <x-heroicon-s-x-circle class="h-5 w-5 text-danger-400" />
                    <div class="ml-3">
                        <p class="text-sm text-danger-700 dark:text-danger-400">{{ $message }}</p>
                    </div>
                </div>
            </div>
        @enderror
    @else
        {{-- File Uploaded - Show Summary --}}
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
            <div class="flex items-start space-x-4">
                <div class="flex-shrink-0">
                    <div class="p-3 rounded-full bg-success-100 dark:bg-success-900/20">
                        <x-heroicon-s-document-check class="w-6 h-6 text-success-600 dark:text-success-400" />
                    </div>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="text-sm font-medium text-gray-900 dark:text-white">
                        File uploaded successfully
                    </h3>
                    <div class="mt-2 grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <span class="text-gray-500 dark:text-gray-400">Rows:</span>
                            <span class="ml-2 font-medium text-gray-900 dark:text-white">{{ number_format($rowCount) }}</span>
                        </div>
                        <div>
                            <span class="text-gray-500 dark:text-gray-400">Columns:</span>
                            <span class="ml-2 font-medium text-gray-900 dark:text-white">{{ count($headers) }}</span>
                        </div>
                    </div>

                    {{-- Column Preview --}}
                    <div class="mt-4">
                        <span class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Detected Columns</span>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($headers as $header)
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200">
                                    {{ $header }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="flex-shrink-0">
                    <button
                        wire:click="removeFile"
                        type="button"
                        class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300"
                    >
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>
            </div>
        </div>

        {{-- Navigation --}}
        <div class="flex justify-end pt-4 border-t border-gray-200 dark:border-gray-700">
            <x-filament::button
                wire:click="continueToMapping"
                wire:loading.attr="disabled"
                icon="heroicon-o-arrow-right"
                icon-position="after"
            >
                <span wire:loading.remove wire:target="continueToMapping">Continue to Mapping</span>
                <span wire:loading wire:target="continueToMapping">Creating import...</span>
            </x-filament::button>
        </div>
    @endif
</div>
