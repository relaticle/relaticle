{{-- Static twin of the real composer. Every control below mirrors
     packages/Chat/resources/views/livewire/chat/partials/_composer-bar.blade.php
     and _composer.blade.php: rounded-2xl container, model picker with a
     chevron-up-down, the push-to-talk mic, and a ROUND send button that reads
     gray while the composer is empty. --}}

<div class="border-t border-gray-200 bg-white px-4 py-4 dark:border-zinc-700 dark:bg-zinc-900">
    {{-- Docked proposal. Mirrors _composer.blade.php: the composer is hidden
         entirely while a write awaits review, replaced by the "Review before
         continuing" label plus the proposal card. Display is toggled by the
         heroChat script (swap with .mcp-input); opacity animates separately. --}}
    <div id="hero-dock" class="mcp-dock mx-auto w-full max-w-3xl" style="display: none; opacity: 0;">
        <div class="mb-2 flex items-center gap-1.5 text-xs font-medium text-gray-500 dark:text-zinc-400">
            <x-heroicon-o-sparkles class="h-3.5 w-3.5 text-primary-500 dark:text-primary-400"/>
            <span>Review before continuing</span>
        </div>

        {{-- Solid block tier with a hairline header/footer, matching
             proposal-card.blade.php and the read-result blocks. --}}
        <div class="overflow-hidden rounded-xl border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)]">
            {{-- Update variant (exchange 2). --}}
            <div class="mcp-dock-update px-4 py-3">
                <div class="flex items-start gap-2.5">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-amber-50 text-amber-600 dark:bg-amber-400/10 dark:text-amber-400" aria-hidden="true">
                        <x-heroicon-o-pencil-square class="h-3.5 w-3.5"/>
                    </span>
                    <p class="min-w-0 flex-1 text-sm font-medium leading-6 text-gray-900 dark:text-white">
                        Update task "Schedule demo with Kovra Systems"
                    </p>
                </div>

                <div class="mt-2 space-y-2 ps-[2.125rem]">
                    <div class="flex items-start gap-2.5">
                        <span class="w-24 shrink-0 text-micro font-medium leading-5 text-gray-400 dark:text-gray-500">Status</span>
                        <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-1.5 text-xs">
                            <span class="text-gray-400 line-through decoration-gray-300 dark:text-gray-500 dark:decoration-gray-600">To do</span>
                            <x-heroicon-m-arrow-right class="h-2.5 w-2.5 text-gray-400 dark:text-gray-500" aria-hidden="true"/>
                            <span class="font-medium text-gray-900 dark:text-white">Done</span>
                        </span>
                    </div>
                </div>
            </div>

            {{-- Create variant (exchange 3). A create proposal shows the fields
                 it is about to write, with no old value to strike through. --}}
            <div class="mcp-dock-create px-4 py-3" style="display: none;">
                <div class="flex items-start gap-2.5">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-blue-50 text-blue-600 dark:bg-blue-400/10 dark:text-blue-400" aria-hidden="true">
                        <x-heroicon-o-plus class="h-3.5 w-3.5"/>
                    </span>
                    <p class="min-w-0 flex-1 text-sm font-medium leading-6 text-gray-900 dark:text-white">
                        Create person "Sarah Chen"
                    </p>
                </div>

                <div class="mt-2 space-y-2 ps-[2.125rem]">
                    @foreach ([
                        ['label' => 'Name', 'value' => 'Sarah Chen'],
                        ['label' => 'Job title', 'value' => 'VP of Engineering'],
                        ['label' => 'Company', 'value' => 'Kovra Systems'],
                    ] as $heroDockField)
                        <div class="flex items-start gap-2.5">
                            <span class="w-24 shrink-0 text-micro font-medium leading-5 text-gray-400 dark:text-gray-500">{{ $heroDockField['label'] }}</span>
                            <span class="min-w-0 flex-1 text-xs font-medium text-gray-900 dark:text-white">{{ $heroDockField['value'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 px-4 py-2 dark:border-white/5">
                <button type="button" tabindex="-1" class="inline-flex items-center rounded-lg px-2.5 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-300">
                    Discard
                </button>
                {{-- Label follows the operation, matching proposal-card.blade.php's
                     $primaryLabel match: update reads "Save changes", create "Create". --}}
                <button id="hero-approve-btn" type="button" tabindex="-1" class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm">
                    <x-heroicon-o-check class="h-3 w-3" aria-hidden="true"/>
                    <span class="mcp-dock-label">Save changes</span>
                    <kbd class="hidden rounded bg-white/20 px-1 py-0.5 font-sans text-pico font-medium sm:inline">&#8984;&#9166;</kbd>
                </button>
            </div>
        </div>
    </div>

    <div class="mcp-el mcp-input mx-auto w-full max-w-3xl">
        <div class="relative rounded-2xl border border-[var(--surface-input-border)] bg-white transition focus-within:border-primary-400 dark:bg-gray-900 dark:focus-within:border-primary-500/60">
            {{-- Editor row: placeholder anchored top-left, then the typed text. --}}
            <div class="px-4 pt-3.5 pb-1.5 min-h-[60px] text-sm leading-snug">
                <span id="hero-composer-placeholder" class="hero-composer-placeholder text-gray-400 dark:text-gray-500">Ask anything...</span>
                <span id="hero-composer-typed" class="hero-composer-typed text-gray-900 dark:text-gray-100"></span>
                <span id="hero-composer-cursor" class="hero-composer-cursor inline-block w-px h-4 align-middle bg-primary/60 dark:bg-primary/80 ml-px" aria-hidden="true"></span>
            </div>

            {{-- Controls row: model picker, mic, send. Grouped at the end, same
                 order and spacing as _composer-bar. --}}
            <div class="flex items-center gap-2 px-3 pb-2">
                <div class="ms-auto flex items-center gap-1.5">
                    <span class="inline-flex h-7 items-center gap-1 rounded-md border border-transparent px-2 text-xs font-medium text-gray-600 dark:text-gray-300">
                        <span>Auto</span>
                        <x-heroicon-o-chevron-up-down class="h-3 w-3" aria-hidden="true"/>
                    </span>

                    {{-- Push-to-talk. Present in the real composer whenever a
                         transcription provider key is configured. --}}
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-gray-500 dark:text-gray-400">
                        <x-heroicon-o-microphone class="h-4 w-4" aria-hidden="true"/>
                    </span>

                    {{-- ROUND, and gray while empty: the real send button carries
                         disabled:bg-gray-200 disabled:text-gray-400, so a
                         saturated purple square never appears on an idle
                         composer. The script tints it while a prompt is typed. --}}
                    <button id="hero-composer-send" type="button" tabindex="-1" aria-hidden="true" class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-200 text-gray-400 dark:bg-gray-700 dark:text-gray-500">
                        <x-heroicon-s-arrow-up class="h-4 w-4"/>
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
