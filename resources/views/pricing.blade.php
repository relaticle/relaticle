<x-guest-layout
    title="Pricing - Relaticle"
    description="Relaticle pricing. Free forever — self-hosted or cloud. No per-seat pricing. No usage limits."
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

            {{-- Pricing cards --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-4xl mx-auto">

                {{-- Cloud (primary) --}}
                <div class="relative rounded-2xl border border-primary/20 dark:border-primary/15 bg-white dark:bg-white/[0.02] flex flex-col overflow-hidden shadow-[0_4px_32px_-8px_rgba(124,58,237,0.08)] dark:shadow-[0_4px_32px_-8px_rgba(124,58,237,0.15)]">
                    <div class="h-1 bg-gradient-to-r from-primary via-purple-500 to-pink-500"></div>
                    <div class="flex-1 flex flex-col p-8">

                    {{-- Recommended badge --}}
                    <div class="absolute top-6 right-5">
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-primary/[0.08] dark:bg-primary/[0.15] text-[10px] font-semibold uppercase tracking-wider text-primary-700 dark:text-primary-300">
                            <x-ri-star-fill class="w-3 h-3"/>
                            Recommended
                        </span>
                    </div>

                    <div class="relative mb-6">
                        <div class="flex items-center gap-2.5">
                            <div class="flex items-center justify-center w-9 h-9 rounded-xl bg-primary/[0.08] dark:bg-primary/[0.15]">
                                <x-ri-cloud-line class="w-4.5 h-4.5 text-primary dark:text-primary-400"/>
                            </div>
                            <div>
                                <h2 class="font-display text-xl font-semibold text-gray-900 dark:text-white">Cloud</h2>
                            </div>
                        </div>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Managed hosting with automatic updates and backups</p>
                    </div>

                    <div class="relative mb-8">
                        <div class="flex items-baseline gap-1">
                            <span class="text-5xl font-bold text-gray-950 dark:text-white tracking-tight">$0</span>
                            <span class="text-sm text-gray-400 dark:text-gray-500">/mo</span>
                        </div>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1.5">Generous free tier. Always.</p>
                    </div>

                    <div class="relative mb-8 flex-1">
                        <div class="text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-4">Everything included</div>
                        <ul class="space-y-3">
                            @foreach([
                                'Unlimited users and data',
                                'MCP server with 30 tools',
                                'REST API with full CRUD',
                                '22 custom field types',
                                'Multi-team workspaces',
                            ] as $feature)
                                <li class="flex items-start gap-2.5 text-sm text-gray-600 dark:text-gray-400">
                                    <x-ri-check-line class="w-4 h-4 text-primary dark:text-primary-400 shrink-0 mt-0.5"/>
                                    {{ $feature }}
                                </li>
                            @endforeach
                        </ul>

                        <div class="mt-5 pt-5 border-t border-gray-100 dark:border-white/[0.04]">
                            <div class="text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-4">Cloud benefits</div>
                            <ul class="space-y-3">
                                @foreach([
                                    'Zero-downtime updates',
                                    'Automatic daily backups',
                                    'No server maintenance',
                                    'Email support',
                                ] as $feature)
                                    <li class="flex items-start gap-2.5 text-sm text-gray-600 dark:text-gray-400">
                                        <x-ri-check-line class="w-4 h-4 text-primary dark:text-primary-400 shrink-0 mt-0.5"/>
                                        {{ $feature }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>

                    <x-marketing.button href="{{ route('register') }}">
                        Start for free
                    </x-marketing.button>
                    </div>
                </div>

                {{-- Self-Hosted --}}
                <div class="relative rounded-2xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02] flex flex-col overflow-hidden shadow-[0_2px_16px_-6px_rgba(0,0,0,0.05)] dark:shadow-none">
                    <div class="h-1 bg-gradient-to-r from-gray-200 via-gray-300 to-gray-200 dark:from-white/10 dark:via-white/20 dark:to-white/10"></div>
                    <div class="flex-1 flex flex-col p-8">
                    <div class="mb-6">
                        <div class="flex items-center gap-2.5">
                            <div class="flex items-center justify-center w-9 h-9 rounded-xl bg-gray-100 dark:bg-white/[0.06]">
                                <x-ri-server-line class="w-4.5 h-4.5 text-gray-600 dark:text-gray-400"/>
                            </div>
                            <div>
                                <h2 class="font-display text-xl font-semibold text-gray-900 dark:text-white">Self-Hosted</h2>
                            </div>
                        </div>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Your server, your data, your rules</p>
                    </div>

                    <div class="mb-8">
                        <div class="flex items-baseline gap-1">
                            <span class="text-5xl font-bold text-gray-950 dark:text-white tracking-tight">Free</span>
                            <span class="text-sm text-gray-400 dark:text-gray-500">forever</span>
                        </div>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1.5">AGPL-3.0 open source</p>
                    </div>

                    <div class="mb-8 flex-1">
                        <div class="text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-4">Everything included</div>
                        <ul class="space-y-3">
                            @foreach([
                                'Unlimited users and data',
                                'MCP server with 30 tools',
                                'REST API with full CRUD',
                                '22 custom field types',
                                'Multi-team workspaces',
                            ] as $feature)
                                <li class="flex items-start gap-2.5 text-sm text-gray-600 dark:text-gray-400">
                                    <x-ri-check-line class="w-4 h-4 text-gray-400 dark:text-gray-500 shrink-0 mt-0.5"/>
                                    {{ $feature }}
                                </li>
                            @endforeach
                        </ul>

                        <div class="mt-5 pt-5 border-t border-gray-100 dark:border-white/[0.04]">
                            <div class="text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-4">Self-hosted benefits</div>
                            <ul class="space-y-3">
                                @foreach([
                                    'Full source code access',
                                    'Docker Compose deployment',
                                    'Data never leaves your server',
                                    'Community support (Discord)',
                                ] as $feature)
                                    <li class="flex items-start gap-2.5 text-sm text-gray-600 dark:text-gray-400">
                                        <x-ri-check-line class="w-4 h-4 text-gray-400 dark:text-gray-500 shrink-0 mt-0.5"/>
                                        {{ $feature }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>

                    <x-marketing.button variant="secondary" href="https://github.com/relaticle/relaticle" icon="ri-github-fill" external>
                        View on GitHub
                    </x-marketing.button>
                    </div>
                </div>
            </div>

            {{-- Trust signals --}}
            <div class="mt-16 max-w-4xl mx-auto">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    @foreach([
                        ['ri-shield-check-line', '1,100+', 'Automated Tests'],
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
