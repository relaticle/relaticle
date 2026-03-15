<section class="relative overflow-hidden bg-white dark:bg-black">
    <div class="container max-w-7xl mx-auto py-24 md:py-32 px-4 sm:px-6 lg:px-8 relative">

        {{-- Corner & top dots patterns --}}
        <div class="absolute top-0 left-32 right-32 md:left-48 md:right-48 lg:left-64 lg:right-64 h-16 md:h-24 overflow-hidden pointer-events-none">
            <svg width="100%" height="100%" class="absolute inset-0 opacity-30 md:opacity-40 dark:opacity-20 dark:md:opacity-40">
                <pattern id="dots-top" x="0" y="0" width="20" height="20" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1.2" fill="#6B7280"/></pattern>
                <rect width="100%" height="100%" fill="url(#dots-top)"/>
            </svg>
            <div class="absolute inset-0 bg-gradient-to-b from-transparent to-white dark:to-black"></div>
        </div>
        @foreach([['left', 'br'], ['right', 'bl']] as [$side, $dir])
            <div class="absolute top-0 {{ $side }}-0 w-32 h-32 md:w-48 md:h-48 lg:w-64 lg:h-64 overflow-hidden pointer-events-none">
                <svg width="100%" height="100%" class="absolute inset-0 opacity-40 md:opacity-50 lg:opacity-60 dark:opacity-30 dark:md:opacity-40 dark:lg:opacity-50">
                    <pattern id="dots-{{ $side }}" x="0" y="0" width="20" height="20" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1.2" fill="#6B7280"/></pattern>
                    <rect width="100%" height="100%" fill="url(#dots-{{ $side }})"/>
                </svg>
                <div class="absolute inset-0 bg-gradient-to-{{ $dir }} from-transparent via-white/50 to-white dark:via-black/50 dark:to-black"></div>
            </div>
        @endforeach

        <div class="relative max-w-xl mx-auto text-center">
            <h2 class="font-display text-3xl sm:text-4xl md:text-[2.75rem] font-bold text-gray-950 dark:text-white tracking-[-0.02em] leading-[1.15] mb-5">
                Your CRM, Your Rules
            </h2>
            <p class="text-base md:text-lg text-gray-500 dark:text-gray-400 mb-8 max-w-sm mx-auto leading-relaxed">
                Self-hosted. Agent-native. Full control over your data and your AI.
            </p>

            <div class="flex flex-col sm:flex-row items-center justify-center gap-3 mb-6">
                <a href="{{ route('register') }}"
                   class="group relative flex h-11 items-center gap-2.5 rounded-xl bg-primary px-7 text-sm font-semibold text-white shadow-[0_1px_2px_rgba(0,0,0,0.1),inset_0_1px_0_rgba(255,255,255,0.12)] hover:shadow-[0_4px_16px_var(--color-primary-500)/25] hover:brightness-110 transition-all duration-300">
                    <span>Start for free</span>
                    <x-ri-arrow-right-line class="h-3.5 w-3.5 transition-transform duration-300 group-hover:translate-x-0.5"/>
                </a>
                <a href="https://github.com/relaticle/relaticle" target="_blank"
                   class="group flex h-11 items-center gap-2.5 rounded-xl border border-gray-200 bg-white px-7 text-sm font-semibold text-gray-700 shadow-[0_1px_2px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] hover:border-gray-300 transition-all duration-300 dark:border-white/[0.08] dark:bg-white/[0.03] dark:text-white dark:hover:bg-white/[0.06] dark:hover:border-white/[0.15]">
                    <x-ri-github-fill class="h-4 w-4"/>
                    <span>GitHub</span>
                </a>
            </div>

            <div class="flex items-center justify-center gap-1 text-[13px] text-gray-500 dark:text-gray-400">
                <x-ri-checkbox-circle-line class="h-3.5 w-3.5 text-green-500 dark:text-green-400 flex-shrink-0"/>
                <span>No credit card</span>
                <span class="mx-1.5 text-gray-300 dark:text-gray-600">&middot;</span>
                <x-ri-checkbox-circle-line class="h-3.5 w-3.5 text-green-500 dark:text-green-400 flex-shrink-0"/>
                <span>Deploy in 5 minutes</span>
            </div>
        </div>
    </div>
</section>
