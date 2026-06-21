<div class="space-y-4">
    @if ($summary === null)
        <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-4">
            <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed">
                {{ __('No summary is available for this thread.') }}
            </p>
        </div>
    @else
        <div
            x-data="{
                copied: false,
                copy() {
                    window.navigator.clipboard.writeText(this.$refs.summary.innerText.trim()).then(() => {
                        this.copied = true;
                        setTimeout(() => this.copied = false, 2000);
                    });
                },
            }"
            class="space-y-4"
        >
            <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-4">
                <p x-ref="summary" class="text-sm text-gray-700 dark:text-gray-300 leading-relaxed">
                    {{ $summary->summary }}
                </p>
            </div>

            <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-clock class="h-4 w-4" />
                    <span>{{ __('Generated') }} {{ $summary->created_at->diffForHumans() }}</span>
                </div>

                <button
                    type="button"
                    x-on:click="copy()"
                    class="flex items-center gap-1.5 rounded-md px-2 py-1 font-medium text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                >
                    <template x-if="! copied">
                        <span class="flex items-center gap-1.5">
                            <x-heroicon-o-clipboard-document class="h-4 w-4" />
                            <span>{{ __('Copy') }}</span>
                        </span>
                    </template>
                    <template x-if="copied">
                        <span class="flex items-center gap-1.5 text-success-600 dark:text-success-400">
                            <x-heroicon-o-check class="h-4 w-4" />
                            <span>{{ __('Copied') }}</span>
                        </span>
                    </template>
                </button>
            </div>
        </div>
    @endif
</div>
