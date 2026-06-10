<div
    x-data="{ menuOpen: false }"
    x-on:keydown.escape.window="menuOpen = false"
    class="relative"
>
    <button
        type="button"
        x-on:click="menuOpen = !menuOpen"
        class="inline-flex h-7 items-center gap-1 rounded-md border px-2 text-xs font-medium transition"
        :class="menuOpen
            ? 'border-gray-200 bg-gray-50 text-gray-900 dark:border-gray-700 dark:bg-gray-700 dark:text-white'
            : 'border-transparent bg-transparent text-gray-600 hover:border-gray-200 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-300 dark:hover:border-gray-700 dark:hover:bg-gray-700 dark:hover:text-white'"
        :aria-expanded="menuOpen"
        aria-haspopup="listbox"
        aria-label="Select AI model"
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
        x-on:click.away="menuOpen = false"
        x-transition.opacity.duration.100ms
        role="listbox"
        aria-label="AI model options"
        class="absolute bottom-full right-0 z-10 mb-2 w-56 overflow-hidden rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800"
        style="display: none;"
    >
        <template x-for="opt in modelOptions" :key="opt.value">
            <button
                type="button"
                role="option"
                :aria-selected="selectedModel === opt.value"
                :aria-disabled="! allowedModels.includes(opt.value)"
                :disabled="false"
                x-on:click="selectModel(opt.value); menuOpen = false"
                class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs hover:bg-gray-50 dark:hover:bg-gray-700"
                :class="{
                    'bg-gray-50 dark:bg-gray-700/50': selectedModel === opt.value && allowedModels.includes(opt.value),
                    'text-gray-700 dark:text-gray-200': allowedModels.includes(opt.value),
                    'text-gray-400 dark:text-gray-500': ! allowedModels.includes(opt.value),
                }"
            >
                <span
                    x-html="providerIconHtml(opt.provider)"
                    :class="providerIconColor(opt.provider) + ' inline-flex h-4 w-4 shrink-0 items-center justify-center'"
                    aria-hidden="true"
                ></span>
                <span class="flex-1 truncate" x-text="opt.label"></span>
                <span
                    x-show="! allowedModels.includes(opt.value)"
                    class="ml-auto inline-flex shrink-0 items-center rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300"
                >
                    Pro
                </span>
                <x-heroicon-s-check-circle
                    x-show="selectedModel === opt.value && allowedModels.includes(opt.value)"
                    class="h-3.5 w-3.5 shrink-0 text-primary-600 dark:text-primary-400"
                    aria-hidden="true"
                />
            </button>
        </template>
    </div>
</div>
