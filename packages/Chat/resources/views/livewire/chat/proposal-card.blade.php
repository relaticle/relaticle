@php
    $operation = $proposal?->operation->value;
    $approvableSteps = array_filter($steps, fn (array $step): bool => ! $step['needsOwnApproval']);
    $totalRecordCount = array_sum(array_map(fn (array $step): int => $step['isBatch'] ? count($step['items']) : 1, $approvableSteps));
    $countedSteps = array_filter($approvableSteps, fn (array $step): bool => ! in_array($step['id'], $leftOutStepIds, true) && ! isset($takenBy[$step['id']]));
    $hasDelete = array_filter($countedSteps, fn (array $step): bool => $step['operation'] === 'delete') !== [];
    $isOnlyOwnApproval = $isPlan && $approvableSteps === [];
    $isWebAccess = ! $isPlan && ($steps[0]['entity_type'] ?? null) === \Relaticle\Chat\Enums\ProposalEntity::WebAccess->value;
    $holdsOwnApproval = ! $isPlan && ($steps[0]['needsOwnApproval'] ?? false);
    $isStandaloneBatch = ! $isPlan && ($steps[0]['isBatch'] ?? false);
    $rowsIn = $isStandaloneBatch
        ? count($steps[0]['items']) - count(array_intersect(array_column($steps[0]['items'], 'index'), $leftOutRowIndexes[$steps[0]['id']] ?? []))
        : 0;
    $decisionCount = $holdsOwnApproval ? $rowsIn : $approvableRecordCount;

    if ($isPlan) {
        $loneStep = count($countedSteps) === 1 ? reset($countedSteps) : null;
        $primaryLabel = $hasOwnApprovalSteps && $loneStep !== null && ! $loneStep['isBatch']
            ? __('Approve :entity', ['entity' => $loneStep['entityNoun']])
            : trans_choice('Approve :count change|Approve :count changes', $approvableRecordCount, ['count' => $approvableRecordCount]);
        $showPrimary = $approvableRecordCount > 0;
        $discardLabel = __('Discard all');
    } else {
        $isBatchApproval = $isStandaloneBatch && ! $holdsOwnApproval && $operation !== 'delete';
        $primaryLabel = $isBatchApproval
            ? trans_choice('Approve :count change|Approve :count changes', $approvableRecordCount, ['count' => $approvableRecordCount])
            : ($proposal?->entity_type->confirmLabel($proposal->operation, max($decisionCount, 1)) ?? __('Approve'));
        $showPrimary = ! $isStandaloneBatch || $decisionCount > 0;
        $discardLabel = $isBatchApproval ? __('Discard all') : ($proposal?->entity_type->declineLabel() ?? __('Discard'));
    }

    $primaryAction = $isPlan || ($isStandaloneBatch && ! $holdsOwnApproval) ? 'approveAll' : 'createCurrent';
    $discardAction = $isPlan ? 'discardAll' : 'discardCurrent';
    $isDestructive = $isPlan ? $hasDelete : $operation === 'delete';
    $primaryIsSend = $holdsOwnApproval;
    $showsShortcut = ! $primaryIsRisky;

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
        {{-- fi-fixed-positioning-context makes a Select open its options with
             position: fixed, so the steps scroller below cannot clip them. --}}
        <div class="fi-fixed-positioning-context flex min-h-0 flex-col overflow-hidden rounded-xl border border-primary-200 bg-[var(--surface-block-bg)] ring-[3px] ring-primary-100 dark:border-primary-400/30 dark:ring-primary-400/10">
            @if ($isPlan && ! $isOnlyOwnApproval)
                <div class="flex shrink-0 items-center gap-2.5 border-b border-gray-100 px-4 py-2.5 dark:border-white/5">
                    <p class="min-w-0 flex-1 truncate text-sm font-semibold leading-5 text-gray-900 dark:text-white">
                        {{ $approvableRecordCount === $totalRecordCount
                            ? trans_choice(':count change|:count changes', $totalRecordCount, ['count' => $totalRecordCount])
                            : __(':count of :total changes', ['count' => $approvableRecordCount, 'total' => $totalRecordCount]) }}
                    </p>

                    @if ($approvableRecordCount === $totalRecordCount)
                        <span class="hidden shrink-0 text-[length:var(--text-micro)] text-gray-400 sm:inline dark:text-gray-500">
                            {{ __('Hover a line to leave it out') }}
                        </span>
                    @endif
                </div>
            @endif

            @if ($isWebAccess)
                @include('chat::livewire.chat.partials._dock-web-access', ['step' => $steps[0], 'proposal' => $proposal])
            @else
            {{-- A plan can be taller than the dock: the lines scroll, so the
                 decision buttons never leave the viewport. --}}
            <div @class([
                'min-h-0 flex-1 overflow-y-auto overscroll-contain',
                'divide-y divide-gray-100 dark:divide-white/5' => $isPlan,
            ])>
                @foreach ($steps as $step)
                    @include('chat::livewire.chat.partials._dock-step', ['step' => $step, 'isPlan' => $isPlan, 'ownButton' => $isOnlyOwnApproval])
                @endforeach
            </div>

            @error('resolve')
                <p class="mx-4 mb-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-400/10 dark:text-red-400" role="alert">
                    {{ $message }}
                </p>
            @enderror

            {{-- Footer: the decision. Separated by a hairline so it reads as one
                 deliberate step: a plain-text discard and a brand-colored confirm,
                 with the genuinely destructive delete in red. --}}
            <div class="flex shrink-0 items-center gap-2 border-t border-gray-100 px-4 py-2 dark:border-white/5">
                <div class="ms-auto flex items-center gap-2">
                <button
                    type="button"
                    wire:key="discard-{{ $discardAction }}-{{ $steps[0]['id'] ?? '' }}"
                    wire:click="{{ $discardAction }}"
                    wire:loading.attr="disabled"
                    @if ($primaryIsSend)
                        x-data="{ armed: false }"
                        x-init="setTimeout(() => armed = true, 700)"
                        x-bind:disabled="{{ $editingFieldCode !== null ? 'true' : '! armed' }}"
                    @endif
                    @disabled($editingFieldCode !== null)
                    @if ($editingFieldCode !== null) title="{{ __('Finish editing the field first') }}" @endif
                    @class([
                        'inline-flex h-7 items-center rounded-md px-2.5 text-xs font-medium text-gray-600 transition hover:bg-gray-100 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 motion-safe:active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white dark:disabled:hover:bg-transparent',
                        'opacity-50' => $editingFieldCode !== null,
                    ])
                >
                    {{ $discardLabel }}
                </button>

                @if ($showPrimary)
                <button
                    type="button"
                    wire:key="primary-{{ $primaryAction }}-{{ $steps[0]['id'] ?? '' }}"
                    wire:click="{{ $primaryAction }}"
                    wire:loading.attr="disabled"
                    @if ($showsShortcut) data-proposal-shortcut @endif
                    @if ($primaryIsSend)
                        x-data="{ armed: false }"
                        x-init="setTimeout(() => armed = true, 700)"
                        x-bind:disabled="{{ $primaryBlocked ? 'true' : '! armed' }}"
                    @endif
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
                    @if ($showsShortcut)
                    <kbd
                        x-data
                        x-text="/Mac|iP/.test(navigator.platform) ? '⌘⏎' : 'Ctrl+⏎'"
                        class="hidden rounded bg-white/20 px-1 py-0.5 font-sans text-[length:var(--text-pico)] font-medium sm:inline"
                    ></kbd>
                    @endif
                </button>
                @endif
                </div>
            </div>
            @endif
        </div>
    @endif

    <x-filament-actions::modals />
</div>
