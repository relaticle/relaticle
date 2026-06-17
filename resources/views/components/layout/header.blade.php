<div x-data="{ mobileMenu: false }" @resize.window="if (window.innerWidth >= 768) mobileMenu = false">
    <header
        id="main-header"
        class="fixed w-full top-0 z-50 bg-white/80 dark:bg-gray-950/90 backdrop-blur-md border-b border-gray-200/60 dark:border-white/[0.06]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">

                <div class="flex flex-1 items-center">
                    <a href="{{ url('/') }}" class="transition-opacity" aria-label="Relaticle Home">
                        <x-brand.logo-lockup size="md" class="text-black dark:text-white" />
                    </a>
                </div>

                <nav class="hidden md:flex items-center gap-1">
                    <a href="{{ url('/#features') }}"
                       class="px-4 py-1.5 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white text-[13px] font-medium transition-colors">
                        Features
                    </a>
                    <a href="{{ route('pricing') }}"
                       class="px-4 py-1.5 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white text-[13px] font-medium transition-colors">
                        Pricing
                    </a>
                    @feature(App\Features\Documentation::class)
                    <a href="{{ route('documentation.index') }}"
                       class="px-4 py-1.5 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white text-[13px] font-medium transition-colors">
                        Documentation
                    </a>
                    @endfeature
                    <a href="{{ route('blog.index') }}"
                       class="px-4 py-1.5 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white text-[13px] font-medium transition-colors">
                        Blog
                    </a>
                    <a href="{{ route('discord') }}" target="_blank"
                       class="px-4 py-1.5 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white text-[13px] font-medium transition-colors flex items-center gap-1.5">
                        <x-ri-discord-fill class="w-4 h-4"/>
                        <span>Discord</span>
                        <x-ri-arrow-right-up-line class="h-3 w-3 text-gray-400 dark:text-gray-600"/>
                    </a>
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
    </header>

    <x-layout.mobile-menu/>
</div>
