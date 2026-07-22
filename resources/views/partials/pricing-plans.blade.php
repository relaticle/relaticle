{{-- Pricing cards (managed Cloud + open-source self-hosting) --}}
<div class="mx-auto grid max-w-4xl grid-cols-1 gap-6 md:grid-cols-2">
    {{-- Managed Cloud Pro --}}
    <div
        x-data="{ yearly: true }"
        class="relative flex flex-col overflow-hidden rounded-2xl border border-primary/20 bg-white shadow-[0_4px_32px_-8px_rgba(124,58,237,0.08)] dark:border-primary/15 dark:bg-white/[0.02] dark:shadow-[0_4px_32px_-8px_rgba(124,58,237,0.15)]"
    >
        <div class="h-1 bg-gradient-to-r from-primary via-purple-500 to-pink-500"></div>
        <div class="flex flex-1 flex-col p-8">
            <div class="absolute right-5 top-6">
                <span class="inline-flex items-center gap-1 rounded-full bg-primary/[0.08] px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wider text-primary-700 dark:bg-primary/[0.15] dark:text-primary-300">
                    <x-ri-star-fill class="h-3 w-3" />
                    Recommended
                </span>
            </div>

            <div class="relative mb-6">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary/[0.08] dark:bg-primary/[0.15]">
                        <x-ri-cloud-line class="h-4.5 w-4.5 text-primary dark:text-primary-400" />
                    </div>
                    <h2 class="font-display text-xl font-semibold text-gray-900 dark:text-white">Cloud Pro</h2>
                </div>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Managed by us. Ready in minutes.</p>
            </div>

            <div class="relative mb-6">
                <div class="flex items-baseline gap-1">
                    <span class="text-5xl font-bold tracking-tight text-gray-950 dark:text-white" x-text="yearly ? '$19' : '$24'">$19</span>
                    <span class="text-sm text-gray-400 dark:text-gray-500">/mo</span>
                </div>
                <p class="mt-1.5 text-xs text-gray-400 dark:text-gray-500">Per workspace. Never per seat.</p>

                <div class="mt-4 inline-flex items-center gap-2 rounded-full border border-gray-200/80 p-1 text-xs dark:border-white/[0.08]">
                    <button type="button" @click="yearly = true" :aria-pressed="yearly" :class="yearly ? 'bg-primary text-white' : 'text-gray-500 dark:text-gray-400'" class="rounded-full px-3 py-1 font-medium transition">
                        Yearly
                        <span class="ml-1 text-[10px]" :class="yearly ? 'text-white/80' : 'text-primary-600 dark:text-primary-300'">Save 21%</span>
                    </button>
                    <button type="button" @click="yearly = false" :aria-pressed="!yearly" :class="!yearly ? 'bg-primary text-white' : 'text-gray-500 dark:text-gray-400'" class="rounded-full px-3 py-1 font-medium transition">Monthly</button>
                </div>
            </div>

            <div class="relative mb-8 flex-1">
                <ul class="space-y-3">
                    @foreach([
                        'Unlimited users and records',
                        '2,000 AI credits / month',
                        'All AI models, including premium',
                        'REST API and 30-tool MCP server',
                        'Email support',
                    ] as $feature)
                        <li class="flex items-start gap-2.5 text-sm text-gray-600 dark:text-gray-400">
                            <x-ri-check-line class="mt-0.5 h-4 w-4 shrink-0 text-primary dark:text-primary-400" />
                            {{ $feature }}
                        </li>
                    @endforeach
                </ul>
            </div>

            <x-marketing.button href="{{ route('register') }}">
                Start your 14-day trial — no card
            </x-marketing.button>
            <p class="mt-3 text-center text-xs text-gray-400 dark:text-gray-500" x-text="yearly ? '$228 billed yearly · save $60' : 'Billed monthly · cancel anytime'">
                $228 billed yearly · save $60
            </p>
        </div>
    </div>

    {{-- Open-source self-hosting --}}
    <div class="relative flex flex-col overflow-hidden rounded-2xl border border-gray-200/80 bg-white shadow-[0_2px_16px_-6px_rgba(0,0,0,0.05)] dark:border-white/[0.06] dark:bg-white/[0.02] dark:shadow-none">
        <div class="h-1 bg-gradient-to-r from-gray-200 via-gray-300 to-gray-200 dark:from-white/10 dark:via-white/20 dark:to-white/10"></div>
        <div class="flex flex-1 flex-col p-8">
            <div class="mb-6">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gray-100 dark:bg-white/[0.06]">
                        <x-ri-server-line class="h-4.5 w-4.5 text-gray-600 dark:text-gray-400" />
                    </div>
                    <h2 class="font-display text-xl font-semibold text-gray-900 dark:text-white">Self-Hosted</h2>
                </div>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Your server, your data, your rules.</p>
            </div>

            <div class="mb-8">
                <div class="flex items-baseline gap-1">
                    <span class="text-5xl font-bold tracking-tight text-gray-950 dark:text-white">Free</span>
                    <span class="text-sm text-gray-400 dark:text-gray-500">forever</span>
                </div>
                <p class="mt-1.5 text-xs text-gray-400 dark:text-gray-500">AGPL-3.0 open source</p>
            </div>

            <div class="mb-8 flex-1">
                <ul class="space-y-3">
                    @foreach([
                        'Unlimited users and records',
                        'Full source code access',
                        'Docker Compose deployment',
                        'Data never leaves your server',
                        'Community support on Discord',
                    ] as $feature)
                        <li class="flex items-start gap-2.5 text-sm text-gray-600 dark:text-gray-400">
                            <x-ri-check-line class="mt-0.5 h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500" />
                            {{ $feature }}
                        </li>
                    @endforeach
                </ul>
            </div>

            <x-marketing.button variant="secondary" href="https://github.com/relaticle/relaticle" icon="ri-github-fill" external>
                View on GitHub
            </x-marketing.button>
        </div>
    </div>
</div>

<div class="mx-auto mt-8 max-w-4xl space-y-3 text-center">
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Need higher AI allowances or custom invoicing?
        <a href="{{ route('contact') }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">Talk to us</a>.
    </p>
    <p class="text-xs text-gray-400 dark:text-gray-500">
        1 credit ≈ one AI chat message or record summary. Allowances may evolve with notice.
    </p>
</div>
