<x-guest-layout
    title="Contact Us - Relaticle"
    description="Get in touch with the Relaticle team. Questions about enterprise deployments, custom integrations, or partnerships."
    ogTitle="Contact Us - Relaticle"
>
    <section class="relative pt-32 pb-24 md:pt-40 md:pb-32 bg-white dark:bg-black overflow-hidden">
        <div class="absolute inset-0 bg-[linear-gradient(to_right,rgba(0,0,0,0.015)_1px,transparent_1px),linear-gradient(to_bottom,rgba(0,0,0,0.015)_1px,transparent_1px)] dark:bg-[linear-gradient(to_right,rgba(255,255,255,0.025)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.025)_1px,transparent_1px)] bg-[size:3rem_3rem] [mask-image:radial-gradient(ellipse_70%_50%_at_50%_50%,black_30%,transparent_100%)]"></div>

        <div class="relative max-w-5xl mx-auto px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-5 gap-16 lg:gap-20">

                {{-- Left: heading --}}
                <div class="lg:col-span-2">
                    <h1 class="font-display text-4xl sm:text-5xl font-bold text-gray-950 dark:text-white tracking-[-0.03em] leading-[1.1]">
                        Get in Touch
                    </h1>
                    <p class="mt-5 text-base text-gray-500 dark:text-gray-400 leading-relaxed max-w-sm">
                        Questions about enterprise deployments, custom integrations, or partnerships? We'd love to hear from you.
                    </p>

                    <div class="mt-8 space-y-4">
                        <a href="{{ route('documentation.index') }}" class="flex items-center gap-2 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors">
                            <x-ri-book-open-line class="w-4 h-4"/>
                            Documentation
                        </a>
                        <a href="{{ route('discord') }}" target="_blank" class="flex items-center gap-2 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors">
                            <x-ri-discord-fill class="w-4 h-4"/>
                            Join Discord community
                        </a>
                        <a href="https://github.com/relaticle/relaticle" target="_blank" class="flex items-center gap-2 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors">
                            <x-ri-github-fill class="w-4 h-4"/>
                            GitHub repository
                        </a>
                    </div>
                </div>

                {{-- Right: form --}}
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
                        <form method="POST" action="{{ route('contact') }}" class="space-y-6">
                            @csrf
                            <x-honeypot />

                            <x-marketing.input label="Name" :required="true" type="text" name="name" id="name" required :value="old('name')" placeholder="Your name"/>

                            <x-marketing.input label="Work email" :required="true" type="email" name="email" id="email" required :value="old('email')" placeholder="you@company.com"/>

                            <x-marketing.input label="Company" type="text" name="company" id="company" :value="old('company')" placeholder="Your company"/>

                            <x-marketing.textarea label="How can we help?" :required="true" name="message" id="message" rows="5" required placeholder="Tell us about your project, team size, and any specific requirements...">{{ old('message') }}</x-marketing.textarea>

                            <x-marketing.button type="submit">
                                Send message
                            </x-marketing.button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </section>
</x-guest-layout>
