@if ($items !== [])
    <x-filament::dropdown placement="bottom-end" teleport class="fi-topbar-help me-1">
        <x-slot name="trigger">
            <button
                type="button"
                aria-label="{{ __('support.help') }}"
                title="{{ __('support.help') }}"
                class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-sm font-medium text-gray-600 shadow-sm transition hover:bg-gray-50 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white"
            >
                <x-heroicon-o-question-mark-circle class="h-4 w-4" aria-hidden="true" />
                <span class="hidden sm:inline">{{ __('support.help') }}</span>
            </button>
        </x-slot>

        <x-filament::dropdown.list>
            @foreach ($items as $item)
                <x-filament::dropdown.list.item
                    tag="a"
                    :href="$item['url']"
                    target="_blank"
                    :icon="$item['icon']"
                >
                    {{ $item['label'] }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>
@endif
