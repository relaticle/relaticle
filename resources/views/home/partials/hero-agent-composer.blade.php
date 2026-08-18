{{-- Composer — card-style 2-row layout, mirrors real chat-interface composer --}}
<div class="border-t border-gray-200 bg-white px-4 py-4 dark:border-zinc-700 dark:bg-zinc-900">
    {{-- Docked proposal — mirrors the real pending-proposal dock that replaces the
         composer while a write awaits review. Display is toggled by the heroChat
         script (swap with .mcp-input); opacity is animated separately. --}}
    <div id="hero-dock" class="mcp-dock mx-auto w-full max-w-3xl" style="display: none; opacity: 0;">
        <div class="mb-2 flex items-center gap-1.5 text-micro font-medium text-gray-500 dark:text-zinc-400">
            <x-heroicon-o-sparkles class="h-3.5 w-3.5 text-primary-500 dark:text-primary-400"/>
            <span>Review before continuing</span>
        </div>

        <div class="rounded-2xl border border-gray-200/80 bg-white p-4 shadow-lg shadow-gray-900/[0.04] dark:border-white/10 dark:bg-zinc-900 dark:shadow-black/20">
            <div class="flex items-start gap-3">
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-400/10 dark:text-amber-400" aria-hidden="true">
                    <x-heroicon-o-pencil-square class="h-4 w-4"/>
                </div>
                <div class="min-w-0 flex-1 pt-1">
                    <p class="text-sm font-semibold leading-5 text-gray-900 dark:text-white">Update task "Schedule demo with Kovra Systems"</p>
                </div>
            </div>

            <div class="mt-3 space-y-1.5 ps-11">
                <div class="flex items-start gap-3">
                    <span class="w-28 shrink-0 pt-0.5 text-xs font-medium leading-5 text-gray-500 sm:w-32 dark:text-zinc-400">Status</span>
                    <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-1.5 text-sm">
                        <span class="text-gray-400 line-through decoration-gray-300 dark:text-zinc-500 dark:decoration-zinc-600">To do</span>
                        <x-heroicon-m-arrow-right class="h-3 w-3 text-gray-400 dark:text-zinc-500" aria-hidden="true"/>
                        <span class="font-medium text-gray-900 dark:text-white">Done</span>
                    </span>
                </div>
            </div>

            <div class="mt-4 flex items-center justify-end gap-2 border-t border-gray-100 pt-3 dark:border-white/5">
                <button type="button" tabindex="-1" class="inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium text-gray-600 dark:text-zinc-300">
                    Discard
                </button>
                <button id="hero-approve-btn" type="button" tabindex="-1" class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm">
                    <x-heroicon-o-check class="h-3.5 w-3.5" aria-hidden="true"/>
                    <span>Save changes</span>
                    <kbd class="hidden rounded bg-white/20 px-1 font-sans text-[10px] sm:inline">&#8984;&#9166;</kbd>
                </button>
            </div>
        </div>
    </div>

    <div class="mcp-el mcp-input mx-auto w-full max-w-3xl">
        <div class="relative rounded-2xl border border-gray-200 bg-white transition-colors focus-within:border-primary-500 dark:border-zinc-700 dark:bg-zinc-800">
            {{-- Editor row — placeholder anchored top-left; remaining space mimics a multi-line text input. --}}
            <div class="px-4 pt-3.5 pb-1.5 min-h-[60px] text-sm leading-snug">
                <span id="hero-composer-placeholder" class="hero-composer-placeholder text-gray-400 dark:text-zinc-500">Ask anything…</span>
                <span id="hero-composer-typed" class="hero-composer-typed text-gray-900 dark:text-zinc-100"></span>
                <span id="hero-composer-cursor" class="hero-composer-cursor inline-block w-px h-4 align-middle bg-primary/60 dark:bg-primary/80 ml-px" aria-hidden="true"></span>
            </div>

            {{-- Footer row mirrors the real dashboard composer: spacer on the
                 left, model picker grouped with the send button on the right. --}}
            <div class="flex items-center justify-between gap-2 px-3 pb-2.5">
                <div class="flex-1"></div>
                <div class="flex items-center gap-2">
                    <button type="button" tabindex="-1" class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-pico font-medium text-gray-500 dark:text-zinc-400">
                        <span>Auto</span>
                        <x-heroicon-o-chevron-down class="w-3 h-3"/>
                    </button>
                    <button id="hero-composer-send" type="button" tabindex="-1" aria-hidden="true" class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-primary-600 text-white">
                        <x-heroicon-s-arrow-up class="w-4 h-4"/>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .hero-composer-placeholder.is-hidden { display: none; }
    .hero-composer-cursor { animation: hero-composer-blink 1.05s steps(2, end) infinite; }
    @keyframes hero-composer-blink { to { visibility: hidden; } }
    @media (prefers-reduced-motion: reduce) {
        .hero-composer-cursor { animation: none; }
    }
</style>
