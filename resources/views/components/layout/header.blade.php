<!-- Minimalist Header -->
{{--@push('header')--}}
<script>
    // On page load or when changing themes, best to add inline in `head` to avoid FOUC
    if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
</script>
{{--@endpush--}}

<header
    class="bg-white dark:bg-black py-4 fixed w-full top-0 z-50 transition-all duration-300 border-b border-gray-100 dark:border-gray-900 backdrop-blur-sm bg-white/95 dark:bg-black/95">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Three-column layout: Logo | Nav (centered) | Actions -->
        <div class="flex items-center justify-between">
            <!-- Logo (Left Column) -->
            <div class="flex flex-1 items-center min-w-0">
                <a href="{{ url('/') }}" class="inline-flex w-fit" aria-label="Relaticle Home">
                    <x-brand.logo-lockup
                        size="md"
                        class="text-black dark:text-white"
                    />
                </a>
            </div>

            <!-- Desktop Navigation (Center Column) -->
            <div class="hidden md:flex flex-1 items-center justify-center">
                <nav class="flex items-center space-x-8">
                    <a href="{{ url('/#features') }}"
                       class="text-gray-700 dark:text-white hover:text-primary dark:hover:text-primary-400 text-sm font-medium transition-all duration-200 relative after:absolute after:bottom-0 after:left-0 after:right-0 after:h-0.5 after:w-0 after:bg-primary dark:after:bg-primary after:transition-all hover:after:w-full"
                       aria-label="Product features">Features</a>
                    <a href="{{ route('documentation.index') }}"
                       class="text-gray-700 dark:text-white hover:text-primary dark:hover:text-primary-400 text-sm font-medium transition-all duration-200 relative after:absolute after:bottom-0 after:left-0 after:right-0 after:h-0.5 after:w-0 after:bg-primary dark:after:bg-primary after:transition-all hover:after:w-full"
                       aria-label="Documentation">Documentation</a>
                    <a href="https://github.com/Relaticle/relaticle" target="_blank" rel="noopener"
                       class="text-gray-700 dark:text-white hover:text-primary dark:hover:text-primary-400 text-sm font-medium transition-all duration-200 flex items-center gap-1.5 relative after:absolute after:bottom-0 after:left-0 after:right-0 after:h-0.5 after:w-0 after:bg-primary dark:after:bg-primary after:transition-all hover:after:w-full"
                       aria-label="GitHub Repository">
                        <x-icon-github class="w-4 h-4"/>
                        @if(isset($githubStars) && $githubStars > 0)
                            <span>{{ $formattedGithubStars }}</span>
                        @endif
                        <x-heroicon-o-arrow-up-right class="h-3 w-3 text-gray-400"/>
                    </a>
                    <a href="{{ route('discord') }}" target="_blank"
                       class="text-gray-700 dark:text-white hover:text-primary dark:hover:text-primary-400 text-sm font-medium transition-all duration-200 flex items-center gap-1.5 relative after:absolute after:bottom-0 after:left-0 after:right-0 after:h-0.5 after:w-0 after:bg-primary dark:after:bg-primary after:transition-all hover:after:w-full"
                       aria-label="Join Discord Community">
                        <x-icon-discord class="w-4 h-4"/>
                        Discord
                        <x-heroicon-o-arrow-up-right class="h-3 w-3 text-gray-400"/>
                    </a>
                </nav>
            </div>

            <!-- Right Section: Auth and Settings (Right Column) -->
            <div class="flex flex-1 items-center justify-end space-x-5">
                <!-- Dark Mode Toggle -->
                <button id="theme-toggle"
                        class="p-2 text-gray-500 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 focus:outline-none focus:ring-2 focus:ring-primary/30 rounded-full transition-transform duration-300 active:scale-90"
                        aria-label="Toggle dark mode">
                    <x-heroicon-o-sun class="h-5 w-5 hidden dark:block"/>
                    <x-heroicon-o-moon class="h-5 w-5 block dark:hidden"/>
                </button>

                <!-- Auth Links -->
                <div class="hidden lg:flex items-center space-x-6">
                    <a href="{{ route('login') }}"
                       class="text-gray-700 dark:text-white hover:text-primary dark:hover:text-primary-400 text-sm font-medium transition-all duration-200 {{ Route::is('login') ? 'text-primary dark:text-primary-400' : '' }}"
                       aria-label="Sign in to your account">Sign In</a>

                    <a href="{{ route('register') }}"
                       class="group bg-primary hover:bg-primary-600 text-white px-5 py-2.5 text-sm rounded-md transition-all duration-300 font-medium relative overflow-hidden shadow-sm hover:shadow hover:scale-[1.02] active:scale-[0.98]"
                       aria-label="Create a new account">
                        <span class="relative z-10">Start for free</span>
                        <span
                            class="absolute inset-0 bg-gradient-to-r from-primary-600 to-primary-700 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></span>
                    </a>
                </div>

                <!-- Mobile Menu Button -->
                <div class="md:hidden">
                    <button id="mobile-menu-button"
                            class="p-2 text-gray-500 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 focus:outline-none focus:ring-2 focus:ring-primary/30 rounded-full transition-all active:scale-95"
                            aria-label="Toggle mobile menu"
                            aria-expanded="false">
                        <x-heroicon-o-bars-3 class="h-6 w-6"/>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Menu Component -->
    <x-layout.mobile-menu/>
