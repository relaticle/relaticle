<x-guest-layout
    title="Pricing - Relaticle"
    description="Relaticle pricing. No per-seat pricing — flat workspace plans. Unlimited users and records. Self-host free forever."
    ogTitle="Pricing - Relaticle"
>
    <section class="relative pt-32 pb-24 md:pt-40 md:pb-32 bg-white dark:bg-gray-950 overflow-hidden">
        <div class="absolute inset-0 bg-[linear-gradient(to_right,rgba(0,0,0,0.015)_1px,transparent_1px),linear-gradient(to_bottom,rgba(0,0,0,0.015)_1px,transparent_1px)] dark:bg-[linear-gradient(to_right,rgba(255,255,255,0.025)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.025)_1px,transparent_1px)] bg-[size:3rem_3rem] [mask-image:radial-gradient(ellipse_70%_50%_at_50%_50%,black_30%,transparent_100%)]"></div>

        <div class="relative max-w-5xl mx-auto px-6 lg:px-8">

            {{-- Badge --}}
            <div class="flex justify-center mb-6">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full border border-gray-200/80 dark:border-white/[0.08] bg-white/80 dark:bg-white/[0.04] backdrop-blur-sm shadow-[0_1px_2px_rgba(0,0,0,0.03)]">
                    <x-ri-heart-pulse-line class="h-3.5 w-3.5 text-primary dark:text-primary-400"/>
                    <span class="uppercase tracking-wider text-[10px] font-medium text-gray-500 dark:text-gray-400">Simple pricing</span>
                </div>
            </div>

            {{-- Header --}}
            <div class="text-center max-w-2xl mx-auto mb-16 md:mb-20">
                <h1 class="font-display text-4xl sm:text-5xl font-bold text-gray-950 dark:text-white tracking-[-0.03em] leading-[1.1]">
                    No per-seat pricing. Ever.
                </h1>
                <p class="mt-5 text-base md:text-lg text-gray-500 dark:text-gray-400 leading-relaxed">
                    Unlimited users. Unlimited data. Self-host for free forever, or let us run it for you.
                </p>
            </div>

            @if(\Laravel\Pennant\Feature::active(\App\Features\Billing::class))
                @include('partials.pricing-plans')
            @else
                @include('partials.pricing-legacy')
            @endif

            {{-- Trust signals --}}
            <div class="mt-16 max-w-4xl mx-auto">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    @foreach([
                        ['ri-shield-check-line', '2,000+', 'Automated Tests'],
                        ['ri-robot-2-line', '30', 'MCP Tools'],
                        ['ri-stack-line', '22', 'Field Types'],
                        ['ri-lock-line', '5-Layer', 'Authorization'],
                    ] as [$icon, $value, $label])
                        <div class="rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02] px-5 py-4 text-center">
                            <x-dynamic-component :component="$icon" class="w-5 h-5 text-primary dark:text-primary-400 mx-auto mb-2"/>
                            <div class="text-lg font-semibold text-gray-900 dark:text-white tracking-tight">{{ $value }}</div>
                            <div class="text-[11px] text-gray-400 dark:text-gray-500 mt-0.5 uppercase tracking-wider font-medium">{{ $label }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Help CTA --}}
            <div class="mt-8 max-w-4xl mx-auto">
                <div class="relative rounded-2xl border border-gray-200/80 dark:border-white/[0.06] bg-gray-50/50 dark:bg-white/[0.015] p-8 flex flex-col sm:flex-row items-center gap-6 overflow-hidden">
                    <div class="absolute -bottom-10 -left-10 w-40 h-40 bg-primary/[0.04] dark:bg-primary/[0.08] rounded-full blur-3xl pointer-events-none" aria-hidden="true"></div>
                    <div class="relative flex-1 text-left">
                        <h3 class="font-display text-lg font-semibold text-gray-900 dark:text-white">Need help choosing?</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400 leading-relaxed">
                            Not sure which option fits? Have questions about deployment or migration? We're happy to help.
                        </p>
                    </div>
                    <x-marketing.button variant="secondary" href="{{ route('contact') }}" class="relative shrink-0">
                        Get in touch
                    </x-marketing.button>
                </div>
            </div>

        </div>
    </section>
</x-guest-layout>
