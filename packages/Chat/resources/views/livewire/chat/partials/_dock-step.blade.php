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
    class="group/step relative"
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

    <div @class([
        'flex items-center gap-2.5',
        'px-4 py-2.5' => ! $isPlan,
        'py-2.5 pe-3 ps-11' => $isPlan,
    ])>
        <div class="min-w-0 flex-1">
            <div class="flex min-w-0 items-center gap-2">
                @if ($entityIcon && $step['recordLabel'] !== '')
                    <span class="chat-chip min-w-0" data-proposal-record-chip data-record-type="{{ $step['entity_type'] }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $entityIcon }}" />
                        </svg>
                        <span class="chat-chip-label">{{ $step['recordLabel'] }}</span>
                    </span>
                @else
                    <p class="min-w-0 truncate text-sm font-semibold leading-5 text-gray-900 dark:text-white">{{ $step['summary'] }}</p>
                @endif

                @if ($entityIcon && $step['recordLabel'] !== '')
                    <span
                        @class([
                            'shrink-0 text-[length:var(--text-micro)] font-medium uppercase tracking-wider',
                            'text-blue-600 dark:text-blue-400' => $step['operation'] === 'create',
                            'text-amber-600 dark:text-amber-400' => $step['operation'] === 'update',
                            'text-red-600 dark:text-red-400' => $step['operation'] === 'delete',
                        ])
                    >{{ $operationLabel }}</span>
                @endif
            </div>

            @if ($blockedBy !== [])
                <p class="mt-0.5 text-[length:var(--text-micro)] text-gray-400 dark:text-gray-500">
                    {{ trans_choice('Runs after step :steps|Runs after steps :steps', count($blockedBy), ['steps' => implode(', ', $blockedBy)]) }}
                </p>
            @endif
        </div>

        {{-- Remaining-record count, for a step proposing several records of one
             type. The rows below carry the records themselves. --}}
        @if ($step['isBatch'] && $step['remainingCount'] > 1)
            <span class="select-none px-0.5 text-xs font-medium tabular-nums text-gray-400 dark:text-gray-500" aria-live="polite">
                {{ trans_choice(':count record|:count records', $step['remainingCount'], ['count' => $step['remainingCount']]) }}
            </span>
        @endif

        {{-- Per-step decision. Only a plan shows these: a single proposal is
             decided by the footer, and two competing sets of buttons on one card
             is exactly the ambiguity this dock exists to avoid.

             Hover-revealed from `sm:` up, always visible below it: touch has no
             hover, so gating them on it alone left a plan's only per-step
             controls unreachable on a phone (same defect as the batch row's
             skip). --}}
        @if ($isPlan)
            <button
                type="button"
                wire:click="approveStep(@js($step['id']))"
                wire:loading.attr="disabled"
                @disabled($blockedBy !== [])
                @class([
                    'inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-gray-400 transition focus-visible:opacity-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 sm:opacity-0 sm:group-hover/step:opacity-100',
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
                class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-gray-400 transition hover:bg-red-50 hover:text-red-600 focus-visible:opacity-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-500 sm:opacity-0 sm:group-hover/step:opacity-100 dark:hover:bg-red-400/10 dark:hover:text-red-400"
                aria-label="{{ __('Remove this step') }}"
                title="{{ __('Remove this step') }}"
            >
                <x-heroicon-o-x-mark class="h-4 w-4" aria-hidden="true" />
            </button>
        @endif
    </div>

    @if (! empty($step['duplicateWarning']))
        <div @class([
            'mb-2 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-400/10 dark:text-amber-200',
            'mx-4' => ! $isPlan,
            'me-3 ms-11' => $isPlan,
        ])>
            <x-heroicon-o-exclamation-triangle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <span>{{ $step['duplicateWarning'] }}</span>
        </div>
    @endif

    @if ($step['isBatch'])
        {{-- One row per undecided record, each with its own skip control. The
             active row expands into its editable fields; per-record removal
             lives HERE, on the named row, never in the shared footer: a footer
             per-record discard reads as "dismiss card" and silently skips a
             record the user meant to create. --}}
        <div class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-white/5 dark:border-white/5">
            @foreach ($step['items'] as $item)
                <div wire:key="batch-item-{{ $step['id'] }}-{{ $item['index'] }}">
                    <div @class([
                        'group/item flex items-center gap-1.5 py-1 transition',
                        'pe-2 ps-2' => ! $isPlan,
                        'pe-2 ps-9' => $isPlan,
                        'bg-gray-50 dark:bg-white/5' => $item['isActive'],
                    ])>
                        <button
                            type="button"
                            wire:click="focusItem('{{ $step['id'] }}', {{ $item['index'] }})"
                            data-proposal-batch-row
                            class="min-w-0 flex-1 truncate rounded-md px-2 py-1.5 text-start text-sm text-gray-700 transition hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:text-gray-300 dark:hover:text-white"
                        >
                            {{ $item['summary'] }}
                        </button>

                        <button
                            type="button"
                            wire:click="skipItem('{{ $step['id'] }}', {{ $item['index'] }})"
                            wire:loading.attr="disabled"
                            {{-- Always visible on touch: there is no hover to reveal it
                                 with, and a skip control nobody can find is the whole
                                 defect this row exists to fix. --}}
                            class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-gray-400 transition hover:bg-red-50 hover:text-red-600 focus-visible:opacity-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-500 sm:opacity-0 sm:group-hover/item:opacity-100 dark:hover:bg-red-400/10 dark:hover:text-red-400"
                            aria-label="{{ __('Skip this record') }}"
                            title="{{ __('Skip this record') }}"
                        >
                            <x-heroicon-o-x-mark class="h-4 w-4" aria-hidden="true" />
                        </button>
                    </div>

                    @if ($item['isActive'] && $step['fields'] !== [])
                        <div class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-white/5 dark:border-white/5">
                            @foreach ($step['fields'] as $row)
                                <div @class([
                                    'py-2.5',
                                    'px-4' => ! $isPlan,
                                    'pe-3 ps-11' => $isPlan,
                                ]) data-proposal-field-row>
                                    @include('chat::livewire.chat.partials._dock-field', [
                                        'row' => $row,
                                        'stepId' => $step['id'],
                                        'isEditable' => ($row['code'] ?? null) !== null && in_array($row['code'], $step['editableCodes'], true),
                                        'isEditing' => $editingFieldCode !== null && $editingFieldCode === ($row['code'] ?? null) && $editingStepId === $step['id'],
                                    ])
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @elseif ($step['fields'] !== [])
        <div class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-white/5 dark:border-white/5">
            @foreach ($step['fields'] as $row)
                <div @class([
                    'py-2.5',
                    'px-4' => ! $isPlan,
                    'pe-3 ps-11' => $isPlan,
                ]) data-proposal-field-row>
                    @include('chat::livewire.chat.partials._dock-field', [
                        'row' => $row,
                        'stepId' => $step['id'],
                        'isEditable' => ($row['code'] ?? null) !== null && in_array($row['code'], $step['editableCodes'], true),
                        'isEditing' => $editingFieldCode !== null && $editingFieldCode === ($row['code'] ?? null) && $editingStepId === $step['id'],
                    ])
                </div>
            @endforeach
        </div>
    @endif
</div>
