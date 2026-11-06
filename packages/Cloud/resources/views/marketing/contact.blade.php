<x-cloud::guest-layout
    title="Contact: Deployments and integrations - Relaticle"
    description="Get in touch with the Relaticle team. Questions about enterprise deployments, custom integrations, or partnerships."
    ogTitle="Contact: Deployments and integrations - Relaticle"
>
    @php
        $inquiryKey = $inquiry?->value;
    @endphp
    <x-cloud::marketing.hero align="start">
        <x-slot:eyebrow>
            <x-cloud::marketing.eyebrow>{{ __('Talk to us') }}</x-cloud::marketing.eyebrow>
        </x-slot:eyebrow>

        <x-slot:title>{{ $inquiry ? __("cloud::billing.inquiry.{$inquiryKey}.title") : __('Get in touch') }}</x-slot:title>

        <x-slot:aside>
            <p class="text-base leading-relaxed text-gray-500 md:text-lg dark:text-gray-400">
                {{ $inquiry ? __("cloud::billing.inquiry.{$inquiryKey}.body") : __('Questions about deployments, integrations, or partnerships? Get in touch with our team.') }}
            </p>
            @if($inquiry)
                <a href="{{ route('pricing') }}" class="mt-5 inline-flex items-center gap-2 text-sm font-medium text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white">
                    <x-ri-arrow-left-line class="size-4" />
                    {{ __('Back to pricing') }}
                </a>
            @endif
        </x-slot:aside>
    </x-cloud::marketing.hero>

    <x-cloud::marketing.frame>
        <div class="bg-rail grid grid-cols-1 gap-px lg:grid-cols-12">
            <div class="bg-surface p-6 md:p-10 lg:col-span-4">
                @if($inquiry)
                    <x-cloud::marketing.eyebrow class="mb-4">{{ __('The offer') }}</x-cloud::marketing.eyebrow>
                    <p class="type-title text-gray-950 dark:text-white">{{ $inquiry->price() }}</p>
                    <p class="type-prose mt-3 text-gray-500 dark:text-gray-400">{{ $inquiry->terms() }}</p>
                @else
                    <x-cloud::marketing.eyebrow class="mb-4">{{ __('Other ways in') }}</x-cloud::marketing.eyebrow>
                    <ul class="divide-rail border-rail divide-y border-y">
                        @feature(App\Features\Documentation::class)
                            <li><a href="{{ route('help.index') }}" class="flex items-center gap-3 py-3 text-sm font-medium text-gray-700 transition-colors hover:text-gray-950 dark:text-gray-300 dark:hover:text-white"><x-ri-lifebuoy-line class="size-4 text-gray-400"/>Help center</a></li>
                            <li><a href="{{ route('documentation.index') }}" class="flex items-center gap-3 py-3 text-sm font-medium text-gray-700 transition-colors hover:text-gray-950 dark:text-gray-300 dark:hover:text-white"><x-ri-book-open-line class="size-4 text-gray-400"/>Developer Docs</a></li>
                        @endfeature
                        <li><a href="{{ route('discord') }}" target="_blank" class="flex items-center gap-3 py-3 text-sm font-medium text-gray-700 transition-colors hover:text-gray-950 dark:text-gray-300 dark:hover:text-white"><x-ri-discord-fill class="size-4 text-gray-400"/>Join Discord community</a></li>
                        <li><a href="https://github.com/relaticle/relaticle" target="_blank" class="flex items-center gap-3 py-3 text-sm font-medium text-gray-700 transition-colors hover:text-gray-950 dark:text-gray-300 dark:hover:text-white"><x-ri-github-fill class="size-4 text-gray-400"/>GitHub repository</a></li>
                    </ul>
                @endif
            </div>

            <div class="bg-surface p-6 md:p-10 lg:col-span-8">
                @if(session('success'))
                    <div class="border-rail rounded-xl border p-10 text-center">
                        <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-primary/10">
                            <x-ri-check-line class="w-6 h-6 text-primary"/>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-950 dark:text-white">Message sent</h3>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ session('success') }}</p>
                    </div>
                @else
                    <form method="POST" action="{{ route('contact', $inquiry ? ['plan' => $inquiryKey] : []) }}" class="max-w-2xl space-y-6">
                        @csrf
                        <x-honeypot />

                        <x-cloud::marketing.input label="Name" :required="true" type="text" name="name" id="name" autocomplete="name" required :value="old('name')" placeholder="Your name"/>

                        <x-cloud::marketing.input label="Work email" :required="true" type="email" name="email" id="email" autocomplete="email" required :value="old('email')" placeholder="you@company.com"/>

                        <x-cloud::marketing.input label="Company" type="text" name="company" id="company" autocomplete="organization" :value="old('company')" placeholder="Your company"/>

                        <x-cloud::marketing.textarea :label="$inquiry ? __('cloud::billing.inquiry.'.$inquiryKey.'.message_label') : __('How can we help?')" :required="true" name="message" id="message" :rows="$inquiry ? 8 : 5" required placeholder="Tell us about your project, team size, and any specific requirements...">{{ old('message', $inquiry ? __("cloud::billing.inquiry.{$inquiryKey}.message") : '') }}</x-cloud::marketing.textarea>

                        <x-cloud::marketing.button type="submit" class="w-full sm:w-auto focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary">
                            {{ $inquiry ? __("cloud::billing.inquiry.{$inquiryKey}.submit") : __('Send message') }}
                        </x-cloud::marketing.button>
                    </form>
                @endif
            </div>
        </div>
    </x-cloud::marketing.frame>
</x-cloud::guest-layout>
