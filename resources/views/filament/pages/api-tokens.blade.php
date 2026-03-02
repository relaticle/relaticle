<x-filament-panels::page>
    {{ $this->form }}

    <div class="mt-2">
        <p class="text-sm font-medium text-gray-950 dark:text-white mb-3">
            {{ __('access-tokens.integrations.heading') }}
        </p>
        <div class="divide-y divide-gray-200 dark:divide-white/10 rounded-xl border border-gray-200 dark:border-white/10 overflow-hidden">
            <a href="/docs/api" target="_blank" class="flex items-center gap-4 bg-white dark:bg-white/5 px-4 py-3 transition hover:bg-gray-50 dark:hover:bg-white/10 group">
                <div class="flex-shrink-0 flex items-center justify-center w-9 h-9 rounded-lg bg-gray-100 dark:bg-white/10 text-gray-500 dark:text-gray-400 group-hover:text-primary-600 dark:group-hover:text-primary-400 transition">
                    <x-heroicon-o-code-bracket class="w-5 h-5" />
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-950 dark:text-white">{{ __('access-tokens.integrations.api_link') }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('access-tokens.integrations.api_description') }}</p>
                </div>
                <x-heroicon-m-arrow-right class="w-4 h-4 flex-shrink-0 text-gray-400 dark:text-gray-500 group-hover:text-primary-600 dark:group-hover:text-primary-400 transition" />
            </a>
            <a href="{{ route('documentation.show', ['type' => 'mcp']) }}" target="_blank" class="flex items-center gap-4 bg-white dark:bg-white/5 px-4 py-3 transition hover:bg-gray-50 dark:hover:bg-white/10 group">
                <div class="flex-shrink-0 flex items-center justify-center w-9 h-9 rounded-lg bg-gray-100 dark:bg-white/10 text-gray-500 dark:text-gray-400 group-hover:text-primary-600 dark:group-hover:text-primary-400 transition">
                    <x-heroicon-o-cpu-chip class="w-5 h-5" />
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-950 dark:text-white">{{ __('access-tokens.integrations.mcp_link') }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('access-tokens.integrations.mcp_description') }}</p>
                </div>
                <x-heroicon-m-arrow-right class="w-4 h-4 flex-shrink-0 text-gray-400 dark:text-gray-500 group-hover:text-primary-600 dark:group-hover:text-primary-400 transition" />
            </a>
        </div>
    </div>
</x-filament-panels::page>
