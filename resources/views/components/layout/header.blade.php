{{-- The mobile overlay is `fixed inset-0`, which does not stop the page behind it
     from scrolling, and does not stop Tab from walking into the page behind it
     either. `bodyLock` handles the first, `trapTab` the second.

     The focusable set is queried on every keypress rather than cached: the
     accordions add and remove links as they open, so a set captured on open goes
     stale the moment someone expands Product. Hand-rolled because @alpinejs/focus
     is not a dependency of this project. --}}
<header x-data="{
            mobileMenu: false,
            bodyLock() { document.body.style.overflow = this.mobileMenu ? 'hidden' : '' },
            focusables() {
                return [...$refs.overlay.querySelectorAll('a[href], button:not([disabled])')]
                    .filter((el) => el.checkVisibility())
            },
            trapTab(event) {
                const items = this.focusables()

                if (items.length === 0) {
                    return
                }

                const edge = event.shiftKey ? items[0] : items[items.length - 1]

                if (document.activeElement !== edge) {
                    return
                }

                event.preventDefault()
                ;(event.shiftKey ? items[items.length - 1] : items[0]).focus()
            },
        }"
        x-effect="bodyLock()"
        x-init="$watch('mobileMenu', (open) => open || $refs.hamburger.focus())"
        @resize.window="if (window.innerWidth >= 768) mobileMenu = false">
    <div
        id="main-header"
        class="fixed w-full top-0 z-50 bg-white/80 dark:bg-gray-950/90 backdrop-blur-md border-b border-gray-200/60 dark:border-white/[0.06]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">

                <div class="flex flex-1 items-center">
                    <a href="{{ url('/') }}" class="transition-opacity" aria-label="Relaticle Home">
                        <x-brand.logo-lockup size="md" class="text-black dark:text-white" />
                    </a>
                </div>

                @php($navItems = app(\App\Support\MarketingNavigation::class)->header())

                <nav aria-label="{{ __('Main') }}" class="hidden md:flex items-center gap-1"
                     x-data="{ openDropdown: null }"
                     @keydown.escape.window="openDropdown = null"
                     @click.outside="openDropdown = null">
                    @foreach($navItems as $item)
                        @if($item->url === null && count($item->children) > 0)
                            @php($slug = \Illuminate\Support\Str::slug($item->label))
                            @php($isTwoColumn = collect($item->children)->contains(fn ($child) => $child->url === null && count($child->children) > 0))
                            <div class="relative" x-data="{ slug: '{{ $slug }}' }"
                                 @keydown.escape="if (openDropdown === slug) { openDropdown = null; $refs.trigger.focus(); }">
                                <button type="button" x-ref="trigger"
                                        @click="openDropdown = openDropdown === slug ? null : slug"
                                        aria-haspopup="true"
                                        :aria-expanded="openDropdown === slug"
                                        aria-controls="menu-{{ $slug }}"
                                        class="px-4 py-1.5 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white text-[13px] font-medium transition-colors flex items-center gap-1 cursor-pointer"
                                        :class="openDropdown === slug && 'text-gray-900 dark:text-white'">
                                    {{ $item->label }}
                                    <x-ri-arrow-down-s-line class="w-3.5 h-3.5 transition-transform duration-150"
                                                             ::class="openDropdown === slug && 'rotate-180'"/>
                                </button>
                                {{-- Enter rides --ease-out-expo so the panel settles rather than
                                     stops; leave is short and linear so a second trigger opens
                                     without waiting on the first panel. --}}
                                <div x-show="openDropdown === slug"
                                     x-transition:enter="transition duration-200 ease-[var(--ease-out-expo)] motion-reduce:transition-none"
                                     x-transition:enter-start="opacity-0 -translate-y-1 scale-[0.98]"
                                     x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                                     x-transition:leave="transition duration-100 ease-in motion-reduce:transition-none"
                                     x-transition:leave-start="opacity-100 scale-100"
                                     x-transition:leave-end="opacity-0 scale-[0.98]"
                                     x-cloak
                                     id="menu-{{ $slug }}"
                                     class="absolute top-full mt-2 origin-top rounded-2xl border border-gray-200/70 dark:border-white/[0.08] bg-white dark:bg-gray-900 shadow-xl shadow-gray-950/[0.08] dark:shadow-black/50 p-2 @if($isTwoColumn) left-1/2 -translate-x-1/2 grid grid-cols-[1.35fr_1fr] w-[min(40rem,calc(100vw-2rem))] divide-x divide-gray-100 dark:divide-white/[0.06] @else left-0 w-[24rem] @endif">
                                    @foreach($item->children as $child)
                                        @if($child->url === null && count($child->children) > 0)
                                            <div class="px-2 first:pl-0 last:pr-0">
                                                <p class="px-3 pt-1 pb-2 text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                                    {{ $child->label }}
                                                </p>
                                                <div class="space-y-0.5">
                                                    @foreach($child->children as $grandchild)
                                                        <x-layout.mega-menu-link :item="$grandchild"/>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @else
                                            <x-layout.mega-menu-link :item="$child"/>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <a href="{{ $item->url }}"
                               @if($item->external) target="_blank" rel="noopener noreferrer" @endif
                               @if(url()->current() === $item->url) aria-current="page" @endif
                               class="px-4 py-1.5 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white text-[13px] font-medium transition-colors @if($item->external) flex items-center gap-1.5 @endif">
                                @if($item->external)
                                    <x-ri-discord-fill class="w-4 h-4"/>
                                    <span>{{ $item->label }}</span>
                                    <x-ri-arrow-right-up-line class="h-3 w-3 text-gray-400 dark:text-gray-600"/>
                                @else
                                    {{ $item->label }}
                                @endif
                            </a>
                        @endif
                    @endforeach
                </nav>

                <div class="flex flex-1 items-center justify-end gap-2 sm:gap-3">
                    <div class="hidden md:flex items-center gap-2">
                        <x-marketing.button variant="secondary" size="sm" href="{{ route('login') }}">
                            Sign In
                        </x-marketing.button>

                        <x-marketing.button size="sm" href="{{ route('register') }}">
                            Start for free
                        </x-marketing.button>
                    </div>

                    {{-- Closing returns focus here, so dismissing the overlay puts a
                         keyboard user back on the control they opened it with. Driven by
                         the watcher in x-init, not a second escape listener: the overlay
                         already has one on `window`, and two handlers on one event race. --}}
                    <button @click="mobileMenu = !mobileMenu"
                            x-ref="hamburger"
                            class="md:hidden p-2 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white rounded-lg transition-colors cursor-pointer"
                            :aria-expanded="mobileMenu"
                            :aria-label="mobileMenu ? 'Close menu' : 'Open menu'">
                        <svg class="w-5 h-5 transition-transform duration-200" :class="mobileMenu && 'rotate-90'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path x-show="!mobileMenu" stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                            <path x-show="mobileMenu" stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <x-layout.mobile-menu/>
</header>
