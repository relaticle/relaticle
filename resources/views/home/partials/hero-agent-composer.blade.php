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

        {{-- Internals sit one notch below the real dock card (tile 28px, xs type,
             compact buttons): the frame depicts the app at ~0.7 scale, so
             full-size card chrome would read oversized against the shell. --}}
        <div class="rounded-xl border border-gray-200/80 bg-white p-3 shadow-lg shadow-gray-900/[0.04] dark:border-white/10 dark:bg-zinc-900 dark:shadow-black/20">
            <div class="flex items-start gap-2.5">
                <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-400/10 dark:text-amber-400" aria-hidden="true">
                    <x-heroicon-o-pencil-square class="h-3.5 w-3.5"/>
                </div>
                <div class="min-w-0 flex-1 pt-1">
                    <p class="text-xs font-semibold leading-5 text-gray-900 dark:text-white">Update task "Schedule demo with Kovra Systems"</p>
                </div>
            </div>

            <div class="mt-2.5 space-y-1 ps-9">
                <div class="flex items-start gap-2.5">
                    <span class="w-24 shrink-0 text-micro font-medium text-gray-500 dark:text-zinc-400">Status</span>
                    <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-1.5 text-xs">
                        <span class="text-gray-400 line-through decoration-gray-300 dark:text-zinc-500 dark:decoration-zinc-600">To do</span>
                        <x-heroicon-m-arrow-right class="h-2.5 w-2.5 text-gray-400 dark:text-zinc-500" aria-hidden="true"/>
                        <span class="font-medium text-gray-900 dark:text-white">Done</span>
                    </span>
                </div>
            </div>

            <div class="mt-3 flex items-center justify-end gap-2 border-t border-gray-100 pt-2.5 dark:border-white/5">
                <button type="button" tabindex="-1" class="inline-flex items-center rounded-lg px-2.5 py-1.5 text-xs font-medium text-gray-600 dark:text-zinc-300">
                    Discard
                </button>
                <button id="hero-approve-btn" type="button" tabindex="-1" class="inline-flex items-center gap-1 rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm">
                    <x-heroicon-o-check class="h-3 w-3" aria-hidden="true"/>
                    <span>Save changes</span>
                    <kbd class="hidden rounded bg-white/20 px-1 font-sans text-pico sm:inline">&#8984;&#9166;</kbd>
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
