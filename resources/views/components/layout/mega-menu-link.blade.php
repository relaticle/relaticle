@props([
    'item',
])

{{-- One row inside a mega-menu panel. Renders a brand icon tile plus a
     description when the NavItem carries them, and a plain label otherwise,
     so the Product panel reads as rich cards while the Compare column stays
     a scannable list. --}}

<a href="{{ $item->url }}"
   @if($item->external) target="_blank" rel="noopener noreferrer" @endif
   @if(url()->current() === $item->url) aria-current="page" @endif
   @click="openDropdown = null"
   class="group flex items-start gap-3 rounded-lg px-3 py-2 hover:bg-gray-50 dark:hover:bg-white/[0.04] transition-colors">

    @if($item->icon)
        <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-50 text-gray-500 group-hover:bg-primary/[0.08] group-hover:text-primary dark:bg-white/[0.05] dark:text-gray-400 dark:group-hover:bg-primary/[0.15] dark:group-hover:text-primary-400 transition-colors">
            <x-brand.nav-icon :name="$item->icon" class="h-5 w-5"/>
        </span>
    @endif

    <span class="min-w-0">
        <span class="flex items-center gap-1 text-[13px] font-medium text-gray-900 dark:text-white">
            {{ $item->label }}
            <x-ri-arrow-right-line class="h-3 w-3 shrink-0 -translate-x-1 opacity-0 text-gray-400 transition duration-150 group-hover:translate-x-0 group-hover:opacity-100 motion-reduce:transition-none"/>
        </span>

        @if($item->description)
            <span class="mt-0.5 block text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                {{ $item->description }}
            </span>
        @endif
    </span>
</a>
