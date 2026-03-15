<header
    id="main-header"
    class="fixed w-full top-0 z-50 bg-transparent dark:bg-black/95 dark:backdrop-blur-md"
    style="border-bottom: 1px solid transparent; transition: background 0.4s ease, border-bottom 0.4s ease, backdrop-filter 0.4s ease;">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16 md:h-20" id="header-container">

            <div class="flex flex-1 items-center">
                <a href="{{ url('/') }}" class="transition-opacity" aria-label="Relaticle Home">
                    <x-brand.logo-lockup size="md" class="text-black dark:text-white" />
                </a>
            </div>

            <div id="nav-pill"
                 class="hidden md:flex items-center rounded-full px-1.5 py-1"
                 style="gap: 2px; background: transparent; border: 1px solid transparent; box-shadow: none; transition: background 0.4s ease, border 0.4s ease, box-shadow 0.4s ease, gap 0.4s ease;">
                <nav class="flex items-center" id="nav-links" style="gap: 0px; transition: gap 0.4s ease;">
                    <a href="{{ url('/#features') }}"
                       class="px-4 py-1.5 text-gray-700 dark:text-gray-300 hover:text-primary dark:hover:text-primary-400 text-[13px] font-medium transition-colors rounded-full">
                        Features
                    </a>
                    <a href="{{ route('documentation.index') }}"
                       class="px-4 py-1.5 text-gray-700 dark:text-gray-300 hover:text-primary dark:hover:text-primary-400 text-[13px] font-medium transition-colors rounded-full">
                        Documentation
                    </a>

                    <a href="https://github.com/Relaticle/relaticle" target="_blank"
                       class="px-4 py-1.5 text-gray-700 dark:text-gray-300 hover:text-primary dark:hover:text-primary-400 text-[13px] font-medium transition-colors rounded-full flex items-center gap-2">
                        <x-ri-github-fill class="w-4 h-4"/>
                        @if(isset($githubStars) && $githubStars > 0)
                            <span class="opacity-80">{{ $formattedGithubStars }}</span>
                        @else
                            <span>GitHub</span>
                        @endif
                    </a>

                    <a href="{{ route('discord') }}" target="_blank"
                       class="px-4 py-1.5 text-gray-700 dark:text-gray-300 hover:text-primary dark:hover:text-primary-400 text-[13px] font-medium transition-colors rounded-full flex items-center gap-1.5">
                        <x-ri-discord-line class="w-4 h-4"/>
                        <span>Discord</span>
                    </a>
                </nav>
            </div>

            <div class="flex flex-1 items-center justify-end gap-2 sm:gap-3">

                <a href="{{ route('login') }}"
                   class="hidden sm:flex h-9 items-center rounded-lg border border-gray-200
              bg-white px-5 text-sm font-medium
              transition-all duration-100
              hover:bg-gray-50/50
              dark:border-white/10 dark:bg-black dark:text-white dark:hover:bg-neutral-900">
                    Sign In
                </a>

                <a href="{{ route('register') }}"
                   class="flex h-8 sm:h-9 items-center rounded-lg border border-primary
              bg-primary px-3.5 sm:px-5 text-xs sm:text-sm font-medium text-white
              transition-all duration-100
              hover:bg-primary/95 hover:shadow-sm hover:ring-2 hover:ring-primary/10">
                    Start for free
                </a>

            </div>
        </div>
    </div>
</header>

<div class="h-16 md:h-20"></div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const header = document.getElementById('main-header');
        const container = document.getElementById('header-container');
        const navPill = document.getElementById('nav-pill');
        const navLinks = document.getElementById('nav-links');
        const isDark = () => document.documentElement.classList.contains('dark');

        let scrolled = false;

        function updateHeader() {
            const isScrolled = window.scrollY > 20;

            if (isScrolled === scrolled) return;
            scrolled = isScrolled;

            if (isScrolled) {
                // Scrolled state — bordered pill, frosted header
                header.style.background = isDark() ? 'rgba(0,0,0,0.85)' : 'rgba(255,255,255,0.85)';
                header.style.backdropFilter = 'blur(16px)';
                header.style.webkitBackdropFilter = 'blur(16px)';
                header.style.borderBottom = isDark() ? '1px solid rgba(255,255,255,0.05)' : '1px solid rgba(0,0,0,0.04)';

                navPill.style.background = isDark() ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.03)';
                navPill.style.border = isDark() ? '1px solid rgba(255,255,255,0.08)' : '1px solid rgba(0,0,0,0.06)';
                navPill.style.boxShadow = '0 1px 2px rgba(0,0,0,0.04)';
                navPill.style.gap = '4px';
                navLinks.style.gap = '4px';
            } else {
                // Default state — transparent in light, solid in dark
                header.style.background = isDark() ? 'rgba(0,0,0,0.95)' : 'transparent';
                header.style.backdropFilter = isDark() ? 'blur(16px)' : 'none';
                header.style.webkitBackdropFilter = isDark() ? 'blur(16px)' : 'none';
                header.style.borderBottom = isDark() ? '1px solid rgba(255,255,255,0.05)' : '1px solid transparent';

                navPill.style.background = 'transparent';
                navPill.style.border = '1px solid transparent';
                navPill.style.boxShadow = 'none';
                navPill.style.gap = '2px';
                navLinks.style.gap = '0px';
            }
        }

        // Watch for dark mode changes
        const darkObserver = new MutationObserver(() => {
            scrolled = !scrolled; // force re-apply
            updateHeader();
        });
        darkObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

        window.addEventListener('scroll', updateHeader, { passive: true });
        updateHeader();
    });
</script>
