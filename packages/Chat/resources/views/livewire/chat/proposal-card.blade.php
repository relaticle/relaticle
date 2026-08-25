@php
    $operation = $proposal?->operation->value;
    $stepCount = count($steps);
    // The footer always decides everything that remains: the whole plan, every
    // undecided record of a batch, or the single record. The label says so with
    // a count, so one click can never read as a one-record commit.
    $isBatch = ! $isPlan && ($steps[0]['isBatch'] ?? false);
    $primaryLabel = match (true) {
        $isPlan => __('Approve all :count', ['count' => $stepCount]),
        $isBatch && $remainingCount > 1 => match ($operation) {
            'update' => __('Save all :count', ['count' => $remainingCount]),
            'delete' => __('Delete all :count', ['count' => $remainingCount]),
            default => __('Create all :count', ['count' => $remainingCount]),
        },
        default => match ($operation) {
            'update' => __('Save changes'),
            'delete' => __('Delete'),
            default => __('Create'),
        },
    };
    $primaryAction = $isPlan ? 'approveAll' : 'createCurrent';
    $discardLabel = $isPlan || ($isBatch && $remainingCount > 1) ? __('Discard all') : __('Discard');
    $discardAction = $isPlan ? 'discardAll' : 'discardCurrent';
    $isDestructive = $operation === 'delete' && ! $isPlan;
@endphp

<div class="flex min-h-0 flex-col">
    @if ($proposal)
        {{-- Surface: the solid data-block tier (crisp hairline card, no shadow)
             shared with the read-result blocks, so a proposal reads as the same
             kind of object as the data around it. --}}
        {{-- While a field is being edited the card must NOT clip: a Select renders
             its panel as an absolutely-positioned child (Filament positions it
             itself, there is no teleport to opt into), so `overflow-hidden` here
             and `overflow-y-auto` on the steps below cut the options list off --
             measured: a panel spanning 497-737 inside a card starting at 638 lost
             141px, leaving the list unreadable above the input. Clipping returns
             the moment the edit closes, so the rounded corners and the tall-plan
             scroller behave normally the rest of the time. --}}
        <div @class([
            'flex min-h-0 flex-col rounded-xl border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)]',
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
                 deliberate step. The confirm is monochrome, not brand-colored:
                 approving is the routine end of every proposal, and only the
                 genuinely destructive delete earns a color. --}}
            <div class="flex shrink-0 items-center justify-end gap-2 border-t border-gray-100 bg-gray-50/60 px-4 py-2 dark:border-white/5 dark:bg-white/[0.02]">
                <button
                    type="button"
                    wire:click="{{ $discardAction }}"
                    wire:loading.attr="disabled"
                    @disabled($editingFieldCode !== null)
                    @if ($editingFieldCode !== null) title="{{ __('Finish editing the field first') }}" @endif
                    @class([
                        'inline-flex h-7 items-center rounded-md border border-gray-200 bg-white px-2.5 text-xs font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 motion-safe:active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-white dark:border-white/10 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white dark:disabled:hover:bg-white/5',
                        'opacity-50' => $editingFieldCode !== null,
                    ])
                >
                    {{ $discardLabel }}
                </button>

                <button
                    type="button"
                    wire:click="{{ $primaryAction }}"
                    wire:loading.attr="disabled"
                    @disabled($editingFieldCode !== null)
                    @if ($editingFieldCode !== null) title="{{ __('Finish editing the field first') }}" @endif
                    @class([
                        'inline-flex h-7 items-center gap-1.5 rounded-md px-2.5 text-xs font-medium shadow-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 motion-safe:active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60',
                        'bg-red-600 text-white hover:bg-red-500 focus-visible:outline-red-500' => $isDestructive,
                        'bg-gray-900 text-white hover:bg-gray-800 focus-visible:outline-primary-500 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-100' => ! $isDestructive,
                        'opacity-50' => $editingFieldCode !== null,
                    ])
                >
                    <x-heroicon-o-arrow-path class="h-3 w-3 motion-safe:animate-spin" wire:loading wire:target="{{ $primaryAction }}" aria-hidden="true" />
                    <span>{{ $primaryLabel }}</span>
                    <kbd
                        x-data
                        x-text="/Mac|iP/.test(navigator.platform) ? '⌘⏎' : 'Ctrl+⏎'"
                        @class([
                            'hidden rounded px-1 py-0.5 font-sans text-[length:var(--text-pico)] font-medium sm:inline',
                            'bg-white/20' => $isDestructive,
                            'bg-white/15 dark:bg-gray-900/10' => ! $isDestructive,
                        ])
                    ></kbd>
                </button>
            </div>
        </div>
    @endif

    <x-filament-actions::modals />
</div>
