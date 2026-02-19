<section class="relative overflow-hidden bg-white dark:bg-black">
    <div class="container max-w-7xl mx-auto py-24 md:py-32 px-4 sm:px-6 lg:px-8 relative">

        {{-- Top transition dots pattern - adjusted to avoid overlap with corners --}}
        <div
            class="absolute top-0 left-32 right-32 md:left-48 md:right-48 lg:left-64 lg:right-64 h-16 md:h-24 overflow-hidden pointer-events-none">
            <svg width="100%" height="100%"
                 class="absolute inset-0 opacity-30 md:opacity-40 dark:opacity-20 dark:md:opacity-40">
                <pattern id="dots-top" x="0" y="0" width="20" height="20" patternUnits="userSpaceOnUse">
                    <circle cx="2" cy="2" r="1.2" fill="#6B7280"/>
                </pattern>
                <rect width="100%" height="100%" fill="url(#dots-top)"/>
            </svg>
            <div class="absolute inset-0 bg-gradient-to-b from-transparent to-white dark:to-black"></div>
        </div>

        {{-- Left corner dots pattern --}}
        <div
            class="absolute top-0 left-0 w-32 h-32 md:w-48 md:h-48 lg:w-64 lg:h-64 overflow-hidden pointer-events-none">
            <svg width="100%" height="100%"
                 class="absolute inset-0 opacity-40 md:opacity-50 lg:opacity-60 dark:opacity-30 dark:md:opacity-40 dark:lg:opacity-50">
                <pattern id="dots-left" x="0" y="0" width="20" height="20" patternUnits="userSpaceOnUse">
                    <circle cx="2" cy="2" r="1.2" fill="#6B7280"/>
                </pattern>
                <rect width="100%" height="100%" fill="url(#dots-left)"/>
            </svg>
            <div
                class="absolute inset-0 bg-gradient-to-br from-transparent via-white/50 to-white dark:via-black/50 dark:to-black"></div>
        </div>

        {{-- Right corner dots pattern - identical to left but mirrored gradient --}}
        <div
            class="absolute top-0 right-0 w-32 h-32 md:w-48 md:h-48 lg:w-64 lg:h-64 overflow-hidden pointer-events-none">
            <svg width="100%" height="100%"
                 class="absolute inset-0 opacity-40 md:opacity-50 lg:opacity-60 dark:opacity-30 dark:md:opacity-40 dark:lg:opacity-50">
                <pattern id="dots-right" x="0" y="0" width="20" height="20" patternUnits="userSpaceOnUse">
                    <circle cx="2" cy="2" r="1.2" fill="#6B7280"/>
                </pattern>
                <rect width="100%" height="100%" fill="url(#dots-right)"/>
            </svg>
            <div
                class="absolute inset-0 bg-gradient-to-bl from-transparent via-white/50 to-white dark:via-black/50 dark:to-black"></div>
        </div>

        <div class="relative max-w-xl mx-auto text-center">
            {{-- Main content --}}
            <h2 class="font-display text-3xl sm:text-4xl md:text-5xl font-bold text-black dark:text-white mb-4 tracking-tight leading-tight">
                Your CRM, Your Way
            </h2>
            <p class="text-base md:text-lg text-gray-600 dark:text-gray-400 mb-8 max-w-sm mx-auto leading-relaxed">
                Self-hosted. Open source. Full control, zero compromises.
            </p>

            {{-- Primary CTA with perfect design system styling --}}
            <div class="mb-6">
                <a href="{{ route('register') }}"
                   class="group relative inline-flex items-center justify-center gap-2.5 bg-primary hover:bg-primary-700 text-white px-8 py-3.5 rounded-lg text-base font-medium transition-all duration-300 shadow-sm hover:shadow-md transform active:translate-y-0">
                    <span>Start for free</span>
                    <x-heroicon-c-arrow-right
                        class="h-4 w-4 transition-transform duration-300 group-hover:translate-x-1"/>

                </a>
            </div>

            {{-- Trust indicators - super minimal --}}
            <div class="flex items-center justify-center gap-1 text-[13px] text-gray-600 dark:text-gray-400">
                <x-heroicon-o-check-circle class="h-3.5 w-3.5 text-green-500 dark:text-green-400 flex-shrink-0"/>
                <span>No credit card</span>
                <span class="mx-1.5 text-gray-400 dark:text-gray-600">•</span>
                <x-heroicon-o-check-circle class="h-3.5 w-3.5 text-green-500 dark:text-green-400 flex-shrink-0"/>
                <span>Deploy in 5 minutes</span>
            </div>
        </div>
    </div>
</section>
