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
                <svg class="h-5 w-5 transition-transform duration-200 group-hover:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <!-- Content - Scrollable -->
        <div class="flex-1 overflow-y-auto min-h-0">
            <!-- Enhanced Navigation -->
            <nav class="p-6 pt-8 space-y-2">
                <a href="{{ url('/#features') }}"
                   class="mobile-menu-link group flex items-center px-5 py-4 text-gray-600 dark:text-gray-300 hover:text-black dark:hover:text-white rounded-2xl hover:bg-gray-50/80 dark:hover:bg-gray-900/50 transition-all duration-300 hover:scale-[1.02] active:scale-[0.98]">
                    <span class="font-medium text-base">Features</span>
                    <svg class="ml-auto h-4 w-4 opacity-0 group-hover:opacity-70 transition-all duration-300 transform group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                </a>

                <a href="{{ route('documentation.index') }}"
                   class="mobile-menu-link group flex items-center px-5 py-4 text-gray-600 dark:text-gray-300 hover:text-black dark:hover:text-white rounded-2xl hover:bg-gray-50/80 dark:hover:bg-gray-900/50 transition-all duration-300 hover:scale-[1.02] active:scale-[0.98] {{ Route::is('documentation.*') ? 'bg-primary/10 dark:bg-primary/20 text-primary dark:text-primary-300' : '' }}">
                    <span class="font-medium text-base">Documentation</span>
                    <svg class="ml-auto h-4 w-4 opacity-0 group-hover:opacity-70 transition-all duration-300 transform group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
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
                    <svg class="ml-auto h-4 w-4 opacity-50 group-hover:opacity-70 transition-all duration-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                    </svg>
                </a>

                <a href="{{ route('discord') }}"
                   target="_blank"
                   class="mobile-menu-link group flex items-center px-5 py-4 text-gray-600 dark:text-gray-300 hover:text-black dark:hover:text-white rounded-2xl hover:bg-gray-50/80 dark:hover:bg-gray-900/50 transition-all duration-300 hover:scale-[1.02] active:scale-[0.98]">
                    <span class="font-medium text-base">Discord</span>
                    <svg class="ml-auto h-4 w-4 opacity-50 group-hover:opacity-70 transition-all duration-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                    </svg>
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

        <!-- Refined Footer -->
        <div class="p-6 border-t border-gray-100/80 dark:border-gray-800/80 flex-shrink-0">
            <button id="mobile-theme-toggle"
                    class="group w-full flex items-center justify-between px-5 py-4 text-gray-700 dark:text-gray-200 hover:text-black dark:hover:text-white rounded-2xl hover:bg-gray-50/60 dark:hover:bg-gray-900/40 transition-all duration-300 focus:outline-none focus:ring-2 focus:ring-primary/20"
                    aria-label="Toggle dark mode">
                <div class="flex items-center space-x-3">
                    <svg class="h-5 w-5 text-gray-500 dark:text-gray-400 group-hover:text-gray-700 dark:group-hover:text-gray-200 transition-colors duration-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" class="block dark:hidden" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" class="hidden dark:block" />
                    </svg>
                    <span class="font-medium text-base">Theme</span>
                </div>
                <div class="relative">
                    <div class="w-12 h-6 bg-gray-200 dark:bg-gray-700 rounded-full transition-colors duration-300 shadow-inner"></div>
                    <div class="absolute left-0.5 top-0.5 bg-white w-5 h-5 rounded-full shadow-md transform dark:translate-x-6 transition-transform duration-300 group-hover:shadow-lg"></div>
                </div>
            </button>
        </div>
    </div>
</div>
