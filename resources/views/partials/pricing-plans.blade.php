<div x-data="{ yearly: true }" class="mx-auto max-w-4xl">
    <div class="mb-8 flex justify-center">
        <x-billing.interval-toggle />
    </div>

    <div id="pricing-plans" class="grid gap-5 md:grid-cols-2">
        <section class="relative flex flex-col rounded-2xl border border-primary-300 bg-white shadow-[0_1px_2px_rgba(0,0,0,0.04),0_6px_20px_-10px_rgba(124,58,237,0.25)] dark:border-primary-400/40 dark:bg-white/[0.03] dark:shadow-none">
            <div class="p-6 sm:p-8">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/[0.08] dark:bg-primary/[0.15]">
                            <x-icons.plan-cloud-pro class="size-5 text-primary dark:text-primary-400" />
                        </div>
                        <h2 class="font-display text-xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ __('billing.plans.cloud_pro') }}</h2>
                    </div>
                    <span class="shrink-0 rounded-full bg-primary px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wider text-white">{{ __('Recommended') }}</span>
                </div>
                <p class="mt-4 min-h-10 text-sm leading-5 text-gray-600 dark:text-gray-400">{{ __('A ready-to-use CRM for your whole team. Hosting included.') }}</p>

                <div class="mt-8">
                    <p class="text-[11px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Per workspace') }}</p>
                    <p class="mt-2 flex items-baseline gap-1.5" aria-live="polite">
                        <span class="font-display text-5xl font-bold tracking-[-0.03em] text-gray-950 dark:text-white" x-text="yearly ? '$19' : '$24'">$19</span>
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('/ month') }}</span>
                    </p>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400" x-text="yearly ? @js(__('billing.pro_plan.billed_yearly')) : @js(__('billing.pro_plan.billed_monthly'))">{{ __('billing.pro_plan.billed_yearly') }}</p>
                </div>

                <x-marketing.button :href="route('login')" iconTrailing="ri-arrow-right-line" class="mt-8 w-full focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary">
                    {{ __('Start for free') }}
                </x-marketing.button>
                <p class="mt-3 text-center text-xs text-gray-500 dark:text-gray-400">{{ __('14-day trial. No card required.') }}</p>
            </div>

            <div class="flex flex-1 flex-col border-t border-gray-100 p-6 dark:border-white/[0.06] sm:px-8">
                <p class="mb-4 text-[11px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Your workspace includes') }}</p>
                <ul class="space-y-3">
                    @foreach(__('billing.pro_plan.features') as $feature)
                        <li class="flex items-start gap-3 text-sm leading-5 text-gray-700 dark:text-gray-300">
                            <x-ri-check-line class="mt-0.5 size-4 shrink-0 text-primary dark:text-primary-400" />
                            {{ $feature }}
                        </li>
                    @endforeach
                </ul>
                <p class="mt-auto pt-6 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ __('Need more AI credits? Prepaid top-ups are available.') }}</p>
            </div>
        </section>

        <section id="enterprise-plan" class="flex flex-col rounded-2xl border border-gray-200/80 bg-white dark:border-white/[0.08] dark:bg-white/[0.02]">
            <div class="p-6 sm:p-8">
                <div class="flex items-center gap-3">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 dark:bg-white/[0.06]">
                        <x-icons.plan-enterprise class="size-5 text-gray-700 dark:text-gray-300" />
                    </div>
                    <h2 class="font-display text-xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ __('billing.plans.enterprise') }}</h2>
                </div>
                <p class="mt-4 min-h-10 text-sm leading-5 text-gray-600 dark:text-gray-400">{{ __('billing.enterprise.tagline') }}</p>

                <div class="mt-8" aria-label="{{ __('billing.enterprise.starting_price', ['price' => $enterprisePrice]) }}">
                    <p class="text-[11px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Starting at') }}</p>
                    <p class="mt-2 flex flex-wrap items-baseline gap-x-1.5">
                        <span class="font-display text-5xl font-bold tracking-[-0.03em] text-gray-950 dark:text-white">${{ $enterprisePrice }}</span>
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('/ year') }}</span>
                    </p>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('billing.enterprise.billing') }}</p>
                </div>

                <x-marketing.button variant="secondary" :href="route('contact', ['plan' => 'enterprise'])" class="mt-8 w-full focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary">
                    {{ __('billing.enterprise.contact') }}
                </x-marketing.button>
                <p class="mt-3 text-center text-xs text-gray-500 dark:text-gray-400">{{ __('No commitment until scope is agreed.') }}</p>
            </div>

            <div class="flex flex-1 flex-col border-t border-gray-100 p-6 dark:border-white/[0.06] sm:px-8">
                <p class="mb-4 text-[11px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('billing.enterprise.includes') }}</p>
                <ul class="space-y-3">
                    @foreach(__('billing.enterprise.features') as $feature)
                        <li class="flex items-start gap-3 text-sm leading-5 text-gray-700 dark:text-gray-300">
                            <x-ri-check-line class="mt-0.5 size-4 shrink-0 text-gray-400 dark:text-gray-500" />
                            {{ $feature }}
                        </li>
                    @endforeach
                </ul>
                <p class="mt-auto pt-6 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ __('billing.enterprise.terms') }}</p>
            </div>
        </section>
    </div>

    <div id="self-hosting" class="mt-5 flex flex-col gap-4 rounded-2xl border border-gray-200/80 bg-gray-50 p-5 dark:border-white/[0.06] dark:bg-white/[0.02] sm:flex-row sm:items-center sm:justify-between sm:px-6">
        <div class="flex items-start gap-4">
            <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-white shadow-[0_1px_2px_rgba(0,0,0,0.04)] ring-1 ring-gray-200/80 dark:bg-white/[0.06] dark:shadow-none dark:ring-white/[0.08]">
                <x-ri-github-fill class="size-5 text-gray-700 dark:text-gray-300" />
            </div>
            <div>
                <p class="font-display text-base font-semibold text-gray-950 dark:text-white">{{ __('Prefer to self-host? It’s free.') }}</p>
                <p class="mt-1 text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ __('AGPL-3.0 open source. Unlimited users and records on your own server, forever.') }}</p>
            </div>
        </div>
        <x-marketing.button variant="secondary" size="sm" :href="route('selfHosted')" iconTrailing="ri-arrow-right-line" class="shrink-0 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary">
            {{ __('Explore self-hosting') }}
        </x-marketing.button>
    </div>
</div>
