{{-- Hero entry phase — mirrors the real /abcd dashboard: centered greeting,
     centered composer, recent-chat link, example chips. Lives as an
     absolutely-positioned overlay above the conversation pane and fades
     out during the entry → conversation transition.
     Visibility is driven by .mcp-el rule (opacity: 0 at rest) and the
     heroChat factory; no x-show so the markup is also visible to the SEO
     crawler and to no-JS users alongside the conversation. --}}
<div class="hero-agent-entry mcp-el absolute inset-0 z-20 flex items-start justify-center bg-gray-50 dark:bg-gray-950 px-4 sm:px-6 md:px-8 overflow-hidden">
    {{-- pt-12/pt-20 mirrors the real dashboard's `py-16` while leaving headroom
         on the shorter mobile panel (h-[520px]). max-w-2xl keeps the composer
         readable without dominating the panel. --}}
    <div class="mx-auto w-full max-w-2xl pt-12 sm:pt-16 md:pt-20">
        {{-- Greeting --}}
        <div class="text-center">
            <h2 class="mcp-el mcp-entry-greeting text-2xl sm:text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">
                Good morning, Sarah.
            </h2>

            <div class="mcp-el mcp-entry-recent mt-2 inline-flex items-center gap-1.5 text-xs sm:text-sm text-gray-500 dark:text-gray-400">
                <x-heroicon-o-arrow-path class="h-3.5 w-3.5"/>
                <span>Recent chat · This week's pipeline review</span>
            </div>
        </div>

        {{-- Composer twin — identical to hero-agent-composer.blade.php but
             with entry-scoped IDs so the JS factory can target it independently. --}}
        <div class="mcp-el mcp-entry-composer mt-8 sm:mt-10">
            <div class="relative rounded-2xl border border-gray-200 bg-white transition-colors focus-within:border-primary-500 dark:border-gray-700 dark:bg-gray-800">
                <div class="px-4 pt-3.5 pb-1.5 min-h-[60px] text-sm leading-snug">
                    <span id="hero-entry-placeholder" class="hero-composer-placeholder text-gray-400 dark:text-gray-500">Ask anything…</span>
                    <span id="hero-entry-typed" class="hero-composer-typed text-gray-900 dark:text-gray-100"></span>
                    <span class="hero-composer-cursor inline-block w-px h-4 align-middle bg-primary/60 dark:bg-primary/80 ml-px" aria-hidden="true"></span>
                </div>

                <div class="flex items-center justify-between gap-2 px-3 pb-2.5">
                    <div class="flex-1"></div>
                    <div class="flex items-center gap-2">
                        <button type="button" tabindex="-1" class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-pico font-medium text-gray-500 dark:text-gray-400">
                            <span>Auto</span>
                            <x-heroicon-o-chevron-down class="w-3 h-3"/>
                        </button>
                        <button id="hero-entry-send" type="button" tabindex="-1" aria-hidden="true" class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-primary-600 text-white">
                            <x-heroicon-s-arrow-up class="w-4 h-4"/>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Example prompts — three chips spotlighting what users can ask.
             Stack on the smallest widths so they don't crowd. --}}
        <div class="mcp-el mcp-entry-chips mt-5 flex flex-wrap justify-center gap-2 text-xs">
            <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-3 py-1.5 font-medium text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                What's overdue this week?
            </span>
            <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-3 py-1.5 font-medium text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                Show this week's pipeline
            </span>
            <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-3 py-1.5 font-medium text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                Add a new contact
            </span>
        </div>
    </div>
</div>
