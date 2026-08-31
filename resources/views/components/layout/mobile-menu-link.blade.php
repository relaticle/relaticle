@props([
    'item',
])

{{-- One link inside a mobile-menu accordion. Adopts the desktop mega-menu's
     icon tile; items without an icon (e.g. the Compare column) stay a plain
     text row. Descriptions are dropped on purpose: the overlay is a fast
     jump list, not a browsing surface. --}}

<a href="{{ $item->url }}" @click="mobileMenu = false"
   @if($item->external) target="_blank" rel="noopener noreferrer" @endif
   @if(url()->current() === $item->url) aria-current="page" @endif
   class="flex items-center gap-3 text-lg font-medium leading-snug text-gray-600 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 active:opacity-60 transition-[color,opacity] duration-200 py-2">
    @if($item->icon)
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 ring-1 ring-inset ring-gray-900/[0.04] dark:bg-white/[0.07] dark:text-gray-400 dark:ring-white/[0.07]">
            <x-dynamic-component :component="'icons.'.$item->icon" class="h-6 w-6"/>
        </span>
    @endif
    {{ $item->label }}
</a>
