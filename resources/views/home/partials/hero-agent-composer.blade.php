{{-- Static twin of the real composer. Every control below mirrors
     packages/Chat/resources/views/livewire/chat/partials/_composer-bar.blade.php
     and _composer.blade.php: rounded-2xl container, model picker with a
     chevron-up-down, the push-to-talk mic, and a ROUND send button that reads
     gray while the composer is empty. --}}

@php
    $heroDockIcons = [
        'task' => \Relaticle\Chat\Support\RecordChipRenderer::iconPath('task'),
        'people' => \Relaticle\Chat\Support\RecordChipRenderer::iconPath('people'),
    ];
@endphp

<div class="border-t border-gray-200 bg-white px-4 py-4 dark:border-zinc-700 dark:bg-zinc-900">
    {{-- Docked proposal. Mirrors _composer.blade.php: the composer is hidden
         entirely while a write awaits review, and the card carries a soft
         primary halo instead of an eyebrow -- it is the one thing on screen
         asking for a decision, so the tint is the label. "Review before
         continuing" survives only for screen readers. Display is toggled by
         the heroChat script (swap with .mcp-input); opacity animates
         separately. --}}
    <div id="hero-dock" class="mcp-dock mx-auto w-full max-w-3xl" style="display: none; opacity: 0;">
        <span class="sr-only">Review before continuing</span>

        {{-- Data-block tier plus the dock's halo, matching proposal-card.blade.php. --}}
        <div class="overflow-hidden rounded-xl border border-primary-200 bg-[var(--surface-block-bg)] ring-[3px] ring-primary-100 dark:border-primary-400/30 dark:ring-primary-400/10">
            {{-- Update variant (exchange 2). _dock-step's Attio-shaped header:
                 the card title as a muted eyebrow, then the record identity on
                 its own row behind an operation-tinted entity tile. --}}
            <div class="mcp-dock-update">
                <div class="flex items-center gap-2 px-4 pt-3 text-xs font-medium text-gray-500 dark:text-gray-400">
                    <span class="min-w-0 flex-1 truncate">Update Task</span>
                </div>

                <div class="flex min-w-0 items-center gap-2.5 px-4 pb-2.5 pt-1.5" data-proposal-record-chip data-record-type="task">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-amber-500 text-white" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $heroDockIcons['task'] }}"/>
                        </svg>
                    </span>
                    <p class="min-w-0 truncate text-sm font-semibold leading-5 text-gray-900 dark:text-white">Schedule demo with Kovra Systems</p>
                </div>

                {{-- Attribute table. The checkbox column is the write itself:
                     an unchecked attribute is simply not written. --}}
                <div class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-white/5 dark:border-white/5">
                    <div class="flex items-center gap-3 px-4 py-2 text-xs font-medium text-gray-400 dark:text-gray-500">
                        <x-home.hero-dock-checkbox/>
                        <span class="w-32 shrink-0 sm:w-40">Attribute</span>
                        <span>New value</span>
                    </div>

                    <div class="px-4 py-2.5">
                        <div class="flex items-start gap-3">
                            <x-home.hero-dock-checkbox class="mt-0.5"/>
                            <span class="w-32 shrink-0 truncate text-sm leading-5 text-gray-700 sm:w-40 dark:text-gray-300">Status</span>
                            <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-1.5 gap-y-0.5 text-sm">
                                <span class="text-gray-400 line-through decoration-gray-300 dark:text-gray-500 dark:decoration-gray-600">To do</span>
                                <x-heroicon-m-arrow-right class="h-3 w-3 text-gray-400 dark:text-gray-500" aria-hidden="true"/>
                                <span class="text-gray-700 dark:text-gray-300">Done</span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Create variant (exchange 3). A create shows the fields it is
                 about to write, with no old value to strike through, and locks
                 the identity field: a create without it fails validation. --}}
            <div class="mcp-dock-create" style="display: none;">
                <div class="flex items-center gap-2 px-4 pt-3 text-xs font-medium text-gray-500 dark:text-gray-400">
                    <span class="min-w-0 flex-1 truncate">Create Person</span>
                </div>

                <div class="flex min-w-0 items-center gap-2.5 px-4 pb-2.5 pt-1.5" data-proposal-record-chip data-record-type="people">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-primary-600 text-white" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $heroDockIcons['people'] }}"/>
                        </svg>
                    </span>
                    <p class="min-w-0 truncate text-sm font-semibold leading-5 text-gray-900 dark:text-white">Sarah Chen</p>
                </div>

                <div class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-white/5 dark:border-white/5">
                    <div class="flex items-center gap-3 px-4 py-2 text-xs font-medium text-gray-400 dark:text-gray-500">
                        <x-home.hero-dock-checkbox/>
                        <span class="w-32 shrink-0 sm:w-40">Attribute</span>
                        <span>New value</span>
                    </div>

                    @foreach ([
                        ['label' => 'Name', 'value' => 'Sarah Chen', 'locked' => true],
                        ['label' => 'Job title', 'value' => 'VP of Engineering', 'locked' => false],
                        ['label' => 'Company', 'value' => 'Kovra Systems', 'locked' => false],
                    ] as $heroDockField)
                        <div class="px-4 py-2.5">
                            <div class="flex items-start gap-3">
                                <x-home.hero-dock-checkbox class="mt-0.5" :locked="$heroDockField['locked']"/>
                                <span class="w-32 shrink-0 truncate text-sm leading-5 text-gray-700 sm:w-40 dark:text-gray-300">{{ $heroDockField['label'] }}</span>
                                <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-1.5 gap-y-0.5 text-sm text-gray-700 dark:text-gray-300">{{ $heroDockField['value'] }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Footer: the decision. A plain-text discard beside a brand-colored
                 confirm, matching proposal-card.blade.php. --}}
            <div class="flex items-center gap-2 border-t border-gray-100 px-4 py-2 dark:border-white/5">
                <div class="ms-auto flex items-center gap-2">
                    <button type="button" tabindex="-1" class="inline-flex h-7 items-center rounded-md px-2.5 text-xs font-medium text-gray-600 dark:text-gray-300">
                        Discard
                    </button>
                    {{-- Label follows the operation, matching proposal-card.blade.php's
                         $primaryLabel match: update reads "Save changes", create "Create". --}}
                    <button id="hero-approve-btn" type="button" tabindex="-1" class="inline-flex h-7 items-center gap-1.5 rounded-md bg-primary-600 px-2.5 text-xs font-medium text-white shadow-sm">
                        <span class="mcp-dock-label">Save changes</span>
                        <kbd class="hidden rounded bg-white/20 px-1 py-0.5 font-sans text-pico font-medium sm:inline">&#8984;&#9166;</kbd>
                    </button>
                </div>
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