</header>

<!-- Add a spacer to prevent content from hiding behind fixed header -->
<div class="h-[64px]"></div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Dark mode toggle functionality - simplified
        const themeToggleButton = document.getElementById('theme-toggle');

        // Function to toggle dark mode
        function toggleDarkMode() {
            const htmlElement = document.documentElement;
            if (htmlElement.classList.contains('dark')) {
                htmlElement.classList.remove('dark');
                localStorage.theme = 'light';
            } else {
                htmlElement.classList.add('dark');
                localStorage.theme = 'dark';
            }
        }

        // Initialize theme on page load
        document.documentElement.classList.toggle(
            "dark",
            localStorage.theme === "dark" ||
            (!("theme" in localStorage) && window.matchMedia("(prefers-color-scheme: dark)").matches)
        );

        // Add event listener to theme toggle button
        if (themeToggleButton) {
            themeToggleButton.addEventListener('click', toggleDarkMode);
        }

        // Mobile menu functionality
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');
        const mobileMenuBackdrop = document.getElementById('mobile-menu-backdrop');
        const mobileMenuClose = document.getElementById('mobile-menu-close');
        const mobileThemeToggle = document.getElementById('mobile-theme-toggle');
        const mobileMenuLinks = document.querySelectorAll('.mobile-menu-link');

        function openMobileMenu() {
            // Show elements
            mobileMenu.classList.remove('hidden');
            mobileMenuBackdrop.classList.remove('hidden');

            // Prevent body scroll
            document.body.classList.add('overflow-hidden');

            // Trigger animations
            setTimeout(() => {
                mobileMenu.classList.remove('translate-x-full');
                mobileMenuBackdrop.classList.remove('opacity-0');
            }, 10);

            mobileMenuButton.setAttribute('aria-expanded', 'true');
        }

        function closeMobileMenu() {
            // Hide with animations
            mobileMenu.classList.add('translate-x-full');
            mobileMenuBackdrop.classList.add('opacity-0');

            // Re-enable body scroll
            document.body.classList.remove('overflow-hidden');

            // Hide elements after animation
            setTimeout(() => {
                mobileMenu.classList.add('hidden');
                mobileMenuBackdrop.classList.add('hidden');
            }, 300);

            mobileMenuButton.setAttribute('aria-expanded', 'false');
        }

        // Event listeners
        if (mobileMenuButton) {
            mobileMenuButton.addEventListener('click', openMobileMenu);
        }

        if (mobileMenuClose) {
            mobileMenuClose.addEventListener('click', closeMobileMenu);
        }

        if (mobileMenuBackdrop) {
            mobileMenuBackdrop.addEventListener('click', closeMobileMenu);
        }

        if (mobileThemeToggle) {
            mobileThemeToggle.addEventListener('click', toggleDarkMode);
        }

        // Close menu when clicking links
        mobileMenuLinks.forEach(link => {
            link.addEventListener('click', closeMobileMenu);
        });

        // Close menu on escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && mobileMenuButton.getAttribute('aria-expanded') === 'true') {
                closeMobileMenu();
            }
        });

        // Dynamic header scrolling effect
        const header = document.querySelector('header');
        window.addEventListener('scroll', function () {
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop;

            if (scrollTop > 20) {
                header.classList.add('py-2', 'shadow-sm');
                header.classList.remove('py-4');
            } else {
                header.classList.add('py-4');
                header.classList.remove('py-2', 'shadow-sm');
            }
        });

        // Check for user preference for reduced motion
        const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (prefersReducedMotion) {
            document.documentElement.classList.add('reduce-motion');
        }
    });
</script>

<style>
    /* Adding styles for users who prefer reduced motion */
    .reduce-motion * {
        transition-duration: 0.05s !important;
        animation-duration: 0.05s !important;
    }
</style>
