{{-- Composer — card-style 2-row layout, mirrors real chat-interface composer --}}
<div class="border-t border-gray-200 bg-white px-4 py-4 dark:border-gray-700 dark:bg-gray-900">
    <div class="mcp-el mcp-input mx-auto w-full max-w-3xl">
        <div class="relative rounded-2xl border border-gray-200 bg-white transition-colors focus-within:border-primary-500 dark:border-gray-700 dark:bg-gray-800">
            {{-- Editor row — placeholder anchored top-left; remaining space mimics a multi-line text input. --}}
            <div class="px-4 pt-3.5 pb-1.5 min-h-[60px] text-sm leading-snug">
                <span id="hero-composer-placeholder" class="hero-composer-placeholder text-gray-400 dark:text-gray-500">Ask anything…</span>
                <span id="hero-composer-typed" class="hero-composer-typed text-gray-900 dark:text-gray-100"></span>
                <span id="hero-composer-cursor" class="hero-composer-cursor inline-block w-px h-4 align-middle bg-primary/60 dark:bg-primary/80 ml-px" aria-hidden="true"></span>
            </div>

            {{-- Footer row mirrors the real dashboard composer: spacer on the
                 left, model picker grouped with the send button on the right. --}}
            <div class="flex items-center justify-between gap-2 px-3 pb-2.5">
                <div class="flex-1"></div>
                <div class="flex items-center gap-2">
                    <button type="button" tabindex="-1" class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-pico font-medium text-gray-500 dark:text-gray-400">
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
