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
