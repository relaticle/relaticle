@props(['query' => ''])

<form action="{{ route('documentation.search') }}" method="GET"
      {{ $attributes->merge(['class' => 'relative']) }}>
    <div class="relative flex items-center gap-2 rounded-full border border-gray-200/80 dark:border-white/[0.08] bg-white dark:bg-white/[0.03] px-4 py-1.5 shadow-[0_1px_3px_rgba(0,0,0,0.04)] focus-within:border-primary/40 dark:focus-within:border-primary/30 focus-within:shadow-[0_0_0_3px_rgba(124,58,237,0.06)] transition-all duration-200">
        <x-heroicon-o-magnifying-glass class="h-4 w-4 text-gray-400 dark:text-gray-500 shrink-0"/>
        <input
            type="text"
            name="query"
            value="{{ $query }}"
            class="flex-1 bg-transparent border-none outline-none text-sm text-gray-700 dark:text-gray-200 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-0 focus:outline-none focus:border-none focus:shadow-none ring-0 shadow-none p-0 py-1.5"
            style="box-shadow: none !important; outline: none !important;"
            placeholder="Search documentation..."
        >
        <kbd class="hidden sm:inline-flex items-center gap-0.5 rounded-md border border-gray-200/80 dark:border-white/[0.08] bg-gray-50/80 dark:bg-white/[0.04] px-1.5 py-0.5 text-[10px] font-medium text-gray-400 dark:text-gray-500">
            <span class="text-xs">⌘</span>K
        </kbd>
        <button type="submit"
                class="shrink-0 flex items-center justify-center h-8 px-4 rounded-full bg-primary text-white text-xs font-medium hover:bg-primary-600 transition-colors duration-200">
            Search
        </button>
    </div>
</form>
