{{-- Full-screen mobile menu --}}
<nav x-show="mobileMenu"
     x-ref="overlay"
     x-transition.opacity.duration.150ms
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
        {{-- Same three-line construct as the header's hamburger, frozen in its
             end state and sitting at the same screen position — the overlay
             fading in over the mid-morph hamburger reads as one continuous
             motion. Keep the box geometry in sync with that button. --}}
        <button type="button" @click="mobileMenu = false"
                x-ref="close"
                x-effect="if (mobileMenu) $nextTick(() => $refs.close.focus())"
                class="p-2.5 -mr-1 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white active:opacity-60 rounded-lg transition-[color,opacity] duration-200 cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                aria-label="Close menu">
            <span class="relative block h-5 w-5" aria-hidden="true">
                <span class="absolute left-0 top-[3px] h-[2px] w-5 rounded-full bg-current translate-y-[6px] rotate-45"></span>
                <span class="absolute left-0 top-[15px] h-[2px] w-5 rounded-full bg-current -translate-y-[6px] -rotate-45"></span>
            </span>
        </button>
    </div>

    {{-- Navigation --}}
    @php($mobileNavItems = app(\App\Support\MarketingNavigation::class)->mobile())

    {{-- Top-anchored on purpose: a vertically centered (`my-auto`) list
         re-centers on every frame of an accordion's height animation, so a
         collapse + expand makes the whole menu wobble. Anchored to the top,
         height changes only push content downward. --}}
    <div class="flex-1 flex flex-col px-8 overflow-y-auto pt-8 pb-6">
        <div class="space-y-1">
            @foreach($mobileNavItems as $item)
                @if($item->url === null && count($item->children) > 0)
                    @php($slug = \Illuminate\Support\Str::slug($item->label))
                    <div class="py-2 mobile-nav-enter" style="--stagger: {{ $loop->index }}">
                        <button type="button" @click="mobileExpanded = mobileExpanded === '{{ $slug }}' ? null : '{{ $slug }}'"
                                :aria-expanded="(mobileExpanded === '{{ $slug }}').toString()"
                                aria-controls="mobile-menu-{{ $slug }}"
                                class="w-full flex items-center justify-between text-[2rem] leading-tight font-semibold text-gray-950 dark:text-white hover:text-primary dark:hover:text-primary-400 active:opacity-60 transition-[color,opacity] duration-200 cursor-pointer">
                            <span>{{ $item->label }}</span>
                            <x-ri-arrow-down-s-line class="w-7 h-7 shrink-0 text-gray-400 dark:text-gray-500 transition-transform duration-200"
                                                     ::class="mobileExpanded === '{{ $slug }}' && 'rotate-180'"/>
                        </button>
                        <div id="mobile-menu-{{ $slug }}" x-show="mobileExpanded === '{{ $slug }}'" x-collapse x-cloak>
                            {{-- Padding lives on this inner wrapper, not the collapsing element:
                                 border-box height cannot animate below its own padding, so a
                                 padded collapse stalls at the padding height and then pops. --}}
                            <div class="pl-4 pt-1 pb-3 space-y-0.5">
                            @foreach($item->children as $child)
                                @if($child->url === null && count($child->children) > 0)
                                    <p class="pt-4 pb-1.5 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                        {{ $child->label }}
                                    </p>
                                    @foreach($child->children as $grandchild)
                                        <x-layout.mobile-menu-link :item="$grandchild"/>
                                    @endforeach
                                @else
                                    <x-layout.mobile-menu-link :item="$child"/>
                                @endif
                            @endforeach
                            </div>
                        </div>
                    </div>
                @else
                    <a href="{{ $item->url }}" @click="mobileMenu = false"
                       @if($item->external) target="_blank" rel="noopener noreferrer" @endif
                       @if(url()->current() === $item->url) aria-current="page" @endif
                       class="mobile-nav-enter text-[2rem] leading-tight font-semibold text-gray-950 dark:text-white hover:text-primary dark:hover:text-primary-400 active:opacity-60 transition-[color,opacity] duration-200 py-2 @if($item->external) flex items-center gap-3 @else block @endif"
                       style="--stagger: {{ $loop->index }}">
                        @if($item->external)
                            <x-ri-discord-fill class="w-7 h-7 shrink-0"/>
                            <span>{{ $item->label }}</span>
                            <x-ri-arrow-right-up-line class="w-5 h-5 shrink-0 text-gray-400 dark:text-gray-500"/>
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
    <div class="relative px-8 pb-[max(2.5rem,env(safe-area-inset-bottom))] shrink-0">
        <div class="pointer-events-none absolute inset-x-0 -top-8 h-8 bg-gradient-to-t from-white dark:from-gray-950"
             aria-hidden="true"></div>
        <div class="grid grid-cols-2 gap-3 mobile-nav-enter" style="--stagger: {{ count($mobileNavItems) }}">
            <x-marketing.button variant="secondary" href="{{ route('login') }}" class="active:scale-[0.98]">
                Sign In
            </x-marketing.button>
            <x-marketing.button href="{{ route('login') }}" class="active:scale-[0.98]">
                Start for free
            </x-marketing.button>
        </div>
    </div>
</nav>
