{{-- One step of a docked plan (or the whole card, when the plan has one step).

     Expects: $step (the view model from ProposalCard::stepViews()), $isPlan.

     A step always shows its own fields. A plan is approved as one decision, so
     hiding a step behind a "show details" toggle would mean approving writes the
     user was never shown. --}}
@php
    $entityIcon = \Relaticle\Chat\Support\RecordChipRenderer::iconPath($step['entity_type']);
    $operationLabel = match ($step['operation']) {
        'update' => __('Update'),
        'delete' => __('Delete'),
        default => __('Create'),
    };
    $blockedBy = $step['blockedBy'] ?? [];
@endphp

<div
    @class([
        'group/step relative',
        'px-4 py-3' => ! $isPlan,
        'py-2.5 pe-3 ps-11' => $isPlan,
    ])
    wire:key="step-{{ $step['id'] }}"
>
    @if ($isPlan)
        {{-- Left rail: the step's number, and the connector that makes the order
             legible at a glance. The connector is drawn from the number down to
             the next step, so the last one has none. --}}
        <span
            @class([
                'absolute start-4 top-3.5 z-10 flex h-5 w-5 items-center justify-center rounded-full text-[length:var(--text-pico)] font-semibold tabular-nums ring-2 ring-white transition dark:ring-gray-900',
                'bg-primary-600 text-white' => $step['isActive'],
                'bg-gray-200 text-gray-600 dark:bg-white/10 dark:text-gray-300' => ! $step['isActive'],
            ])
            aria-hidden="true"
        >{{ $step['position'] }}</span>

        <span class="absolute bottom-0 start-[1.6rem] top-8 w-px bg-gray-200 group-last/step:hidden dark:bg-white/10" aria-hidden="true"></span>
    @endif

    <div class="flex items-start gap-2.5">
        <span
            @class([
                'flex h-6 w-6 shrink-0 items-center justify-center rounded-md',
                'bg-blue-50 text-blue-600 dark:bg-blue-400/10 dark:text-blue-400' => $step['operation'] === 'create',
                'bg-amber-50 text-amber-600 dark:bg-amber-400/10 dark:text-amber-400' => $step['operation'] === 'update',
                'bg-red-50 text-red-600 dark:bg-red-400/10 dark:text-red-400' => $step['operation'] === 'delete',
            ])
            aria-hidden="true"
        >
            @if ($entityIcon)
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $entityIcon }}" />
                </svg>
            @elseif ($step['operation'] === 'delete')
                <x-heroicon-o-trash class="h-3.5 w-3.5" />
            @elseif ($step['operation'] === 'update')
                <x-heroicon-o-pencil-square class="h-3.5 w-3.5" />
            @else
                <x-heroicon-o-plus class="h-3.5 w-3.5" />
            @endif
        </span>

        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold leading-5 text-gray-900 dark:text-white">{{ $step['summary'] }}</p>

            @if ($blockedBy !== [])
                <p class="mt-0.5 text-[length:var(--text-micro)] text-gray-400 dark:text-gray-500">
                    {{ trans_choice('Runs after step :steps|Runs after steps :steps', count($blockedBy), ['steps' => implode(', ', $blockedBy)]) }}
                </p>
            @endif
        </div>

        {{-- Per-record pager, for a step proposing several records of one type.
             Not gated on the step being active: a plan is approved as one
             decision, so every step's records have to be reachable before the
             user commits to them. Paging a step focuses it. --}}
        @if ($step['isBatch'] && $step['remainingCount'] > 1)
            <div class="flex shrink-0 items-center gap-0.5">
                <button
                    type="button"
                    wire:click="stepPrev('{{ $step['id'] }}')"
                    @disabled($step['isActive'] && $step['position_in_batch'] <= 1)
                    class="inline-flex h-6 w-6 items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent dark:hover:bg-white/5 dark:hover:text-gray-300"
                    aria-label="{{ __('Previous record') }}"
                >
                    <x-heroicon-o-chevron-left class="h-3.5 w-3.5" aria-hidden="true" />
                </button>

                <span
                    class="select-none px-0.5 text-xs font-medium tabular-nums text-gray-400 dark:text-gray-500"
                    aria-live="polite"
                    aria-label="{{ __('Record :position of :total', ['position' => $step['position_in_batch'], 'total' => $step['remainingCount']]) }}"
                >{{ $step['position_in_batch'] }}&hairsp;/&hairsp;{{ $step['remainingCount'] }}</span>

                <button
                    type="button"
                    wire:click="stepNext('{{ $step['id'] }}')"
                    @disabled($step['isActive'] && $step['position_in_batch'] >= $step['remainingCount'])
                    class="inline-flex h-6 w-6 items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent dark:hover:bg-white/5 dark:hover:text-gray-300"
                    aria-label="{{ __('Next record') }}"
                >
                    <x-heroicon-o-chevron-right class="h-3.5 w-3.5" aria-hidden="true" />
                </button>
            </div>
        @endif

        {{-- Per-step decision. Only a plan shows these: a single proposal is
             decided by the footer, and two competing sets of buttons on one card
             is exactly the ambiguity this dock exists to avoid. --}}
        @if ($isPlan)
            <button
                type="button"
                wire:click="approveStep(@js($step['id']))"
                wire:loading.attr="disabled"
                @disabled($blockedBy !== [])
                @class([
                    'inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-gray-400 opacity-0 transition focus-visible:opacity-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 group-hover/step:opacity-100',
                    'hover:bg-primary-50 hover:text-primary-600 dark:hover:bg-primary-400/10 dark:hover:text-primary-400' => $blockedBy === [],
                    'cursor-not-allowed' => $blockedBy !== [],
                ])
                aria-label="{{ __('Approve only this step') }}"
                title="{{ $blockedBy === [] ? __('Approve only this step') : __('Approve the earlier step it links to first') }}"
            >
                <x-heroicon-o-check class="h-4 w-4" aria-hidden="true" />
            </button>

            <button
                type="button"
                wire:click="rejectStep(@js($step['id']))"
                wire:loading.attr="disabled"
                class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-gray-400 opacity-0 transition hover:bg-red-50 hover:text-red-600 focus-visible:opacity-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-500 group-hover/step:opacity-100 dark:hover:bg-red-400/10 dark:hover:text-red-400"
                aria-label="{{ __('Remove this step') }}"
                title="{{ __('Remove this step') }}"
            >
                <x-heroicon-o-x-mark class="h-4 w-4" aria-hidden="true" />
            </button>
        @endif
    </div>

    @if (! empty($step['duplicateWarning']))
        <div class="mt-2 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-400/10 dark:text-amber-200">
            <x-heroicon-o-exclamation-triangle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <span>{{ $step['duplicateWarning'] }}</span>
        </div>
    @endif

    <div @class([
        'mt-2 space-y-2',
        {{-- Align the fields with the step title rather than the tile: one left
             edge per card, the way the block headers read. --}}
        'ps-[2.125rem]' => ! $isPlan,
    ])>
        @foreach ($step['fields'] as $row)
            @include('chat::livewire.chat.partials._dock-field', [
                'row' => $row,
                'stepId' => $step['id'],
                'isEditable' => ($row['code'] ?? null) !== null && in_array($row['code'], $step['editableCodes'], true),
                'isEditing' => $editingFieldCode !== null && $editingFieldCode === ($row['code'] ?? null) && $editingStepId === $step['id'],
            ])
        @endforeach
    </div>
</div>
