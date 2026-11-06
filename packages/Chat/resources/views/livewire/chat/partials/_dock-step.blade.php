{{-- One line of the docked change list (or the whole card, when there is one proposal).

     Expects: $step (the view model from ProposalCard::stepViews()), $isPlan, $ownButton.

     Every record the footer will approve is on screen with its own x, so Approve
     never writes anything the user was not shown. --}}
@php
    $stepId = $step['id'];
    $entityIcon = \Relaticle\Chat\Support\RecordChipRenderer::iconPath($step['entity_type']);
    $isDelete = $step['operation'] === 'delete';
    $verb = ($step['title'] ?? '') !== '' ? $step['title'] : match ($step['operation']) {
        'update' => __('Update'),
        'delete' => __('Delete'),
        default => __('Create'),
    };
    $lineName = $step['isBatch'] || $step['recordLabel'] === '' ? $step['summary'] : $step['recordLabel'];
    $showVerb = ! $step['isBatch'] && $step['recordLabel'] !== '';

    $isLeftOut = in_array($stepId, $leftOutStepIds, true);
    $takenById = $takenBy[$stepId] ?? null;
    $isOut = $isLeftOut || $takenById !== null;
    $takenByStep = $takenById !== null ? collect($steps)->firstWhere('id', $takenById) : null;
    $takenByName = $takenByStep !== null ? ($takenByStep['isBatch'] || $takenByStep['recordLabel'] === '' ? $takenByStep['summary'] : $takenByStep['recordLabel']) : '';
    $leftOutRowsHere = $leftOutRowIndexes[$stepId] ?? [];

    $skipTitle = $step['operation'] !== 'update';
    $sharedChange = [];

    if ($step['operation'] === 'update' && count($step['items']) > 1) {
        foreach ($step['items'][0]['fields'] as $candidate) {
            $label = $candidate['label'] ?? '';
            $new = $candidate['new'] ?? null;

            if ($label === '' || in_array($new, [null, ''], true)) {
                continue;
            }

            $sharedEverywhere = collect($step['items'])->every(
                fn (array $item): bool => collect($item['fields'])->contains(fn (array $field): bool => ($field['label'] ?? null) === $label && ($field['new'] ?? null) === $new)
            );

            if ($sharedEverywhere) {
                $sharedChange[$label] = $new;
            }
        }
    }

    $excludableCodes = $isPlan ? [] : ($step['excludableCodes'] ?? []);
    $holdsOwnApproval = $step['needsOwnApproval'];
@endphp

@if ($isPlan && $holdsOwnApproval)
    @include('chat::livewire.chat.partials._dock-own-line', ['step' => $step, 'ownButton' => $ownButton])
