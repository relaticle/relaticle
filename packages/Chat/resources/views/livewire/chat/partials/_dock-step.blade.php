{{-- One step of a docked plan (or the whole card, when the plan has one step).

     Expects: $step (the view model from ProposalCard::stepViews()), $isPlan.

     A standalone step renders exactly ONE record: a batch is paginated by the
     footer arrows, so the footer's Create/Discard can only ever decide what is
     on screen (the invariant that makes a per-record footer safe). A PLAN keeps
     its numbered step list with every batch record on its own row, because a
     plan is one decision over several writes and hiding a step would mean
     approving writes the user was never shown. --}}
@php
    $entityIcon = \Relaticle\Chat\Support\RecordChipRenderer::iconPath($step['entity_type']);
    $operationLabel = match ($step['operation']) {
        'update' => __('Update'),
        'delete' => __('Delete'),
        default => __('Create'),
    };
    $blockedBy = $step['blockedBy'] ?? [];
    $stepTitle = ($step['title'] ?? '') !== '' ? $step['title'] : $operationLabel;
    $valueHeader = $step['operation'] === 'delete' ? __('Value') : __('New value');
    $identityLabel = $step['isBatch'] ? $step['activeItemLabel'] : $step['recordLabel'];

    // Attribute checkboxes: standalone cards only. A plan's fields render under
    // the shared Approve-all footer, where per-field exclusion state has no
    // single record to belong to.
    $excludableCodes = $isPlan ? [] : ($step['excludableCodes'] ?? []);
    $hasCheckboxes = $excludableCodes !== [];
    $excludedHere = $hasCheckboxes ? array_values(array_intersect($excludableCodes, $excludedFields)) : [];
    $allIncluded = $excludedHere === [];
    $allExcluded = $hasCheckboxes && count($excludedHere) === count($excludableCodes);
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

    @if (! $isPlan)
        {{-- Attio-shaped header: the operation title as a muted eyebrow line
             ("Create Person"), then the record identity on its own row with a
             colored entity tile. For a batch the identity is the ACTIVE record;
             the footer's pagination says where it sits in the batch. --}}
        <div class="flex items-center gap-2 px-4 pt-3 text-xs font-medium text-gray-500 dark:text-gray-400">
            <span class="min-w-0 flex-1 truncate">{{ $stepTitle }}</span>
        </div>

        @if ($entityIcon && $identityLabel !== '')
            <div class="flex min-w-0 items-center gap-2.5 px-4 pb-2.5 pt-1.5" data-proposal-record-chip data-record-type="{{ $step['entity_type'] }}">
                <span
                    @class([
                        'flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-white',
                        'bg-primary-600' => $step['operation'] === 'create',
                        'bg-amber-500' => $step['operation'] === 'update',
                        'bg-red-500' => $step['operation'] === 'delete',
                    ])
                    aria-hidden="true"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $entityIcon }}" />
                    </svg>
                </span>
                <p class="min-w-0 truncate text-sm font-semibold leading-5 text-gray-900 dark:text-white">{{ $identityLabel }}</p>
            </div>
        @else
            <div class="px-4 pb-2.5 pt-1.5">
                <p class="min-w-0 truncate text-sm font-semibold leading-5 text-gray-900 dark:text-white">{{ $step['summary'] }}</p>
            </div>
        @endif
    @else
    <div class="flex items-center gap-2.5 py-2.5 pe-3 ps-11">
        <div class="min-w-0 flex-1">
            <div class="flex min-w-0 items-center gap-2">
                @if ($entityIcon && $step['recordLabel'] !== '')
                    {{-- Same identity the standalone header leads with, at row
                         scale: operation-tinted tile, bold label, muted title.
                         No record pill: chips are reserved for inline clickable
                         references. --}}
                    <span class="flex min-w-0 items-center gap-2.5" data-proposal-record-chip data-record-type="{{ $step['entity_type'] }}">
                        <span
                            @class([
                                'flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-white',
                                'bg-primary-600' => $step['operation'] === 'create',
                                'bg-amber-500' => $step['operation'] === 'update',
                                'bg-red-500' => $step['operation'] === 'delete',
                            ])
                            aria-hidden="true"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $entityIcon }}" />
                            </svg>
                        </span>
                        <span class="min-w-0 truncate text-sm font-semibold leading-5 text-gray-900 dark:text-white">{{ $step['recordLabel'] }}</span>
                    </span>

                    <span class="shrink-0 text-xs font-medium text-gray-500 dark:text-gray-400">{{ $stepTitle }}</span>
                @else
                    <p class="min-w-0 truncate text-sm font-semibold leading-5 text-gray-900 dark:text-white">{{ $step['summary'] }}</p>
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
    @endif

    @if ($isPlan && $step['isBatch'])
        {{-- Plan batch step: one row per undecided record, each with its own skip
             control, because the plan footer decides everything at once. The
             active row expands into its editable fields. --}}
        <div class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-white/5 dark:border-white/5">
            @foreach ($step['items'] as $item)
                <div wire:key="batch-item-{{ $step['id'] }}-{{ $item['index'] }}">
                    <div @class([
                        'group/item flex items-center gap-1.5 py-1 pe-2 ps-9 transition',
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
                            <div class="flex items-center gap-3 py-2 pe-3 ps-11 text-xs font-medium text-gray-400 dark:text-gray-500">
                                <span class="w-32 shrink-0 sm:w-40">{{ __('Attribute') }}</span>
                                <span>{{ $valueHeader }}</span>
                            </div>
                            @foreach ($step['fields'] as $row)
                                <div class="py-2.5 pe-3 ps-11" data-proposal-field-row>
                                    @include('chat::livewire.chat.partials._dock-field', [
                                        'row' => $row,
                                        'stepId' => $step['id'],
                                        'isEditable' => ($row['code'] ?? null) !== null && in_array($row['code'], $step['editableCodes'], true),
                                        'isEditing' => $editingFieldCode !== null && $editingFieldCode === ($row['code'] ?? null) && $editingStepId === $step['id'],
                                        'hasCheckboxColumn' => false,
                                        'isExcludable' => false,
                                        'isExcluded' => false,
                                    ])
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @elseif ($step['fields'] !== [])
        {{-- The active record's fields: for a standalone batch these belong to
             the record the pagination cursor is on. --}}
        <div class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-white/5 dark:border-white/5">
            <div @class([
                'flex items-center gap-3 py-2 text-xs font-medium text-gray-400 dark:text-gray-500',
                'px-4' => ! $isPlan,
                'pe-3 ps-11' => $isPlan,
            ])>
                @if ($hasCheckboxes)
                    {{-- Master checkbox (Attio): checked = every attribute will be
                         written, minus = some unchecked, empty = only the required
                         identity remains. Click: all included -> exclude all,
                         anything else -> include all. --}}
                    <button
                        type="button"
                        wire:click="toggleAllFields"
                        role="checkbox"
                        aria-checked="{{ $allIncluded ? 'true' : ($allExcluded ? 'false' : 'mixed') }}"
                        aria-label="{{ __('Include all attributes') }}"
                        title="{{ __('Include all attributes') }}"
                        @class([
                            'flex h-4 w-4 shrink-0 items-center justify-center rounded border transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500',
                            'border-primary-600 bg-primary-600 text-white' => ! $allExcluded,
                            'border-gray-300 bg-white dark:border-white/20 dark:bg-white/5' => $allExcluded,
                        ])
                    >
                        @if ($allIncluded)
                            <x-heroicon-m-check class="h-3 w-3" aria-hidden="true" />
                        @elseif (! $allExcluded)
                            <x-heroicon-m-minus class="h-3 w-3" aria-hidden="true" />
                        @endif
                    </button>
                @endif
                <span class="w-32 shrink-0 sm:w-40">{{ __('Attribute') }}</span>
                <span>{{ $valueHeader }}</span>
            </div>
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
                        'hasCheckboxColumn' => $hasCheckboxes,
                        'isExcludable' => ($row['code'] ?? null) !== null && in_array($row['code'], $excludableCodes, true),
                        'isExcluded' => ($row['code'] ?? null) !== null && in_array($row['code'], $excludedFields, true),
                    ])
                </div>
            @endforeach
        </div>
    @endif
</div>
