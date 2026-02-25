<!-- Relaticle Mobile Menu - Refined Minimal Design -->
<!-- Enhanced Backdrop -->
<div id="mobile-menu-backdrop"
     class="hidden fixed inset-0 bg-black/70 backdrop-blur-md h-screen z-40 transition-all duration-300 opacity-0 md:hidden"></div>

<!-- Mobile Menu Panel -->
<div id="mobile-menu"
     class="md:hidden hidden fixed inset-y-0 right-0 w-80 max-w-[90vw] h-screen bg-white/95 dark:bg-black/95 backdrop-blur-xl border-l border-gray-200/60 dark:border-gray-800/60 shadow-2xl z-50 transform translate-x-full transition-all duration-300 ease-out">

    <!-- Menu Container -->
    <div class="flex flex-col h-full">
        <!-- Refined Header -->
        <div class="flex items-center justify-between p-6 pb-4 flex-shrink-0 border-b border-gray-100/50 dark:border-gray-800/50">
            <a href="{{ url('/') }}" class="inline-flex w-fit" aria-label="Relaticle Home">
                <x-brand.logo-lockup size="md" class="text-black dark:text-white" />
            </a>
            <button id="mobile-menu-close"
                    class="group p-2.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-all duration-200 rounded-xl hover:bg-gray-100/60 dark:hover:bg-gray-800/60 active:scale-95 focus:outline-none focus:ring-2 focus:ring-primary/20"
                    aria-label="Close menu">
                <x-ri-close-line class="h-5 w-5 transition-transform duration-200 group-hover:rotate-90" />
            </button>
        </div>

        <!-- Content - Scrollable -->
        <div class="flex-1 overflow-y-auto min-h-0">
            <!-- Enhanced Navigation -->
            <nav class="p-6 pt-8 space-y-2">
                <a href="{{ url('/#features') }}"
                   class="mobile-menu-link group flex items-center px-5 py-4 text-gray-600 dark:text-gray-300 hover:text-black dark:hover:text-white rounded-2xl hover:bg-gray-50/80 dark:hover:bg-gray-900/50 transition-all duration-300 hover:scale-[1.02] active:scale-[0.98]">
                    <span class="font-medium text-base">Features</span>
                    <x-ri-arrow-right-s-line class="ml-auto h-4 w-4 opacity-0 group-hover:opacity-70 transition-all duration-300 transform group-hover:translate-x-1" />
                </a>

                <a href="{{ route('documentation.index') }}"
                   class="mobile-menu-link group flex items-center px-5 py-4 text-gray-600 dark:text-gray-300 hover:text-black dark:hover:text-white rounded-2xl hover:bg-gray-50/80 dark:hover:bg-gray-900/50 transition-all duration-300 hover:scale-[1.02] active:scale-[0.98] {{ Route::is('documentation.*') ? 'bg-primary/10 dark:bg-primary/20 text-primary dark:text-primary-300' : '' }}">
                    <span class="font-medium text-base">Documentation</span>
                    <x-ri-arrow-right-s-line class="ml-auto h-4 w-4 opacity-0 group-hover:opacity-70 transition-all duration-300 transform group-hover:translate-x-1" />
                </a>

                <a href="https://github.com/Relaticle/relaticle"
                   target="_blank"
                   rel="noopener"
                   class="mobile-menu-link group flex items-center px-5 py-4 text-gray-600 dark:text-gray-300 hover:text-black dark:hover:text-white rounded-2xl hover:bg-gray-50/80 dark:hover:bg-gray-900/50 transition-all duration-300 hover:scale-[1.02] active:scale-[0.98]">
                    <div class="flex items-center">
                        <span class="font-medium text-base">GitHub</span>
                        @if(isset($githubStars) && $githubStars > 0)
                            <span class="ml-2.5 text-xs bg-gray-200/80 dark:bg-gray-700/80 text-gray-600 dark:text-gray-400 px-2.5 py-1 rounded-full font-medium">{{ $formattedGithubStars }}</span>
                        @endif
                    </div>
                    <x-ri-external-link-line class="ml-auto h-4 w-4 opacity-50 group-hover:opacity-70 transition-all duration-300" />
                </a>

                <a href="{{ route('discord') }}"
                   target="_blank"
                   class="mobile-menu-link group flex items-center px-5 py-4 text-gray-600 dark:text-gray-300 hover:text-black dark:hover:text-white rounded-2xl hover:bg-gray-50/80 dark:hover:bg-gray-900/50 transition-all duration-300 hover:scale-[1.02] active:scale-[0.98]">
                    <span class="font-medium text-base">Discord</span>
                    <x-ri-external-link-line class="ml-auto h-4 w-4 opacity-50 group-hover:opacity-70 transition-all duration-300" />
                </a>
            </nav>

            <!-- Elegant Divider -->
            <div class="mx-6 my-6 border-t border-gray-200/60 dark:border-gray-800/60"></div>

            <!-- Enhanced Auth Section -->
            <div class="p-6 space-y-4">
                <a href="{{ route('login') }}"
                   class="mobile-menu-link group flex items-center justify-center px-5 py-4 text-gray-600 dark:text-gray-300 hover:text-black dark:hover:text-white rounded-2xl border border-gray-200/80 dark:border-gray-700/80 hover:border-gray-300 dark:hover:border-gray-600 hover:bg-gray-50/60 dark:hover:bg-gray-900/40 transition-all duration-300 hover:scale-[1.02] active:scale-[0.98] {{ Route::is('login') ? 'bg-gray-100 dark:bg-gray-900 border-gray-300 dark:border-gray-600 text-black dark:text-white' : '' }}">
                    <span class="font-medium text-base">Sign In</span>
                </a>

                <a href="{{ route('register') }}"
                   class="mobile-menu-link group relative overflow-hidden flex items-center justify-center px-5 py-4 bg-primary hover:bg-primary-600 text-white rounded-2xl font-semibold transition-all duration-300 hover:scale-[1.02] active:scale-[0.98] shadow-lg hover:shadow-xl focus:outline-none focus:ring-2 focus:ring-primary/30">
                    <span class="relative z-10">Start for free</span>
                    <div class="absolute inset-0 bg-gradient-to-r from-primary-600 to-primary-700 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                </a>
            </div>
        </div>

        <!-- Theme Switcher -->
        <div class="p-6 border-t border-gray-100/80 dark:border-gray-800/80 flex-shrink-0">
            <div class="flex items-center justify-between px-5 py-4">
                <span class="font-medium text-base text-gray-700 dark:text-gray-200">Theme</span>
                <div id="mobile-theme-switcher" class="inline-flex items-center rounded-full border border-gray-200 dark:border-gray-700 bg-gray-100 dark:bg-gray-800 p-0.5">
                    <button data-theme="system" aria-label="System theme"
                            class="theme-btn p-2 rounded-full transition-all duration-200 cursor-pointer">
                        <x-ri-computer-line class="h-4 w-4" />
                    </button>
                    <button data-theme="light" aria-label="Light theme"
                            class="theme-btn p-2 rounded-full transition-all duration-200 cursor-pointer">
                        <x-ri-sun-line class="h-4 w-4" />
                    </button>
                    <button data-theme="dark" aria-label="Dark theme"
                            class="theme-btn p-2 rounded-full transition-all duration-200 cursor-pointer">
                        <x-ri-moon-line class="h-4 w-4" />
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
