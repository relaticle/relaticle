<section class="relative overflow-hidden bg-white dark:bg-gray-950">
    <div class="container max-w-7xl mx-auto py-24 md:py-32 px-4 sm:px-6 lg:px-8 relative">

        {{-- Corner & top dots patterns --}}
        <div class="absolute top-0 left-32 right-32 md:left-48 md:right-48 lg:left-64 lg:right-64 h-16 md:h-24 overflow-hidden pointer-events-none">
            <svg width="100%" height="100%" class="absolute inset-0 opacity-30 md:opacity-40 dark:opacity-20 dark:md:opacity-40">
                <pattern id="dots-top" x="0" y="0" width="20" height="20" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1.2" fill="#6B7280"/></pattern>
                <rect width="100%" height="100%" fill="url(#dots-top)"/>
            </svg>
            <div class="absolute inset-0 bg-gradient-to-b from-transparent to-white dark:to-gray-950"></div>
        </div>
        @foreach([['left', 'br'], ['right', 'bl']] as [$side, $dir])
            <div class="absolute top-0 {{ $side }}-0 w-32 h-32 md:w-48 md:h-48 lg:w-64 lg:h-64 overflow-hidden pointer-events-none">
                <svg width="100%" height="100%" class="absolute inset-0 opacity-40 md:opacity-50 lg:opacity-60 dark:opacity-30 dark:md:opacity-40 dark:lg:opacity-50">
                    <pattern id="dots-{{ $side }}" x="0" y="0" width="20" height="20" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1.2" fill="#6B7280"/></pattern>
                    <rect width="100%" height="100%" fill="url(#dots-{{ $side }})"/>
                </svg>
                <div class="absolute inset-0 bg-gradient-to-{{ $dir }} from-transparent via-white/50 to-white dark:via-gray-950/50 dark:to-gray-950"></div>
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
                <x-marketing.button href="{{ route('register') }}">
                    Start for free
                </x-marketing.button>

                <x-marketing.button variant="secondary" href="{{ route('contact') }}">
                    Get in touch
                </x-marketing.button>
            </div>
        </div>
    </div>
</section>
