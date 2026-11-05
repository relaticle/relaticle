<div class="bg-surface" aria-hidden="true">
    <div class="border-rail mx-auto h-12 max-w-6xl sm:border-x"></div>
</div>

<section class="bg-cta-band relative isolate overflow-hidden text-white">
    <div class="pointer-events-none absolute inset-0 bg-[linear-gradient(to_right,rgba(255,255,255,0.05)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.05)_1px,transparent_1px)] bg-[size:4rem_4rem] bg-[position:calc(50%-36rem)_0] [mask-image:radial-gradient(ellipse_80%_75%_at_50%_45%,black_35%,transparent_100%)]" aria-hidden="true"></div>

    {{-- The page above hangs into the band as a tab; it sits 1px high to cover the section's bottom rail. --}}
    <svg class="fill-surface pointer-events-none absolute -top-px left-1/2 z-10 h-10 w-[min(44rem,86%)] -translate-x-1/2 md:h-16" viewBox="0 0 704 64" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0 0C24 0 38 8 50 26L62 44C72 58 86 64 106 64H598C618 64 632 58 642 44L654 26C666 8 680 0 704 0Z"/>
    </svg>

    <div class="relative mx-auto max-w-6xl border-white/10 px-6 pb-20 pt-28 text-center sm:border-x md:px-10 md:pb-24 md:pt-40">
        <h2 class="type-title mx-auto max-w-4xl md:text-5xl">
            <span class="text-white">{{ __('Your CRM, your rules.') }}</span>
            <span class="text-gray-400 lg:block">{{ __('Start free, self-host whenever you like.') }}</span>
        </h2>

        <div class="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row">
            <x-cloud::marketing.button href="{{ route('login') }}" data-analytics-intent="signup" data-analytics-placement="footer">
                {{ __('Start for free') }}
            </x-cloud::marketing.button>

            <x-cloud::marketing.button variant="secondary" href="{{ route('contact') }}">
                {{ __('Get in touch') }}
            </x-cloud::marketing.button>
        </div>

        <p class="mt-5 text-sm text-gray-400">
            {{ __(':days-day trial, no card, unlimited users.', ['days' => \App\Models\Workspace::PRO_TRIAL_DAYS]) }}
        </p>

    </div>
</section>
