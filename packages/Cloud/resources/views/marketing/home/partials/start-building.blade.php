<x-cloud::marketing.frame>
    <div class="bg-warm relative overflow-hidden px-6 py-24 text-center md:py-32">
        <div class="mask-top-corners absolute inset-0 text-gray-400 dark:text-gray-600" aria-hidden="true">
            <svg class="absolute inset-0 size-full">
                <pattern id="start-building-dots" width="20" height="20" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1.2" fill="currentColor"/></pattern>
                <rect width="100%" height="100%" fill="url(#start-building-dots)"/>
            </svg>
            <x-cloud::marketing.interactive-dot-grid-glow/>
        </div>

        <div class="relative mx-auto max-w-4xl">
            <h2 class="type-title md:text-5xl">
                <span class="text-gray-950 dark:text-white">{{ __('Your CRM, your rules.') }}</span>
                <span class="text-gray-500 lg:block dark:text-gray-400">{{ __('Start free, self-host whenever you like.') }}</span>
            </h2>

            <div class="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <x-cloud::marketing.button href="{{ route('login') }}" data-analytics-intent="signup" data-analytics-placement="footer">
                    {{ __('Start for free') }}
                </x-cloud::marketing.button>

                <x-cloud::marketing.button variant="secondary" href="{{ route('contact') }}">
                    {{ __('Get in touch') }}
                </x-cloud::marketing.button>
            </div>

            <p class="mt-5 text-sm text-gray-500 dark:text-gray-400">
                {{ __(':days-day trial, no card, unlimited users.', ['days' => \App\Models\Workspace::PRO_TRIAL_DAYS]) }}
            </p>
        </div>
    </div>
</x-cloud::marketing.frame>