@else
    <div class="group/line" wire:key="step-{{ $stepId }}">
        <div class="flex items-start gap-2.5 px-4 pb-1.5 pt-3">
            <span
                class="flex min-w-0 flex-1 items-start gap-2.5"
                @if ($entityIcon && $lineName !== '') data-proposal-record-chip data-record-type="{{ $step['entity_type'] }}" @endif
            >
                @if ($entityIcon)
                    <span
                        @class([
                            'mt-px flex h-6 w-6 shrink-0 items-center justify-center rounded-md',
                            'bg-red-50 text-red-600 dark:bg-red-400/10 dark:text-red-400' => $isDelete,
                            'bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400' => ! $isDelete,
                        ])
                        aria-hidden="true"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $entityIcon }}" />
                        </svg>
                    </span>
                @endif

                <span class="min-w-0 flex-1">
                    <span @class([
                        'block truncate text-sm leading-6',
                        'text-gray-400 line-through dark:text-gray-500' => $isOut,
                    ])>
                        @if ($showVerb)
                            <span @class(['text-gray-500 dark:text-gray-400' => ! $isOut])>{{ $verb }}</span>
                        @endif
                        <span @class(['font-semibold', 'text-gray-900 dark:text-white' => ! $isOut])>{{ $lineName }}</span>
                    </span>

                    @if ($step['blockedBy'] !== [] && ! $isOut)
                        <span class="block text-[length:var(--text-micro)] text-gray-400 dark:text-gray-500">
                            {{ trans_choice('Runs after step :steps|Runs after steps :steps', count($step['blockedBy']), ['steps' => implode(', ', $step['blockedBy'])]) }}
                        </span>
                    @endif

                    @if ($sharedChange !== [] && ! $isOut)
                        <span class="block text-xs leading-5 text-gray-500 dark:text-gray-400">
                            @foreach ($sharedChange as $label => $new)
                                {{ __(':field to :value', ['field' => $label, 'value' => $new]) }}@unless ($loop->last) &middot; @endunless
                            @endforeach
                        </span>
                    @endif
                </span>
            </span>

            @if ($holdsOwnApproval && ! $isPlan)
                <span class="mt-1 inline-flex shrink-0 items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                    <x-heroicon-o-lock-closed class="h-3 w-3" aria-hidden="true" />
                    {{ __('Own approval') }}
                </span>
            @endif

            @if ($isPlan && ! $holdsOwnApproval && $takenById === null)
                <button
                    type="button"
                    wire:click="toggleLeftOut(@js($stepId))"
                    wire:loading.attr="disabled"
                    @disabled($editingFieldCode !== null)
                    @class([
                        'inline-flex min-h-8 min-w-8 shrink-0 items-center justify-center rounded-md transition focus-visible:visible focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-not-allowed disabled:opacity-60',
                        'text-gray-400 hover:bg-gray-100 hover:text-gray-700 sm:invisible sm:group-hover/line:visible dark:hover:bg-white/10 dark:hover:text-gray-200' => ! $isLeftOut,
                        'px-1.5 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400' => $isLeftOut,
                    ])
                    aria-label="{{ $isLeftOut ? __('Keep :name', ['name' => $lineName]) : __('Leave out :name', ['name' => $lineName]) }}"
                >
                    @if ($isLeftOut)
                        {{ __('Keep') }}
                    @else
                        <x-heroicon-o-x-mark class="h-4 w-4" aria-hidden="true" />
                    @endif
                </button>
            @endif
        </div>

        @if ($takenById !== null)
            <p class="mx-4 mb-3 ms-[3.125rem] flex flex-wrap items-baseline gap-x-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-inset ring-amber-200 dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-400/20" data-proposal-left-out-note>
                <span>{{ __(':name is left out, so this :entity is left out too. It links to :name.', ['name' => $takenByName, 'entity' => $step['entityNoun']]) }}</span>
                <button
                    type="button"
                    wire:click="toggleLeftOut(@js($takenById))"
                    wire:loading.attr="disabled"
                    class="font-medium underline underline-offset-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600"
                >{{ __('Keep :name', ['name' => $takenByName]) }}</button>
            </p>
        @endif

        @if (! $isOut)
            @if ($step['isBatch'])
                <div class="divide-y divide-gray-100 pb-2 pe-2 ps-[3.125rem] dark:divide-white/5">
                    @foreach ($step['items'] as $item)
                        @php
                            $rowIndex = $item['index'];
                            $rowOut = in_array($rowIndex, $leftOutRowsHere, true);
                            $linkedFrom = $linkedRows[$stepId][$rowIndex] ?? null;
                            $rowFields = array_map(
                                static fn (array $field): array => isset($sharedChange[$field['label'] ?? '']) ? array_diff_key($field, ['new' => true]) : $field,
                                $item['fields'],
                            );
                        @endphp

                        <div class="group/row py-1.5" wire:key="batch-item-{{ $stepId }}-{{ $rowIndex }}">
                            <div class="flex items-center gap-2">
                                <span
                                    data-proposal-batch-row
                                    @class([
                                        'min-w-0 flex-1 truncate text-sm font-medium',
                                        'text-gray-900 dark:text-white' => ! $rowOut,
                                        'text-gray-400 line-through dark:text-gray-500' => $rowOut,
                                    ])
                                >{{ $item['label'] }}</span>

                                @if ($linkedFrom !== null)
                                    <span class="shrink-0 text-xs text-gray-400 dark:text-gray-500">{{ __('linked from :step', ['step' => $linkedFrom]) }}</span>
                                @else
                                    <button
                                        type="button"
                                        wire:click="toggleLeftOutRow(@js($stepId), {{ $rowIndex }})"
                                        wire:loading.attr="disabled"
                                        @disabled($editingFieldCode !== null)
                                        @class([
                                            'inline-flex min-h-8 min-w-8 shrink-0 items-center justify-center rounded-md transition focus-visible:visible focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-not-allowed disabled:opacity-60',
                                            'text-gray-400 hover:bg-gray-100 hover:text-gray-700 sm:invisible sm:group-hover/row:visible dark:hover:bg-white/10 dark:hover:text-gray-200' => ! $rowOut,
                                            'px-1.5 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400' => $rowOut,
                                        ])
                                        aria-label="{{ $rowOut ? __('Keep :name', ['name' => $item['label']]) : __('Leave out :name', ['name' => $item['label']]) }}"
                                    >
                                        @if ($rowOut)
                                            {{ __('Keep') }}
                                        @else
                                            <x-heroicon-o-x-mark class="h-4 w-4" aria-hidden="true" />
                                        @endif
                                    </button>
                                @endif
                            </div>

                            @if (! $rowOut && ! $isDelete)
                                @include('chat::livewire.chat.partials._dock-line-values', [
                                    'fields' => $rowFields,
                                    'stepId' => $stepId,
                                    'titleKey' => $step['titleKey'],
                                    'titleLabel' => $step['titleLabel'],
                                    'editableCodes' => $step['editableCodes'],
                                    'index' => $rowIndex,
                                    'skipTitle' => $skipTitle,
                                ])
                            @endif
                        </div>
                    @endforeach
                </div>
            @elseif ($isPlan)
                <div class="pb-2.5 pe-3 ps-[3.125rem]">
                    @include('chat::livewire.chat.partials._dock-line-values', [
                        'fields' => $isDelete ? [] : $step['fields'],
                        'stepId' => $stepId,
                        'titleKey' => $step['titleKey'],
                        'titleLabel' => $step['titleLabel'],
                        'editableCodes' => $step['editableCodes'],
                        'index' => null,
                        'skipTitle' => $skipTitle,
                    ])
                </div>
            @elseif ($step['fields'] !== [])
                <div class="space-y-1 pb-3 pe-2 ps-[3.125rem]">
                    @foreach ($step['fields'] as $row)
                        <div data-proposal-field-row>
                            @include('chat::livewire.chat.partials._dock-field', [
                                'row' => $row,
                                'stepId' => $stepId,
                                'isEditable' => ($row['code'] ?? null) !== null && in_array($row['code'], $step['editableCodes'], true),
                                'isEditing' => $editingFieldCode !== null && $editingFieldCode === ($row['code'] ?? null) && $editingStepId === $stepId,
                                'isExcludable' => ($row['code'] ?? null) !== null && in_array($row['code'], $excludableCodes, true),
                                'isExcluded' => ($row['code'] ?? null) !== null && in_array($row['code'], $excludedFields, true),
                                'clamp' => ! $holdsOwnApproval,
                            ])
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    </div>
@endif
