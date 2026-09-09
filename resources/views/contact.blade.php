<x-guest-layout
    title="Contact: Deployments and integrations - Relaticle"
    description="Get in touch with the Relaticle team. Questions about enterprise deployments, custom integrations, or partnerships."
    ogTitle="Contact: Deployments and integrations - Relaticle"
>
    <section class="pt-28 pb-20 sm:pt-32 sm:pb-24 bg-white dark:bg-gray-950">

        <div class="relative max-w-5xl mx-auto px-6 lg:px-8">
            <div class="grid grid-cols-1 gap-10 lg:grid-cols-5 lg:gap-16">

                <div class="lg:col-span-2">
                    @if($enterpriseInquiry)
                        <a href="{{ route('pricing') }}" class="mb-6 inline-flex items-center gap-2 text-sm text-gray-600 hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary dark:text-gray-400 dark:hover:text-primary-300">
                            <x-ri-arrow-left-line class="size-4" />
                            {{ __('Back to pricing') }}
                        </a>
                    @endif
                    <h1 class="font-display text-3xl sm:text-4xl font-bold text-gray-950 dark:text-white tracking-tight leading-tight">
                        {{ $enterpriseInquiry ? __('billing.inquiry.title') : __('Get in touch') }}
                    </h1>
                    <p class="mt-5 text-base text-gray-500 dark:text-gray-400 leading-relaxed max-w-sm">
                        {{ $enterpriseInquiry ? __('billing.inquiry.body') : __('Questions about deployments, integrations, or partnerships? Get in touch with our team.') }}
                    </p>

                    @if($enterpriseInquiry)
                        <div class="mt-6 border-t border-[var(--surface-card-border)] pt-6">
                            <p class="font-display text-xl font-semibold text-gray-950 dark:text-white">{{ __('billing.enterprise.starting_price', ['price' => number_format(config('relaticle.enterprise.starting_price_yearly'))]) }}</p>
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('billing.enterprise.terms') }}</p>
                        </div>
                    @endif

                    @if(! $enterpriseInquiry)
                    <div class="mt-8 space-y-4">
                        @feature(App\Features\Documentation::class)
                        <a href="{{ route('help.index') }}" class="flex items-center gap-2 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors">
                            <x-ri-lifebuoy-line class="w-4 h-4"/>
                            Help Centre
                        </a>
                        <a href="{{ route('documentation.index') }}" class="flex items-center gap-2 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors">
                            <x-ri-book-open-line class="w-4 h-4"/>
                            Developer Docs
                        </a>
                        @endfeature
                        <a href="{{ route('discord') }}" target="_blank" class="flex items-center gap-2 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors">
                            <x-ri-discord-fill class="w-4 h-4"/>
                            Join Discord community
                        </a>
                        <a href="https://github.com/relaticle/relaticle" target="_blank" class="flex items-center gap-2 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors">
                            <x-ri-github-fill class="w-4 h-4"/>
                            GitHub repository
                        </a>
                    </div>
                    @endif
                </div>

                <div class="lg:col-span-3">
                    @if(session('success'))
                        <div class="rounded-xl border border-gray-200 dark:border-white/[0.08] bg-white dark:bg-white/[0.03] p-10 text-center">
                            <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-primary/10">
                                <x-ri-check-line class="w-6 h-6 text-primary"/>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-950 dark:text-white">Message sent</h3>
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ session('success') }}</p>
                        </div>
                    @else
                        <form method="POST" action="{{ route('contact', $enterpriseInquiry ? ['plan' => 'enterprise'] : []) }}" class="space-y-6">
                            @csrf
                            <x-honeypot />

                            <x-marketing.input label="Name" :required="true" type="text" name="name" id="name" autocomplete="name" required :value="old('name')" placeholder="Your name"/>

                            <x-marketing.input label="Work email" :required="true" type="email" name="email" id="email" autocomplete="email" required :value="old('email')" placeholder="you@company.com"/>

                            <x-marketing.input label="Company" type="text" name="company" id="company" autocomplete="organization" :value="old('company')" placeholder="Your company"/>

                            <x-marketing.textarea :label="$enterpriseInquiry ? __('billing.inquiry.message_label') : __('How can we help?')" :required="true" name="message" id="message" :rows="$enterpriseInquiry ? 8 : 5" required placeholder="Tell us about your project, team size, and any specific requirements...">{{ old('message', $enterpriseInquiry ? __('billing.inquiry.message') : '') }}</x-marketing.textarea>

                            <x-marketing.button type="submit" class="w-full sm:w-auto focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary">
                                {{ $enterpriseInquiry ? __('billing.inquiry.submit') : __('Send message') }}
                            </x-marketing.button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </section>
</x-guest-layout>
