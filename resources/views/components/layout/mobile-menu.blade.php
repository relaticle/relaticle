{{-- Full-screen mobile menu --}}
<nav x-show="mobileMenu"
     x-ref="overlay"
     x-transition.opacity.duration.200ms
     @keydown.escape.window="mobileMenu = false"
     {{-- Both bindings are required: Alpine skips a key handler when a system
          modifier is held that the expression did not list, so `.tab` alone never
          sees Shift+Tab and focus escapes backwards out of the overlay. --}}
     @keydown.tab="trapTab($event)"
     @keydown.shift.tab="trapTab($event)"
     class="md:hidden fixed inset-0 z-[60] bg-white dark:bg-gray-950 flex flex-col"
     x-cloak
     aria-label="{{ __('Mobile menu') }}">

    {{-- Header --}}
    <div class="flex items-center justify-between h-16 px-4 shrink-0">
        <a href="{{ url('/') }}" aria-label="Relaticle Home">
            <x-brand.logo-lockup size="md" class="text-black dark:text-white"/>
        </a>
        {{-- Focus lands here when the overlay opens, so the trap has somewhere to
             start and a keyboard user's first Tab walks the menu, not the page. --}}
        <button type="button" @click="mobileMenu = false"
                x-ref="close"
                x-effect="if (mobileMenu) $nextTick(() => $refs.close.focus())"
                class="p-2 text-gray-400 hover:text-gray-900 dark:hover:text-white rounded-lg transition-colors cursor-pointer"
                aria-label="Close menu">
            <x-ri-close-line class="w-5 h-5"/>
        </button>
    </div>

    {{-- Navigation --}}
    @php($mobileNavItems = app(\App\Support\MarketingNavigation::class)->mobile())

    <div class="flex-1 flex flex-col px-8 overflow-y-auto py-6">
        <div class="space-y-1 my-auto">
            @foreach($mobileNavItems as $item)
                @if($item->url === null && count($item->children) > 0)
                    @php($slug = \Illuminate\Support\Str::slug($item->label))
                    <div x-data="{ expanded: false }" class="py-2">
                        <button type="button" @click="expanded = !expanded"
                                :aria-expanded="expanded.toString()"
                                aria-controls="mobile-menu-{{ $slug }}"
                                class="w-full flex items-center justify-between text-[2rem] font-semibold text-gray-950 dark:text-white hover:text-primary dark:hover:text-primary-400 transition-colors cursor-pointer">
                            <span>{{ $item->label }}</span>
                            <x-ri-arrow-down-s-line class="w-7 h-7 shrink-0 transition-transform duration-150"
                                                     ::class="expanded && 'rotate-180'"/>
                        </button>
                        <div id="mobile-menu-{{ $slug }}" x-show="expanded" x-collapse x-cloak class="pl-4 space-y-1">
                            @foreach($item->children as $child)
                                @if($child->url === null && count($child->children) > 0)
                                    <p class="pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                        {{ $child->label }}
                                    </p>
                                    @foreach($child->children as $grandchild)
                                        <a href="{{ $grandchild->url }}" @click="mobileMenu = false"
                                           @if($grandchild->external) target="_blank" rel="noopener noreferrer" @endif
                                           @if(url()->current() === $grandchild->url) aria-current="page" @endif
                                           class="block text-lg font-medium text-gray-600 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors py-1.5">
                                            {{ $grandchild->label }}
                                        </a>
                                    @endforeach
                                @else
                                    <a href="{{ $child->url }}" @click="mobileMenu = false"
                                       @if($child->external) target="_blank" rel="noopener noreferrer" @endif
                                       @if(url()->current() === $child->url) aria-current="page" @endif
                                       class="block text-lg font-medium text-gray-600 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors py-1.5">
                                        {{ $child->label }}
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @else
                    <a href="{{ $item->url }}" @click="mobileMenu = false"
                       @if($item->external) target="_blank" rel="noopener noreferrer" @endif
                       @if(url()->current() === $item->url) aria-current="page" @endif
                       class="text-[2rem] font-semibold text-gray-950 dark:text-white hover:text-primary dark:hover:text-primary-400 transition-colors py-2 @if($item->external) flex items-center gap-3 @else block @endif">
                        @if($item->external)
                            <x-ri-discord-fill class="w-7 h-7 shrink-0"/>
                            <span>{{ $item->label }}</span>
                            <x-ri-arrow-right-up-line class="w-5 h-5 shrink-0 text-gray-400 dark:text-gray-600"/>
                        @else
                            {{ $item->label }}
                        @endif
                    </a>
                @endif
            @endforeach
        </div>
    </div>

    {{-- Bottom CTA. With both accordions open the list scrolls under this block,
         so it carries a fade: without one the last link stops dead against the
         buttons and reads as clipped rather than scrollable. --}}
    <div class="relative px-8 pb-10 shrink-0">
        <div class="pointer-events-none absolute inset-x-0 -top-8 h-8 bg-gradient-to-t from-white dark:from-gray-950"
             aria-hidden="true"></div>
        <div class="grid grid-cols-2 gap-3">
            <x-marketing.button variant="secondary" href="{{ route('login') }}">
                Sign In
            </x-marketing.button>
            <x-marketing.button href="{{ route('register') }}">
                Start for free
            </x-marketing.button>
        </div>
    </div>
</nav>
