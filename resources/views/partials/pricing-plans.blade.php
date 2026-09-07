<div x-data="{ yearly: true }" class="mx-auto max-w-4xl">
    <div class="mb-6 flex flex-wrap items-center justify-center gap-4">
        <x-billing.interval-toggle />
        <span class="text-sm text-gray-600 dark:text-gray-400">{{ __('Save $60 a year on Cloud Pro') }}</span>
    </div>

    <div id="pricing-plans" class="grid gap-6 md:grid-cols-2">
        <section class="flex flex-col rounded-2xl border border-primary-200 bg-white dark:border-primary-400/30 dark:bg-[var(--surface-card-bg)]">
            <div class="p-6 sm:p-8">
                <h2 class="font-display text-2xl font-bold tracking-tight text-gray-950 dark:text-white">{{ __('billing.plans.cloud_pro') }}</h2>
                <p class="mt-2 min-h-10 text-sm leading-5 text-gray-600 dark:text-gray-400">{{ __('A ready-to-use CRM for your whole team. Hosting included.') }}</p>

                <div class="mt-6">
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Per workspace') }}</p>
                    <p class="mt-1 flex items-baseline gap-2" aria-live="polite">
                        <span class="font-display text-5xl font-bold tracking-tight text-gray-950 dark:text-white" x-text="yearly ? '$19' : '$24'">$19</span>
                        <span class="text-sm text-gray-600 dark:text-gray-400">{{ __('/ month') }}</span>
                    </p>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400" x-text="yearly ? @js(__('billing.pro_plan.billed_yearly')) : @js(__('billing.pro_plan.billed_monthly'))">{{ __('billing.pro_plan.billed_yearly') }}</p>
                </div>

                <x-marketing.button :href="route('login')" iconTrailing="ri-arrow-right-line" class="mt-6 w-full focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary">
                    {{ __('Start for free') }}
                </x-marketing.button>
                <p class="mt-3 text-center text-xs text-gray-600 dark:text-gray-400">{{ __('14-day trial. No card required.') }}</p>
            </div>

            <div class="flex flex-1 flex-col border-t border-[var(--surface-card-border)] p-6 sm:px-8">
                <p class="mb-4 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Your workspace includes') }}</p>
                <ul class="space-y-3">
                    @foreach(__('billing.pro_plan.features') as $feature)
                        <li class="flex items-start gap-3 text-sm leading-5 text-gray-600 dark:text-gray-300">
                            <x-ri-check-line class="mt-0.5 size-4 shrink-0 text-primary dark:text-primary-400" />
                            {{ $feature }}
                        </li>
                    @endforeach
                </ul>
                <p class="mt-auto pt-6 text-xs leading-5 text-gray-600 dark:text-gray-400">{{ __('Need more AI credits? Prepaid top-ups are available.') }}</p>
            </div>
        </section>

        <section id="enterprise-plan" class="flex flex-col rounded-2xl border border-[var(--surface-card-border)] bg-white dark:bg-[var(--surface-card-bg)]">
            <div class="p-6 sm:p-8">
                <h2 class="font-display text-2xl font-bold tracking-tight text-gray-950 dark:text-white">{{ __('billing.plans.enterprise') }}</h2>
                <p class="mt-2 min-h-10 text-sm leading-5 text-gray-600 dark:text-gray-400">{{ __('billing.enterprise.tagline') }}</p>

                <div class="mt-6" aria-label="{{ __('billing.enterprise.starting_price', ['price' => number_format(config('relaticle.enterprise.starting_price_yearly'))]) }}">
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Starting at') }}</p>
                    <p class="mt-1 flex flex-wrap items-baseline gap-x-2">
                        <span class="font-display text-5xl font-bold tracking-tight text-gray-950 dark:text-white">${{ number_format(config('relaticle.enterprise.starting_price_yearly')) }}</span>
                        <span class="text-sm text-gray-600 dark:text-gray-400">{{ __('/ year') }}</span>
                    </p>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('billing.enterprise.billing') }}</p>
                </div>

                <x-marketing.button variant="secondary" :href="route('contact', ['plan' => 'enterprise'])" class="mt-6 w-full focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary">
                    {{ __('billing.enterprise.contact') }}
                </x-marketing.button>
                <p class="mt-3 text-center text-xs text-gray-600 dark:text-gray-400">{{ __('Tell us about your project.') }}</p>
            </div>

            <div class="flex flex-1 flex-col border-t border-[var(--surface-card-border)] p-6 sm:px-8">
                <p class="mb-4 text-sm font-semibold text-gray-900 dark:text-white">{{ __('billing.enterprise.includes') }}</p>
                <ul class="space-y-3">
                    @foreach(__('billing.enterprise.features') as $feature)
                        <li class="flex items-start gap-3 text-sm leading-5 text-gray-600 dark:text-gray-300">
                            <x-ri-check-line class="mt-0.5 size-4 shrink-0 text-gray-500 dark:text-gray-400" />
                            {{ $feature }}
                        </li>
                    @endforeach
                </ul>
                <p class="mt-auto pt-6 text-xs leading-5 text-gray-600 dark:text-gray-400">{{ __('billing.enterprise.terms') }}</p>
            </div>
        </section>
    </div>

    <div id="self-hosting" class="mt-6 flex flex-col items-start justify-between gap-4 px-1 text-sm sm:flex-row sm:items-center">
        <p class="flex items-center gap-2 text-gray-600 dark:text-gray-400">
            <x-ri-github-fill class="size-4 shrink-0" />
            {{ __('Prefer to self-host? It’s free.') }}
        </p>
        <a href="{{ route('selfHosted') }}" class="inline-flex items-center gap-2 font-medium text-gray-900 hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary dark:text-gray-200 dark:hover:text-primary-300">
            {{ __('Explore self-hosting') }}
            <x-ri-arrow-right-line class="size-4" />
        </a>
    </div>
</div>
