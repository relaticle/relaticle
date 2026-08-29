{{-- Hero entry phase — mirrors the real /abcd dashboard: centered greeting,
     centered composer, recent-chat link, example chips. Lives as an
     absolutely-positioned overlay above the conversation pane and fades
     out during the entry → conversation transition.
     Visibility is driven by .mcp-el rule (opacity: 0 at rest) and the
     heroChat factory; no x-show so the markup is also visible to the SEO
     crawler and to no-JS users alongside the conversation. --}}
<div class="hero-agent-entry mcp-el absolute inset-x-0 bottom-0 top-10 z-20 flex items-start justify-center bg-gray-50 dark:bg-zinc-950 px-4 sm:px-6 md:px-8 overflow-hidden">
    {{-- pt-12/pt-20 mirrors the real dashboard's `py-16` while leaving headroom
         on the shorter mobile panel (h-[520px]). max-w-2xl keeps the composer
         readable without dominating the panel. --}}
    <div class="mx-auto w-full max-w-2xl pt-12 sm:pt-16 md:pt-20">
        {{-- Greeting --}}
        <div class="text-center">
            <h2 class="mcp-el mcp-entry-greeting text-2xl sm:text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">
                Good morning, Marcus.
            </h2>

            <div class="mcp-el mcp-entry-recent mt-2 inline-flex items-center gap-1.5 text-xs sm:text-sm text-gray-500 dark:text-zinc-400">
                <x-heroicon-o-chat-bubble-left class="h-3.5 w-3.5"/>
                <span>Recent chat · This week's pipeline review</span>
            </div>
        </div>

        {{-- Composer twin — identical to hero-agent-composer.blade.php but
             with entry-scoped IDs so the JS factory can target it independently. --}}
        <div class="mcp-el mcp-entry-composer mt-8 sm:mt-10">
            <div class="relative rounded-2xl border border-[var(--surface-input-border)] bg-white transition focus-within:border-primary-400 dark:bg-gray-900 dark:focus-within:border-primary-500/60">
                <div class="px-4 pt-3.5 pb-1.5 min-h-[60px] text-sm leading-snug">
                    <span id="hero-entry-placeholder" class="hero-composer-placeholder text-gray-400 dark:text-gray-500">Ask anything...</span>
                    <span id="hero-entry-typed" class="hero-composer-typed text-gray-900 dark:text-gray-100"></span>
                    <span class="hero-composer-cursor inline-block w-px h-4 align-middle bg-primary/60 dark:bg-primary/80 ml-px" aria-hidden="true"></span>
                </div>

                <div class="flex items-center gap-2 px-3 pb-2">
                    <div class="ms-auto flex items-center gap-1.5">
                        <span class="inline-flex h-7 items-center gap-1 rounded-md border border-transparent px-2 text-xs font-medium text-gray-600 dark:text-gray-300">
                            <span>Auto</span>
                            <x-heroicon-o-chevron-up-down class="h-3 w-3" aria-hidden="true"/>
                        </span>
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-gray-500 dark:text-gray-400">
                            <x-heroicon-o-microphone class="h-4 w-4" aria-hidden="true"/>
                        </span>
                        <button id="hero-entry-send" type="button" tabindex="-1" aria-hidden="true" class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-200 text-gray-400 dark:bg-gray-700 dark:text-gray-500">
                            <x-heroicon-s-arrow-up class="h-4 w-4"/>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- My Tasks. Mirrors chat::filament.pages.partials.my-tasks, which the
             real dashboard renders below the composer. Shown POPULATED: a
             marketing frame selling an AI CRM should not depict an empty CRM,
             and the real thing puts overdue dates in red with a header count
             and a create action. Presentational only; the preview is inert. --}}
        <div class="mcp-el mcp-entry-tasks mt-10 text-start">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="flex items-baseline gap-2 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    <span>Tasks</span>
                    <span class="text-gray-400 dark:text-gray-500">3</span>
                </h3>
                <div class="flex items-center gap-3">
                    <span class="text-xs text-gray-500 dark:text-gray-400">View all</span>
                    <x-heroicon-o-plus class="h-4 w-4 text-gray-400 dark:text-gray-500"/>
                </div>
            </div>

            <ul class="divide-y divide-[var(--surface-block-border)] overflow-hidden rounded-xl border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)]">
                @foreach ([
                    ['title' => 'Call Sarah Chen', 'due' => 'Aug 24, 2026', 'overdue' => true],
                    ['title' => 'Send proposal to Trellis Labs', 'due' => 'Aug 23, 2026', 'overdue' => true],
                    ['title' => 'Renewal prep for Daniel Okafor', 'due' => 'Sep 2, 2026', 'overdue' => false],
                ] as $heroTask)
                    <li class="flex items-center gap-3 pl-4">
                        {{-- 1px ring, matching the real checkbox: a 2px border
                             outweighed every other control on the page. --}}
                        <span class="flex size-4 flex-shrink-0 items-center justify-center rounded-full border border-gray-400 dark:border-gray-500" aria-hidden="true"></span>
                        <span class="flex flex-1 items-center gap-3 py-3 pr-4">
                            <span class="flex-1 truncate text-sm text-gray-900 dark:text-white">{{ $heroTask['title'] }}</span>
                            <span @class([
                                'text-xs',
                                'text-red-600 dark:text-red-400' => $heroTask['overdue'],
                                'text-gray-500 dark:text-gray-400' => ! $heroTask['overdue'],
                            ])>{{ $heroTask['due'] }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</div>
