<section class="py-24 md:py-32 bg-gray-50 dark:bg-gray-950 relative overflow-hidden">
    <div class="absolute inset-0 bg-[radial-gradient(circle_at_1px_1px,rgb(0_0_0/0.04)_1px,transparent_0)] dark:bg-[radial-gradient(circle_at_1px_1px,rgb(255_255_255/0.035)_1px,transparent_0)] bg-[size:24px_24px] [mask-image:radial-gradient(ellipse_60%_50%_at_50%_50%,black_25%,transparent_100%)]"></div>

    <!-- Bottom gradient fade into next section -->
    <div class="absolute bottom-0 left-0 right-0 h-32 bg-gradient-to-b from-transparent to-white dark:to-black pointer-events-none"></div>

    <div class="relative max-w-3xl mx-auto px-6 lg:px-8">
        <div class="max-w-2xl mx-auto text-center mb-16">
            <h2 class="font-display text-3xl sm:text-4xl font-bold text-gray-950 dark:text-white tracking-[-0.02em] leading-[1.15]">
                Frequently Asked Questions
            </h2>
        </div>

        <div x-data="{ open: null }" class="divide-y divide-gray-200 dark:divide-white/10">

            <div class="py-5">
                <button @click="open = open === 1 ? null : 1"
                        class="flex w-full items-center justify-between text-left gap-4">
                    <span class="text-base font-semibold text-gray-900 dark:text-white">
                        Is Relaticle production-ready?
                    </span>
                    <svg class="h-5 w-5 shrink-0 text-gray-400 transition-transform duration-200" :class="open === 1 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                    </svg>
                </button>
                <div x-show="open === 1" x-collapse class="mt-3 text-sm text-gray-600 dark:text-gray-400 leading-relaxed">
                    Yes. Relaticle has 1,100+ automated tests, 5-layer authorization, 56+ MCP-specific tests, and is used in production. The codebase is continuously tested with PHPStan static analysis and Pest mutation testing.
                </div>
            </div>

            <div class="py-5">
                <button @click="open = open === 2 ? null : 2"
                        class="flex w-full items-center justify-between text-left gap-4">
                    <span class="text-base font-semibold text-gray-900 dark:text-white">
                        What AI agents can I connect?
                    </span>
                    <svg class="h-5 w-5 shrink-0 text-gray-400 transition-transform duration-200" :class="open === 2 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                    </svg>
                </button>
                <div x-show="open === 2" x-collapse class="mt-3 text-sm text-gray-600 dark:text-gray-400 leading-relaxed">
                    Any agent that speaks MCP (Model Context Protocol). Claude, ChatGPT, Gemini, open-source models, or your own custom agents. Relaticle's MCP server provides 20 tools for AI agents to read, create, update, and delete CRM data.
                </div>
            </div>

            <div class="py-5">
                <button @click="open = open === 3 ? null : 3"
                        class="flex w-full items-center justify-between text-left gap-4">
                    <span class="text-base font-semibold text-gray-900 dark:text-white">
                        What is MCP?
                    </span>
                    <svg class="h-5 w-5 shrink-0 text-gray-400 transition-transform duration-200" :class="open === 3 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                    </svg>
                </button>
                <div x-show="open === 3" x-collapse class="mt-3 text-sm text-gray-600 dark:text-gray-400 leading-relaxed">
                    MCP (Model Context Protocol) is an open standard that lets AI agents interact with tools and data sources. Relaticle's MCP server gives agents 20 tools to work with your CRM data — listing companies, creating contacts, updating opportunities, and more.
                </div>
            </div>

            <div class="py-5">
                <button @click="open = open === 4 ? null : 4"
                        class="flex w-full items-center justify-between text-left gap-4">
                    <span class="text-base font-semibold text-gray-900 dark:text-white">
                        How is Relaticle different from HubSpot or Salesforce?
                    </span>
                    <svg class="h-5 w-5 shrink-0 text-gray-400 transition-transform duration-200" :class="open === 4 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                    </svg>
                </button>
                <div x-show="open === 4" x-collapse class="mt-3 text-sm text-gray-600 dark:text-gray-400 leading-relaxed">
                    Relaticle is self-hosted (you own your data), open-source (AGPL-3.0), has 20 MCP tools (vs HubSpot's 9), and has no per-seat pricing. It's designed for teams who want AI agent integration without vendor lock-in.
                </div>
            </div>

            <div class="py-5">
                <button @click="open = open === 5 ? null : 5"
                        class="flex w-full items-center justify-between text-left gap-4">
                    <span class="text-base font-semibold text-gray-900 dark:text-white">
                        How do I deploy Relaticle?
                    </span>
                    <svg class="h-5 w-5 shrink-0 text-gray-400 transition-transform duration-200" :class="open === 5 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                    </svg>
                </button>
                <div x-show="open === 5" x-collapse class="mt-3 text-sm text-gray-600 dark:text-gray-400 leading-relaxed">
                    Deploy with Docker Compose, Laravel Forge, or any PHP 8.4+ hosting with PostgreSQL. Self-hosted means your data never leaves your server. A managed hosting option is also available at app.relaticle.com.
                </div>
            </div>

            <div class="py-5">
                <button @click="open = open === 6 ? null : 6"
                        class="flex w-full items-center justify-between text-left gap-4">
                    <span class="text-base font-semibold text-gray-900 dark:text-white">
                        Can I customize the data model?
                    </span>
                    <svg class="h-5 w-5 shrink-0 text-gray-400 transition-transform duration-200" :class="open === 6 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                    </svg>
                </button>
                <div x-show="open === 6" x-collapse class="mt-3 text-sm text-gray-600 dark:text-gray-400 leading-relaxed">
                    Yes. Relaticle offers 22 custom field types including text, email, phone, currency, date, select, multiselect, entity relationships, conditional visibility, and per-field encryption. No migrations or code changes needed.
                </div>
            </div>

        </div>
    </div>
</section>
