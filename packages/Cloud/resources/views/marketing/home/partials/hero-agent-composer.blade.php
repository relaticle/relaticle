{{-- Static twin of the real composer. Every control below mirrors
     packages/Chat/resources/views/livewire/chat/partials/_composer-bar.blade.php
     and _composer.blade.php: rounded-2xl container, attach on the lead side,
     model picker with a chevron-up-down, the push-to-talk mic, and a ROUND
     send button that reads gray while the composer is empty. --}}

@php
    $heroDockIcons = [
        'task' => \Relaticle\Chat\Support\RecordChipRenderer::iconPath('task'),
        'people' => \Relaticle\Chat\Support\RecordChipRenderer::iconPath('people'),
    ];
@endphp

<div x-ref="composerFooter" class="flex shrink-0 flex-col justify-end overflow-hidden border-t border-gray-200 bg-white px-4 py-4 dark:border-zinc-700 dark:bg-zinc-900">
    {{-- The input and the docked proposal share one slot, as in _composer.blade.php. heroChat
         keeps the inactive one out of flow and animates the slot's height between them. --}}
    <div class="relative mx-auto w-full max-w-3xl">
        <div id="hero-dock" class="mcp-dock invisible absolute inset-x-0 bottom-0 w-full space-y-2">
            {{-- Data-block tier plus the dock's halo, matching proposal-card.blade.php. --}}
            <div class="overflow-hidden rounded-xl border border-primary-200 bg-[var(--surface-block-bg)] ring-[3px] ring-primary-100 dark:border-primary-400/30 dark:ring-primary-400/10">
                {{-- Update variant (exchange 2), as _dock-step renders one record. --}}
                <div class="mcp-dock-update">
                    <div class="flex items-start gap-2.5 px-4 pb-1.5 pt-3" data-proposal-record-chip data-record-type="task">
                        <span class="mt-px flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-3.5 w-3.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $heroDockIcons['task'] }}"/>
                            </svg>
                        </span>
                        <p class="min-w-0 truncate text-sm leading-6">
                            <span class="text-gray-500 dark:text-gray-400">Update</span>
                            <span class="font-semibold text-gray-900 dark:text-white">Schedule demo with Kovra Systems</span>
                        </p>
                    </div>
                    <div class="pb-2 pe-3 ps-[3.125rem]">
                            <div class="flex items-center gap-3 text-sm">
                                <span class="w-24 shrink-0 truncate text-xs leading-8 text-gray-500 sm:w-28 dark:text-gray-400">Status</span>
                                <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-1.5 leading-8">
                                    <span class="text-gray-500 line-through decoration-gray-300 dark:text-gray-400 dark:decoration-gray-600">To do</span>
                                    <x-lucide-arrow-right class="h-3 w-3 shrink-0 text-gray-400 dark:text-gray-500" aria-hidden="true"/>
                                    <span class="text-gray-900 dark:text-white">Done</span>
                                </span>
                                <x-cloud::home.hero-dock-field/>
                            </div>
                    </div>
                </div>

                {{-- Create variant (exchange 3). The identity field has no leave-out control. --}}
                <div class="mcp-dock-create hidden">
                    <div class="flex items-start gap-2.5 px-4 pb-1.5 pt-3" data-proposal-record-chip data-record-type="people">
                        <span class="mt-px flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-3.5 w-3.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $heroDockIcons['people'] }}"/>
                            </svg>
                        </span>
                        <p class="min-w-0 truncate text-sm leading-6">
                            <span class="text-gray-500 dark:text-gray-400">Create person</span>
                            <span class="font-semibold text-gray-900 dark:text-white">Sarah Chen</span>
                        </p>
                    </div>
                    <div class="pb-2 pe-3 ps-[3.125rem]">
                            <div class="flex items-center gap-3 text-sm">
                                <span class="w-24 shrink-0 truncate text-xs leading-8 text-gray-500 sm:w-28 dark:text-gray-400">Name</span>
                                <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-1.5 leading-8">
                                    <span class="text-gray-900 dark:text-white">Sarah Chen</span>
                                </span>
                                <x-cloud::home.hero-dock-field :locked="true"/>
                            </div>
                            <div class="flex items-center gap-3 text-sm">
                                <span class="w-24 shrink-0 truncate text-xs leading-8 text-gray-500 sm:w-28 dark:text-gray-400">Job title</span>
                                <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-1.5 leading-8">
                                    <span class="text-gray-900 dark:text-white">VP of Engineering</span>
                                </span>
                                <x-cloud::home.hero-dock-field/>
                            </div>
                            <div class="flex items-center gap-3 text-sm">
                                <span class="w-24 shrink-0 truncate text-xs leading-8 text-gray-500 sm:w-28 dark:text-gray-400">Company</span>
                                <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-1.5 leading-8">
                                    <span class="text-gray-900 dark:text-white">Kovra Systems</span>
                                </span>
                                <x-cloud::home.hero-dock-field/>
                            </div>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-gray-100 px-4 py-2 dark:border-white/5">
                    <button type="button" tabindex="-1" class="inline-flex h-7 items-center rounded-md px-2.5 text-xs font-medium text-gray-600 dark:text-gray-300">
                        Discard
                    </button>
                    <x-cloud::marketing.rela-approve id="hero-approve-btn" tabindex="-1" label="Approve"/>
                </div>
            </div>
        </div>

        <div class="mcp-el mcp-input w-full">
            <div class="relative rounded-2xl border border-[var(--surface-input-border)] bg-white transition focus-within:border-primary-400 dark:bg-gray-900 dark:focus-within:border-primary-500/60">
                {{-- Editor row: placeholder anchored top-left, then the typed text. --}}
                <div class="px-4 pt-3.5 pb-1.5 min-h-[60px] text-sm leading-snug">
                    <span id="hero-composer-placeholder" class="hero-composer-placeholder text-gray-400 dark:text-gray-500">Ask anything...</span>
                    <span id="hero-composer-typed" class="hero-composer-typed text-gray-900 dark:text-gray-100"></span>
                    <span id="hero-composer-cursor" class="hero-composer-cursor inline-block w-px h-4 align-middle bg-primary/60 dark:bg-primary/80 ml-px" aria-hidden="true"></span>
                </div>

                {{-- Controls row: attach leads, model picker, mic and send trail,
                     same order and spacing as _composer-bar. --}}
                <div class="flex items-center gap-2 px-3 pb-2">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-gray-500 dark:text-gray-400">
                        <x-lucide-paperclip class="h-4 w-4" aria-hidden="true"/>
                    </span>

                    <div class="ms-auto flex items-center gap-1.5">
                        <span class="inline-flex h-7 items-center gap-1 rounded-md border border-transparent px-2 text-xs font-medium text-gray-600 dark:text-gray-300">
                            <span>Auto</span>
                            <x-lucide-chevrons-up-down class="h-3 w-3" aria-hidden="true"/>
                        </span>

                        {{-- Push-to-talk. Present in the real composer whenever a
                             transcription provider key is configured. --}}
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-gray-500 dark:text-gray-400">
                            <x-lucide-mic class="h-4 w-4" aria-hidden="true"/>
                        </span>

                        {{-- ROUND, and gray while empty: the real send button carries
                             disabled:bg-gray-200 disabled:text-gray-400, so a
                             saturated purple square never appears on an idle
                             composer. The script tints it while a prompt is typed. --}}
                        <button id="hero-composer-send" type="button" tabindex="-1" aria-hidden="true" class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-200 text-gray-400 dark:bg-gray-700 dark:text-gray-500">
                            <x-lucide-arrow-up class="h-4 w-4"/>
                        </button>
                    </div>
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
