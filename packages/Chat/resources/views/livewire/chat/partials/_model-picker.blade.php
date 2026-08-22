<div
    x-data="{ menuOpen: false }"
    x-on:keydown.escape.window="menuOpen = false"
    x-effect="if (!menuOpen) lockedModelHint = false"
    class="relative"
>
    <button
        type="button"
        x-on:click="menuOpen = !menuOpen"
        class="inline-flex h-7 items-center gap-1 rounded-md border px-2 text-xs font-medium transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500"
        :class="menuOpen
            ? 'border-gray-200 bg-gray-50 text-gray-900 dark:border-gray-700 dark:bg-gray-700 dark:text-white'
            : 'border-transparent bg-transparent text-gray-600 hover:border-gray-200 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-300 dark:hover:border-gray-700 dark:hover:bg-gray-700 dark:hover:text-white'"
        :aria-expanded="menuOpen"
        aria-haspopup="listbox"
        aria-label="{{ __('Select AI model') }}"
    >
        <span
            x-show="modelProvider(selectedModel)"
            x-html="providerIconHtml(modelProvider(selectedModel))"
            :class="providerIconColor(modelProvider(selectedModel)) + ' inline-flex h-3.5 w-3.5 shrink-0 items-center justify-center'"
            aria-hidden="true"
        ></span>
        <span x-text="modelLabel(selectedModel)"></span>
        <x-heroicon-o-chevron-up-down class="h-3 w-3" aria-hidden="true" />
    </button>
    <div
        x-show="menuOpen"
        x-cloak
        x-on:click.away="menuOpen = false"
        x-transition:enter="motion-safe:transition motion-safe:ease-out motion-safe:duration-100"
        x-transition:enter-start="motion-safe:opacity-0"
        x-transition:enter-end="motion-safe:opacity-100"
        class="absolute bottom-full end-0 z-10 mb-2 w-56 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-900"
    >
        <div role="listbox" aria-label="{{ __('AI model options') }}" class="max-h-64 overflow-y-auto">
            <template x-for="opt in modelOptions" :key="opt.value">
                <button
                    type="button"
                    role="option"
                    :aria-selected="selectedModel === opt.value"
                    :aria-disabled="! allowedModels.includes(opt.value)"
                    x-on:click="selectModel(opt.value); if (allowedModels.includes(opt.value)) menuOpen = false"
                    class="flex w-full items-center gap-2 px-3 py-1.5 text-start text-xs transition hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-500 dark:hover:bg-gray-800"
                    :class="{
                        'bg-gray-50 dark:bg-gray-800': selectedModel === opt.value && allowedModels.includes(opt.value),
                        'text-gray-700 dark:text-gray-200': allowedModels.includes(opt.value),
                        'cursor-default text-gray-400 dark:text-gray-500': ! allowedModels.includes(opt.value),
                    }"
                >
                    <span
                        x-html="providerIconHtml(opt.provider)"
                        :class="providerIconColor(opt.provider) + ' inline-flex h-4 w-4 shrink-0 items-center justify-center'"
                        aria-hidden="true"
                    ></span>
                    <span class="flex-1 truncate" :title="opt.label" x-text="opt.label"></span>
                    <span
                        x-show="! allowedModels.includes(opt.value)"
                        class="ms-auto inline-flex shrink-0 items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[length:var(--text-micro)] font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300"
                    >
                        <x-heroicon-m-lock-closed class="h-2.5 w-2.5" aria-hidden="true" />
                        {{ __('Pro') }}
                    </span>
                    <x-heroicon-s-check-circle
                        x-show="selectedModel === opt.value && allowedModels.includes(opt.value)"
                        class="h-3.5 w-3.5 shrink-0 text-primary-600 dark:text-primary-400"
                        aria-hidden="true"
                    />
                </button>
            </template>
        </div>

        {{-- Inline upsell hint after clicking a locked model: replaces a
             dispatched event nothing listened for (the click was a silent no-op). --}}
        <div
            x-show="lockedModelHint"
            x-cloak
            role="status"
            class="border-t border-gray-100 px-3 py-2 text-[length:var(--text-micro)] text-gray-500 dark:border-white/5 dark:text-gray-400"
        >
            <span>{{ __('Available on the Pro plan.') }}</span>
            <template x-if="upgradeUrl">
                <a
                    :href="upgradeUrl"
                    class="font-medium text-primary-600 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:text-primary-400"
                >{{ __('Upgrade') }}</a>
            </template>
        </div>
    </div>
</div>
