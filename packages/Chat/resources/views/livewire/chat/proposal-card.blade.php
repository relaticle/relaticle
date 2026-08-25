@php
    $operation = $proposal?->operation->value;
    $stepCount = count($steps);
    // A plan's footer decides every remaining step at once, and says so with a
    // count. A standalone card (single record, or a paginated batch showing one
    // record at a time) decides exactly the record on screen, so its labels are
    // singular: the card can never commit anything that is not rendered.
    $isBatch = ! $isPlan && ($steps[0]['isBatch'] ?? false);
    $primaryLabel = $isPlan
        ? __('Approve all :count', ['count' => $stepCount])
        : match ($operation) {
            'update' => __('Save changes'),
            'delete' => __('Delete'),
            default => __('Create'),
        };
    $primaryAction = $isPlan ? 'approveAll' : 'createCurrent';
    $discardLabel = $isPlan ? __('Discard all') : __('Discard');
    $discardAction = $isPlan ? 'discardAll' : 'discardCurrent';
    $isDestructive = $operation === 'delete' && ! $isPlan;
    $activePosition = $steps[0]['activeItemPosition'] ?? 1;

    // Every change of an update unchecked: approving would write nothing, so
    // the confirm is disabled rather than minting an "Approved" no-op.
    $allChangesExcluded = ! $isPlan
        && $operation === 'update'
        && ($steps[0]['excludableCodes'] ?? []) !== []
        && array_diff($steps[0]['excludableCodes'], $excludedFields) === [];
    $primaryBlocked = $editingFieldCode !== null || $allChangesExcluded;
    $primaryBlockedHint = $editingFieldCode !== null
        ? __('Finish editing the field first')
        : __('Every change is unchecked; there is nothing to save');
@endphp

