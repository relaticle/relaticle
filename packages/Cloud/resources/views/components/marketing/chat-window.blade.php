<x-cloud::marketing.window :attributes="$attributes">
    <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-white/[0.06]">
        <x-cloud::marketing.actor as="rela"/>
        <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Northwind workspace') }}</span>
    </div>

    {{ $slot }}
</x-cloud::marketing.window>
