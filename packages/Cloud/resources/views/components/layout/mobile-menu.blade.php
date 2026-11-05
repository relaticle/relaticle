<div x-show="mobileMenu" x-transition.opacity.duration.150ms @click="mobileMenu = false" class="fixed inset-0 z-[59] md:hidden" x-cloak aria-hidden="true"></div>

{{-- A card under the floating header --}}
<nav x-show="mobileMenu"
     x-ref="overlay"
     x-transition.opacity.duration.150ms
     @keydown.escape.window="mobileMenu = false"
     {{-- Both bindings are required: Alpine skips a key handler when a system
          modifier is held that the expression did not list, so `.tab` alone never
          sees Shift+Tab and focus escapes backwards out of the overlay. --}}
     @keydown.tab="trapTab($event)"
     @keydown.shift.tab="trapTab($event)"
     class="md:hidden fixed z-[60] bg-white dark:bg-gray-950 flex flex-col inset-x-2 top-2 max-h-[calc(100dvh-1rem)] overflow-hidden rounded-2xl shadow-navbar"
     x-cloak
     aria-label="{{ __('Mobile menu') }}">

    {{-- Header --}}
    <div class="flex items-center justify-between h-16 shrink-0 px-2">
        <a href="{{ url('/') }}" aria-label="Relaticle Home">
            <x-brand.logo-lockup size="md" class="text-black dark:text-white"/>
        </a>
        {{-- Focus lands here when the overlay opens, so the trap has somewhere to
             start and a keyboard user's first Tab walks the menu, not the page. --}}
        {{-- Same three-line construct as the header's hamburger, frozen in its
             end state and sitting at the same screen position. The overlay
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
    @php($mobileNavItems = app(\Relaticle\Cloud\Marketing\Support\MarketingNavigation::class)->mobile())

    {{-- Top-anchored on purpose: a vertically centered (`my-auto`) list
         re-centers on every frame of an accordion's height animation, so a
         collapse + expand makes the whole menu wobble. Anchored to the top,
         height changes only push content downward. --}}
    <div class="flex-1 flex flex-col overflow-y-auto">
        <div>
            @foreach($mobileNavItems as $item)
                @if($item->url === null && count($item->children) > 0)
                    @php($slug = \Illuminate\Support\Str::slug($item->label))
                    <div class="mobile-nav-enter border-t border-gray-100 dark:border-white/[0.06]" style="--stagger: {{ $loop->index }}">
                        <button type="button" @click="mobileExpanded = mobileExpanded === '{{ $slug }}' ? null : '{{ $slug }}'"
                                :aria-expanded="(mobileExpanded === '{{ $slug }}').toString()"
                                aria-controls="mobile-menu-{{ $slug }}"
                                class="w-full flex items-center justify-between active:opacity-60 transition-[color,opacity] duration-200 cursor-pointer px-4 py-3.5 text-lg font-medium text-gray-600 hover:text-gray-950 dark:text-gray-300 dark:hover:text-white">
                            <span>{{ $item->label }}</span>
                            <x-ri-arrow-down-s-line class="h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500 transition-transform duration-200"
                                                     ::class="mobileExpanded === '{{ $slug }}' && 'rotate-180'"/>
                        </button>
                        <div id="mobile-menu-{{ $slug }}" x-show="mobileExpanded === '{{ $slug }}'" x-collapse x-cloak>
                            {{-- Padding lives on this inner wrapper, not the collapsing element:
                                 border-box height cannot animate below its own padding, so a
                                 padded collapse stalls at the padding height and then pops. --}}
                            <div class="pb-3 space-y-0.5 px-4">
                            @foreach($item->children as $child)
                                @if($child->url === null && count($child->children) > 0)
                                    <x-cloud::marketing.eyebrow class="pt-4 pb-1.5">{{ $child->label }}</x-cloud::marketing.eyebrow>
                                    @foreach($child->children as $grandchild)
                                        <x-cloud::layout.mobile-menu-link :item="$grandchild"/>
                                    @endforeach
                                @else
                                    <x-cloud::layout.mobile-menu-link :item="$child"/>
                                @endif
                            @endforeach
                            </div>
                        </div>
                    </div>
                @else
                    <a href="{{ $item->url }}" @click="mobileMenu = false"
                       @if($item->external) target="_blank" rel="noopener noreferrer" @endif
                       @if(url()->current() === $item->url) aria-current="page" @endif
                       class="mobile-nav-enter block active:opacity-60 transition-[color,opacity] duration-200 border-t border-gray-100 px-4 py-3.5 text-lg font-medium text-gray-600 hover:text-gray-950 dark:border-white/[0.06] dark:text-gray-300 dark:hover:text-white"
                       style="--stagger: {{ $loop->index }}">
                        {{ $item->label }}
                    </a>
                @endif
            @endforeach
        </div>
    </div>

    {{-- Bottom CTA. With both accordions open the list scrolls under this block,
         so it carries a fade: without one the last link stops dead against the
         buttons and reads as clipped rather than scrollable. --}}
    <div class="relative shrink-0 border-t border-gray-100 p-4 dark:border-white/[0.06]">
        <x-cloud::layout.community-links class="mobile-nav-enter mb-4 flex w-fit" style="--stagger: {{ count($mobileNavItems) }}"/>

        @auth('web')
            <div class="grid mobile-nav-enter" style="--stagger: {{ count($mobileNavItems) }}">
                <x-cloud::marketing.button :href="app(\App\Support\Auth\LoginDestination::class)->resolve(auth('web')->user(), null)" class="active:scale-[0.98]">
                    {{ __('Open app') }}
                </x-cloud::marketing.button>
            </div>
        @else
            <div class="grid grid-cols-2 gap-3 mobile-nav-enter" style="--stagger: {{ count($mobileNavItems) }}">
                <x-cloud::marketing.button variant="secondary" href="{{ route('login') }}" class="active:scale-[0.98]" data-analytics-intent="login" data-analytics-placement="mobile">
                    Sign In
                </x-cloud::marketing.button>
                <x-cloud::marketing.button href="{{ route('login') }}" class="active:scale-[0.98]" data-analytics-intent="signup" data-analytics-placement="mobile">
                    Start for free
                </x-cloud::marketing.button>
            </div>
        @endauth
    </div>
</nav>
