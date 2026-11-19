<div>
    @if($outcome !== \Relaticle\Cloud\Referral\Enums\ReferralPromptOutcome::Dismissed->value)
        <div
            data-referral-prompt
            class="mx-4 mb-2 rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-white/5"
        >
            <div class="flex items-start justify-between gap-2">
                <p class="text-sm font-medium text-gray-950 dark:text-white">{{ __('cloud::referral.heading') }}</p>

                @if($outcome === null)
                    <button
                        type="button"
                        wire:click="dismiss"
                        aria-label="{{ __('cloud::referral.dismiss') }}"
                        class="-me-1 -mt-1 flex size-7 shrink-0 items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 max-sm:size-11 dark:hover:bg-white/5 dark:hover:text-gray-200"
                    >
                        <x-lucide-x class="size-4" />
                    </button>
                @endif
            </div>

            @if($outcome === null)
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('cloud::referral.body') }}</p>

                <button
                    type="button"
                    x-on:click="navigator.clipboard.writeText(@js($this->link)).then(() => $wire.copied())"
                    class="mt-2 inline-flex items-center gap-1.5 rounded-md border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-gray-700 transition hover:border-gray-300 hover:text-gray-950 max-sm:min-h-11 dark:border-white/10 dark:bg-transparent dark:text-gray-300 dark:hover:text-white"
                >
                    <x-lucide-copy class="size-3.5" />
                    {{ __('cloud::referral.copy') }}
                </button>
            @else
                <p class="mt-1 flex items-center gap-1.5 text-sm text-success-600 dark:text-success-400">
                    <x-lucide-check class="size-4" />
                    {{ __('cloud::referral.copied') }}
                </p>
            @endif
        </div>
    @endif
</div>