<div class="flex min-h-0 flex-col">
    @if ($proposal)
        {{-- Surface: the solid data-block tier (crisp hairline card, no shadow)
             shared with the read-result blocks, plus a soft primary halo unique
             to the dock (Attio-style): this is the one card on screen asking
             for a decision, and the halo is what separates it from the passive
             data blocks above without shouting. --}}
        {{-- While a field is being edited the card must NOT clip: a Select renders
             its panel as an absolutely-positioned child (Filament positions it
             itself, there is no teleport to opt into), so `overflow-hidden` here
             and `overflow-y-auto` on the steps below cut the options list off --
             measured: a panel spanning 497-737 inside a card starting at 638 lost
             141px, leaving the list unreadable above the input. Clipping returns
             the moment the edit closes, so the rounded corners and the tall-plan
             scroller behave normally the rest of the time. --}}
        <div @class([
            'flex min-h-0 flex-col rounded-xl border border-primary-200 bg-[var(--surface-block-bg)] ring-[3px] ring-primary-100 dark:border-primary-400/30 dark:ring-primary-400/10',
            'overflow-hidden' => $editingFieldCode === null,
        ])>
            @if ($isPlan)
                {{-- Plan header: the card is one decision over several writes, so it
                     says how many and in what order they run. --}}
                <div class="flex shrink-0 items-center gap-2.5 border-b border-gray-100 px-4 py-2.5 dark:border-white/5">
                    {{-- Neutral tile, matching the block headers (records_table,
                         record_card): the coloured tiles belong to the STEP rows,
                         where the tint actually distinguishes create from delete.
                         A second colour on the header competed with them. --}}
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400" aria-hidden="true">
                        <x-heroicon-o-queue-list class="h-3.5 w-3.5" />
                    </span>

                    <p class="min-w-0 flex-1 truncate text-sm font-semibold leading-5 text-gray-900 dark:text-white">
                        {{ trans_choice(':count step|:count steps', $stepCount, ['count' => $stepCount]) }}
                    </p>

                    {{-- Right-aligned meta, same slot the records_table header uses
                         for its truncation count. --}}
                    <span class="shrink-0 text-[length:var(--text-micro)] text-gray-400 dark:text-gray-500">
                        {{ __('Approved together, in order') }}
                    </span>
                </div>
            @endif

            {{-- A plan can be taller than the dock: the steps scroll, so the
                 decision buttons never leave the viewport. --}}
            <div @class([
                'min-h-0 flex-1',
                'overflow-y-auto overscroll-contain' => $editingFieldCode === null,
                'divide-y divide-gray-100 dark:divide-white/5' => $isPlan,
            ])>
                @foreach ($steps as $step)
                    @include('chat::livewire.chat.partials._dock-step', ['step' => $step, 'isPlan' => $isPlan])
                @endforeach
            </div>

            @error('resolve')
                <p class="mx-4 mb-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-400/10 dark:text-red-400" role="alert">
                    {{ $message }}
                </p>
            @enderror

            {{-- Footer: the decision. Separated by a hairline so it reads as one
                 deliberate step. Styled after the Attio reference (user-directed,
                 2026-08): pagination on the left for a batch, then a plain-text
                 discard and a brand-colored confirm, with the genuinely
                 destructive delete in red. Both buttons decide only the record
                 on screen. --}}
            <div class="flex shrink-0 items-center gap-2 border-t border-gray-100 px-4 py-2 dark:border-white/5">
                @if ($isBatch && $remainingCount > 1)
                    {{-- Attio-style pagination over the still-undecided records.
                         Arrows clamp at the ends; deciding a record advances
                         automatically. aria-live announces the position as it
                         changes. --}}
                    <div class="flex items-center gap-1 text-xs font-medium tabular-nums text-gray-500 dark:text-gray-400">
                        <button
                            type="button"
                            wire:click="prevItem"
                            wire:loading.attr="disabled"
                            @disabled($activePosition <= 1)
                            aria-label="{{ __('Previous record') }}"
                            title="{{ __('Previous record') }}"
                            class="inline-flex h-6 w-6 items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-gray-400 dark:hover:bg-white/10 dark:hover:text-gray-200 dark:disabled:hover:bg-transparent"
                        >
                            <x-heroicon-m-chevron-left class="h-3.5 w-3.5" aria-hidden="true" />
                        </button>

                        <span aria-live="polite" class="select-none px-0.5">{{ $activePosition }}/{{ $remainingCount }}</span>

                        <button
                            type="button"
                            wire:click="nextItem"
                            wire:loading.attr="disabled"
                            @disabled($activePosition >= $remainingCount)
                            aria-label="{{ __('Next record') }}"
                            title="{{ __('Next record') }}"
                            class="inline-flex h-6 w-6 items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-gray-400 dark:hover:bg-white/10 dark:hover:text-gray-200 dark:disabled:hover:bg-transparent"
                        >
                            <x-heroicon-m-chevron-right class="h-3.5 w-3.5" aria-hidden="true" />
                        </button>
                    </div>
                @endif

                <div class="ms-auto flex items-center gap-2">
                <button
                    type="button"
                    wire:click="{{ $discardAction }}"
                    wire:loading.attr="disabled"
                    @disabled($editingFieldCode !== null)
                    @if ($editingFieldCode !== null) title="{{ __('Finish editing the field first') }}" @endif
                    @class([
                        'inline-flex h-7 items-center rounded-md px-2.5 text-xs font-medium text-gray-600 transition hover:bg-gray-100 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 motion-safe:active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white dark:disabled:hover:bg-transparent',
                        'opacity-50' => $editingFieldCode !== null,
                    ])
                >
                    {{ $discardLabel }}
                </button>

                <button
                    type="button"
                    wire:click="{{ $primaryAction }}"
                    wire:loading.attr="disabled"
                    @disabled($primaryBlocked)
                    @if ($primaryBlocked) title="{{ $primaryBlockedHint }}" @endif
                    @class([
                        'inline-flex h-7 items-center gap-1.5 rounded-md px-2.5 text-xs font-medium shadow-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 motion-safe:active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60',
                        'bg-red-600 text-white hover:bg-red-500 focus-visible:outline-red-500' => $isDestructive,
                        'bg-primary-600 text-white hover:bg-primary-500 focus-visible:outline-primary-500' => ! $isDestructive,
                        'opacity-50' => $primaryBlocked,
                    ])
                >
                    <x-heroicon-o-arrow-path class="h-3 w-3 motion-safe:animate-spin" wire:loading wire:target="{{ $primaryAction }}" aria-hidden="true" />
                    <span>{{ $primaryLabel }}</span>
                    <kbd
                        x-data
                        x-text="/Mac|iP/.test(navigator.platform) ? '⌘⏎' : 'Ctrl+⏎'"
                        class="hidden rounded bg-white/20 px-1 py-0.5 font-sans text-[length:var(--text-pico)] font-medium sm:inline"
                    ></kbd>
                </button>
                </div>
            </div>
        </div>
    @endif

    <x-filament-actions::modals />
</div>
