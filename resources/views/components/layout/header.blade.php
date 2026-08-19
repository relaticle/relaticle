<header x-data="{ mobileMenu: false }" @resize.window="if (window.innerWidth >= 768) mobileMenu = false">
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
                                        :aria-expanded="openDropdown === slug"
                                        aria-controls="menu-{{ $slug }}"
                                        class="px-4 py-1.5 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white text-[13px] font-medium transition-colors flex items-center gap-1 cursor-pointer">
                                    {{ $item->label }}
                                    <x-ri-arrow-down-s-line class="w-3.5 h-3.5 transition-transform duration-150"
                                                             ::class="openDropdown === slug && 'rotate-180'"/>
                                </button>
                                <div x-show="openDropdown === slug"
                                     x-transition:enter="transition ease-out duration-150"
                                     x-transition:enter-start="opacity-0"
                                     x-transition:enter-end="opacity-100"
                                     x-transition:leave="transition ease-in duration-150"
                                     x-transition:leave-start="opacity-100"
                                     x-transition:leave-end="opacity-0"
                                     x-cloak
                                     id="menu-{{ $slug }}"
                                     class="absolute left-0 top-full mt-2 rounded-2xl border border-gray-200/70 dark:border-white/[0.07] bg-white dark:bg-gray-950 shadow-xl shadow-gray-950/[0.06] dark:shadow-black/40 p-2 @if($isTwoColumn) grid grid-cols-2 min-w-[34rem] divide-x divide-gray-100 dark:divide-white/[0.06] @else min-w-[20rem] @endif">
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

                    <button @click="mobileMenu = !mobileMenu"
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
