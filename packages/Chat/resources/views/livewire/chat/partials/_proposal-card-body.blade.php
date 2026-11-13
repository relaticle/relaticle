{{-- The receipt lines of one proposal step. Expects the Alpine scope vars
     `action` and `group`.

     A DECIDED proposal collapses to check lines. The pending dock shows every
     field because you may not approve what you were not shown; once you have
     decided, the fields are an audit trail, so each line keeps what a reader
     scans for (what happened and a link to the record) and the whole line
     opens the values it wrote. receiptLines() in transcript.js builds the
     lines as data, with every word and icon from ProposalOutcome and
     ProposalEntity::receipts(), so this template never branches on an outcome. --}}
@php
    $leftOutLabel = \Relaticle\Chat\Enums\ProposalOutcome::LeftOut->label();
    $summaryExpression = "action.display?.summary ?? ((".\Illuminate\Support\Js::from(['create' => __('Create'), 'update' => __('Update'), 'delete' => __('Delete')]).")[action.operation] ?? action.operation)";
@endphp
{{-- COMPACT progress view while the batch is still docked. Gated on there being
     partial progress to show: a pending step nobody has touched has no items and
     no results, and would render "0 of 0 resolved. Review the rest below." above
     the still-open dock. The receipt renders as soon as ANY step is decided, so
     its undecided siblings come through here. --}}
<template x-if="action.status === 'pending' && hasItemResults(action)">
    <div class="px-2 py-1.5">
        <div class="space-y-1.5">
            <template x-for="(item, itemIdx) in (action.display?.items || [])" :key="itemIdx">
                <template x-if="itemResult(action, itemIdx)">
                    <div class="flex items-center gap-2 text-xs">
                        <span class="text-gray-600 dark:text-gray-300" x-text="item.summary"></span>
                        @include('chat::livewire.chat.partials._proposal-item-chips')
                        <template x-if="itemResult(action, itemIdx).record && itemResult(action, itemIdx).record.url">
                            @include('chat::livewire.chat.partials._proposal-record-link', ['record' => 'itemResult(action, itemIdx).record'])
                        </template>
                    </div>
                </template>
            </template>
        </div>

        <p class="mt-2 text-xs text-gray-400 dark:text-gray-500"
           x-text="@js(__(':resolved of :total resolved. Review the rest below.'))
               .replace(':resolved', String(Object.keys(action.itemResults || {}).length))
               .replace(':total', String(action.display?.items?.length ?? 0))"></p>
    </div>
</template>

{{-- An undecided step of a part-decided plan. The receipt appears as soon as
     ANY step is decided, so its siblings need a line that says what they are and
     that they are still open. It deliberately claims no progress: the branch
     above owns partial progress, and this one used to fall into it and print
     "0 of 0 resolved" over a card the user had not answered. --}}
<template x-if="action.status === 'pending' && ! hasItemResults(action)">
    <div class="px-2 py-1.5">
        <div class="flex min-w-0 items-center gap-2">
            <template x-if="window.ChatModules.recordChipIcon(action.entity_type) && proposalRecordLabel(action)">
                <span class="flex min-w-0 items-center gap-2.5" data-proposal-record-chip :data-record-type="action.entity_type">
                    <span
                        class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400"
                        aria-hidden="true"
                    >
                        @include('chat::livewire.chat.partials._record-glyph', ['class' => 'h-3.5 w-3.5'])
                    </span>
                    <span class="min-w-0 truncate text-sm font-semibold leading-5 text-gray-900 dark:text-white" x-text="proposalRecordLabel(action)"></span>
                </span>
            </template>

            <template x-if="!window.ChatModules.recordChipIcon(action.entity_type) || !proposalRecordLabel(action)">
                <span class="min-w-0 truncate text-sm font-medium text-gray-900 dark:text-white" x-text="{{ $summaryExpression }}"></span>
            </template>
        </div>
        <p class="mt-1 text-[length:var(--text-micro)] text-gray-400 dark:text-gray-500">{{ __('Waiting for your decision below.') }}</p>
    </div>
</template>

<template x-for="line in receiptLines(action)" :key="line.key">
    <div x-data="{ open: false }" :data-outcome="line.outcome" data-proposal-line>
        {{-- The whole line is the disclosure when it has values to open,
             because expanding is the safe, reversible read and it deserves the
             row-sized target. The toggle is a real <button> stretched over the
             row rather than a clickable wrapper: an anchor inside a button is
             neither valid nor operable. The row's contents sit above it and
             ignore the pointer, so a click anywhere lands on the toggle; only
             the record chip takes the pointer back. --}}
        <div
            class="group relative flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm transition"
            :class="[
                line.expandable ? 'hover:bg-gray-50 dark:hover:bg-white/5' : '',
                line.struck ? 'text-gray-400 dark:text-gray-500' : 'text-gray-700 dark:text-gray-300',
            ]"
        >
            <template x-if="line.expandable">
                <button
                    type="button"
                    data-proposal-row
                    x-on:click="open = !open"
                    :aria-expanded="open ? 'true' : 'false'"
                    :aria-label="open ? @js(__('Hide details')) : @js(__('Show details'))"
                    class="absolute inset-0 rounded-lg focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-500"
                ></button>
            </template>

            <span class="pointer-events-none relative flex min-w-0 flex-1 items-center gap-1.5">
                <span class="shrink-0" aria-hidden="true" x-html="outcomeIcon(line.outcome)"></span>

                <template x-if="line.verb">
                    <span class="shrink-0 whitespace-nowrap" :class="line.struck ? 'line-through decoration-gray-300 dark:decoration-gray-600' : ''" x-text="line.verb"></span>
                </template>

                {{-- A record that exists is the same chip a table cell or a
                     citation renders, and the chip is the link. A record that
                     never existed or is gone is a plain label, because a chip
                     that opens nothing is a false affordance. --}}
                <template x-if="line.chip">
                    <a
                        class="chat-chip pointer-events-auto min-w-0"
                        data-proposal-record-chip
                        data-proposal-record-link
                        :data-record-type="line.chip.type"
                        :href="line.chip.url"
                        wire:navigate
                        :title="@js(__('View :label')).replace(':label', line.chip.label)"
                    >
                        @include('chat::livewire.chat.partials._record-glyph', ['class' => 'h-3 w-3'])
                        <span class="chat-chip-label" x-text="line.chip.label"></span>
                    </a>
                </template>

                <template x-if="!line.chip && line.label">
                    <span class="min-w-0 truncate" :class="line.struck ? 'line-through decoration-gray-300 dark:decoration-gray-600' : ''" x-text="line.label"></span>
                </template>

                <template x-if="line.meta">
                    <span class="min-w-0 truncate text-gray-500 dark:text-gray-400" x-text="line.meta"></span>
                </template>
            </span>

            <template x-if="line.expandable">
                <span
                    data-proposal-toggle-icon
                    class="pointer-events-none relative inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md transition group-hover:bg-gray-100 dark:group-hover:bg-white/10"
                    :class="open ? 'text-gray-600 dark:text-gray-300' : 'text-gray-400 group-hover:text-gray-600 dark:group-hover:text-gray-300'"
                    aria-hidden="true"
                >
                    <x-lucide-chevron-down class="h-3.5 w-3.5 transition-transform duration-200" ::class="open ? 'rotate-180' : ''" />
                </span>
            </template>
        </div>

        <template x-if="line.expandable">
            <div x-show="open" x-cloak x-collapse.duration.200ms data-proposal-details class="ms-6 mt-1 overflow-hidden rounded-lg border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)]">
                <template x-if="Array.isArray(action.display?.fields) && action.display.fields.length > 0">
                    <div class="divide-y divide-gray-100 dark:divide-white/5">
                        <template x-for="(field, fieldIdx) in (action.display?.fields || [])" :key="fieldIdx">
                            <div class="flex items-start justify-between gap-3 px-4 py-2" data-proposal-field-row :data-left-out="isExcluded(action.excluded, field) ? 'true' : 'false'">
                                <div class="min-w-0 flex-1" :class="isExcluded(action.excluded, field) ? 'text-gray-400 line-through opacity-60 dark:text-gray-500' : ''">
                                    @include('chat::livewire.chat.partials._proposal-field')
                                </div>
                                <template x-if="isExcluded(action.excluded, field)">
                                    <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $leftOutLabel }}</span>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Batch items (records[] proposals): per-item summary, fields, and outcome. --}}
                <template x-if="Array.isArray(action.display?.items) && action.display.items.length > 0">
                    <div class="divide-y divide-gray-100 px-4 dark:divide-white/5">
                        <template x-for="(item, itemIdx) in action.display.items" :key="itemIdx">
                            <div class="py-3">
                                <div class="flex items-center justify-between gap-2">
                                    <template x-if="window.ChatModules.recordChipIcon(action.entity_type) && proposalItemLabel(item)">
                                        <span
                                            class="min-w-0 truncate text-sm font-semibold leading-5 text-gray-900 dark:text-white"
                                            data-proposal-record-chip
                                            :data-record-type="action.entity_type"
                                            x-text="proposalItemLabel(item)"
                                        ></span>
                                    </template>

                                    <template x-if="!window.ChatModules.recordChipIcon(action.entity_type) || !proposalItemLabel(item)">
                                        <div class="min-w-0 truncate text-sm font-medium text-gray-900 dark:text-white" x-text="item.summary"></div>
                                    </template>

                                    <template x-if="itemResult(action, itemIdx)">
                                        <span class="flex shrink-0 items-center gap-2 text-xs">
                                            @include('chat::livewire.chat.partials._proposal-item-chips')
                                        </span>
                                    </template>
                                </div>
                                <div class="mt-2 divide-y divide-gray-100 border-t border-gray-100 dark:divide-white/5 dark:border-white/5">
                                    <template x-for="(field, fieldIdx) in (item.fields || [])" :key="fieldIdx">
                                        <div class="flex items-start justify-between gap-3 py-2" data-proposal-field-row :data-left-out="isExcluded(itemResult(action, itemIdx)?.excluded, field) ? 'true' : 'false'">
                                            <div class="min-w-0 flex-1" :class="isExcluded(itemResult(action, itemIdx)?.excluded, field) ? 'text-gray-400 line-through opacity-60 dark:text-gray-500' : ''">
                                                @include('chat::livewire.chat.partials._proposal-field')
                                            </div>
                                            <template x-if="isExcluded(itemResult(action, itemIdx)?.excluded, field)">
                                                <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $leftOutLabel }}</span>
                                            </template>
                                        </div>
                                    </template>
                                </div>

                                <template x-if="itemResult(action, itemIdx) && itemResult(action, itemIdx).record && itemResult(action, itemIdx).record.url">
                                    <div class="mt-1.5 text-xs">
                                        @include('chat::livewire.chat.partials._proposal-record-link', ['record' => 'itemResult(action, itemIdx).record'])
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </template>
    </div>
</template>

{{-- A step cancelled by a rejection above it explains itself, rather than
     reading as an unexplained line. --}}
<template x-if="action.cancelled_by">
    <p class="px-2 pb-1 ps-8 text-[length:var(--text-micro)] text-gray-400 dark:text-gray-500">
        {{ __('Cancelled with the step it depended on.') }}
    </p>
</template>
