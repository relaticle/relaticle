{{-- The contents of a transcript proposal card, without its surface.

     Rendered on its own inside the bordered card below (a single proposal), and
     stacked inside one shared card for a plan, where the surface belongs to the
     plan rather than to each of its steps. Expects the Alpine scope var `action`.

     A DECIDED proposal collapses to one line. The pending dock shows every field
     because you may not approve what you were not shown; once you have decided,
     the fields are an audit trail, not a decision, and five expanded steps of
     them bury the reply they belong to. The line keeps what a reader scans for
     (what it was, what happened to it, and a link to the record) and the whole
     line opens the fields. A plan keeps the same record pill beside its numbered
     rail, so record identity stays consistent at every depth. --}}
@php
    $inPlan = $inPlan ?? false;
    $operationLabels = ['create' => __('Create'), 'update' => __('Update'), 'delete' => __('Delete')];
    $outcomeLabels = ['rejected' => __('Rejected'), 'expired' => __('Expired'), 'superseded' => __('Replaced')];
    $approvedLabels = ['create' => __('Created'), 'update' => __('Updated'), 'delete' => __('Deleted')];
    $summaryExpression = "action.display?.summary ?? ((".\Illuminate\Support\Js::from($operationLabels).")[action.operation] ?? action.operation)";
@endphp
{{-- COMPACT progress view while the batch is still docked. Gated on there being
     partial progress to show: a pending step nobody has touched has no items and
     no results, and would render "0 of 0 resolved. Review the rest below." above
     the still-open dock. The plan card renders as soon as ANY step is decided, so
     its undecided siblings come through here. --}}
<template x-if="action.status === 'pending' && hasItemResults(action)">
    <div class="px-4 py-3">
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

{{-- An undecided step of a part-decided plan. The plan card appears as soon as
     ANY step is decided, so its siblings need a line that says what they are and
     that they are still open. It deliberately claims no progress: the branch
     above owns partial progress, and this one used to fall into it and print
     "0 of 0 resolved" over a card the user had not answered. --}}
@if ($inPlan)
    <template x-if="action.status === 'pending' && ! hasItemResults(action)">
        <div class="py-3 pe-3 ps-11">
            <div class="flex min-w-0 items-center gap-2">
                <template x-if="window.ChatModules.recordChipIcon(action.entity_type) && proposalRecordLabel(action)">
                    <span class="flex min-w-0 items-center gap-2.5" data-proposal-record-chip :data-record-type="action.entity_type">
                        <span
                            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md"
                            :class="action.operation === 'delete'
                                ? 'bg-red-50 text-red-600 dark:bg-red-400/10 dark:text-red-400'
                                : 'bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400'"
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

                <template x-if="window.ChatModules.recordChipIcon(action.entity_type) && proposalRecordLabel(action)">
                    <span
                        class="hidden shrink-0 text-xs font-medium text-gray-500 sm:inline dark:text-gray-400"
                        x-text="action.display?.title ?? ((@js($operationLabels))[action.operation] ?? action.operation)"
                    ></span>
                </template>
            </div>
            <p class="mt-1 text-[length:var(--text-micro)] text-gray-400 dark:text-gray-500">{{ __('Waiting for your decision below.') }}</p>
        </div>
    </template>
@endif

{{-- Read-only audit card once the proposal is finalized. --}}
<template x-if="action.status !== 'pending'">
    <div x-data="{ open: false }">
        {{-- The one line. The whole line is the disclosure, because expanding is
             the safe, reversible read and it deserves the row-sized target;
             leaving the transcript for the record is deliberate, so it gets its
             own small icon at the end of the title.

             The toggle is a real <button> stretched over the row rather than a
             clickable wrapper: an anchor inside a button is neither valid nor
             operable. The row's contents sit above it and ignore the pointer, so
             a click anywhere lands on the toggle; only the record link takes the
             pointer back.

             In a plan the row carries the numbered rail's gutter itself, so the
             hover and the click cover the step number rather than stopping at
             it. --}}
        <div @class([
            'group relative flex items-center gap-2.5 py-2.5 transition hover:bg-gray-50 dark:hover:bg-white/5',
            'ps-11 pe-3' => $inPlan,
            'px-4' => ! $inPlan,
        ])>
            <button
                type="button"
                data-proposal-row
                x-on:click="open = !open"
                :aria-expanded="open ? 'true' : 'false'"
                :aria-label="open ? @js(__('Hide details')) : @js(__('Show details'))"
                class="absolute inset-0 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-500"
            ></button>

            <span class="pointer-events-none relative flex min-w-0 flex-1 items-center gap-2">
                {{-- A record that exists is the same chip a table cell or a
                     citation renders, and the chip is the link: it is the one
                     thing that takes the pointer back from the row toggle. A
                     record that never existed or is gone (rejected, expired,
                     replaced, deleted) is a plain label with the entity glyph,
                     never a chip, because the chip is this transcript's link
                     treatment and a chip that opens nothing is a false
                     affordance. The operation is not repeated here; the
                     outcome at the end of the row names it. --}}
                <template x-if="window.ChatModules.recordChipIcon(action.entity_type) && proposalRecordLabel(action) && action.status === 'approved' && action.record && action.record.url">
                    <a
                        class="chat-chip pointer-events-auto min-w-0"
                        data-proposal-record-chip
                        data-proposal-record-link
                        :data-record-type="action.entity_type"
                        :href="action.record.url"
                        wire:navigate
                        :title="@js(__('View :label')).replace(':label', proposalRecordLabel(action))"
                    >
                        @include('chat::livewire.chat.partials._record-glyph', ['class' => 'h-3 w-3'])
                        <span class="chat-chip-label" x-text="proposalRecordLabel(action)"></span>
                    </a>
                </template>

                <template x-if="window.ChatModules.recordChipIcon(action.entity_type) && proposalRecordLabel(action) && !(action.status === 'approved' && action.record && action.record.url)">
                    <span class="flex min-w-0 items-center gap-1.5 text-sm text-gray-600 dark:text-gray-400" data-proposal-record-chip :data-record-type="action.entity_type">
                        @include('chat::livewire.chat.partials._record-glyph', ['class' => 'h-3.5 w-3.5 shrink-0 text-gray-400 dark:text-gray-500'])
                        <span class="min-w-0 truncate font-medium" x-text="proposalRecordLabel(action)"></span>
                    </span>
                </template>

                <template x-if="!window.ChatModules.recordChipIcon(action.entity_type) || !proposalRecordLabel(action)">
                    <span class="min-w-0 truncate text-sm font-medium text-gray-900 dark:text-white" x-text="{{ $summaryExpression }}"></span>
                </template>

            </span>

            {{-- A finalized batch reports what actually happened per item: its
                 row-level status says "approved" even when a record was skipped,
                 so the receipt is derived from itemResults ("2 created", "1
                 skipped") instead of echoing it. --}}
            <template x-if="batchOutcome(action)">
                <span class="pointer-events-none relative inline-flex shrink-0 items-center gap-2.5">
                    <template x-if="batchOutcome(action).done > 0">
                        <span class="inline-flex items-center gap-1.5 text-[length:var(--text-micro)] font-medium text-gray-500 dark:text-gray-400">
                            <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-green-500" aria-hidden="true"></span>
                            <span x-text="batchOutcome(action).doneLabel"></span>
                        </span>
                    </template>
                    <template x-if="batchOutcome(action).skipped > 0">
                        <span class="inline-flex items-center gap-1.5 text-[length:var(--text-micro)] font-medium text-gray-500 dark:text-gray-400">
                            <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-gray-400 dark:bg-gray-500" aria-hidden="true"></span>
                            <span x-text="batchOutcome(action).skippedLabel"></span>
                        </span>
                    </template>
                </span>
            </template>

            {{-- An approved row names the effect (Created, Updated, Deleted),
                 the same verbs a batch receipt uses, so the outcome is the one
                 place the operation is stated. A dot carries the colour, so
                 the tile stays the row's only tinted surface. The other outcomes come from a
                 translated map rather than the raw status: 'superseded' reads
                 as jargon, so it shows as Replaced. --}}
            <template x-if="!batchOutcome(action)">
                <span class="pointer-events-none relative inline-flex shrink-0 items-center gap-1.5 text-[length:var(--text-micro)] font-medium text-gray-500 dark:text-gray-400">
                    <span
                        class="h-1.5 w-1.5 shrink-0 rounded-full"
                        :class="{
                            'bg-green-500': action.status === 'approved',
                            'bg-red-500': action.status === 'rejected',
                            'bg-gray-400 dark:bg-gray-500': action.status === 'expired' || action.status === 'superseded',
                        }"
                        aria-hidden="true"
                    ></span>
                    <span
                        x-text="action.status === 'approved'
                            ? ((@js($approvedLabels))[action.operation] ?? @js(__('Approved')))
                            : ((@js($outcomeLabels))[action.status] ?? action.status)"
                    ></span>
                </span>
            </template>

            {{-- The chevron is decoration for the toggle underneath, which
                 carries the accessible label; it ignores the pointer so a
                 click on it lands on the toggle. It turns and darkens while
                 the fields are open, so the state reads at rest, not only
                 mid-motion. --}}
            <span
                data-proposal-toggle-icon
                class="pointer-events-none relative inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md transition group-hover:bg-gray-100 dark:group-hover:bg-white/10"
                :class="open ? 'text-gray-600 dark:text-gray-300' : 'text-gray-400 group-hover:text-gray-600 dark:group-hover:text-gray-300'"
                aria-hidden="true"
            >
                <x-heroicon-o-chevron-down class="h-3.5 w-3.5 transition-transform duration-200" ::class="open ? 'rotate-180' : ''" />
            </span>
        </div>

        {{-- Same collapse the sidebar groups use, so every disclosure in the
             panel opens at one speed. --}}
        <div x-show="open" x-cloak x-collapse.duration.200ms data-proposal-details @class([
            'border-t border-gray-100 dark:border-white/5',
            'ps-7' => $inPlan,
        ])>
            <template x-if="Array.isArray(action.display?.fields) && action.display.fields.length > 0">
                <div class="divide-y divide-gray-100 dark:divide-white/5">
                    <template x-for="(field, fieldIdx) in (action.display?.fields || [])" :key="fieldIdx">
                        <div class="px-4 py-2" data-proposal-field-row>
                            @include('chat::livewire.chat.partials._proposal-field')
                        </div>
                    </template>
                </div>
            </template>

            {{-- Batch items (records[] proposals): per-item summary, fields, and resolved chip. --}}
            <template x-if="Array.isArray(action.display?.items) && action.display.items.length > 0">
                <div class="divide-y divide-gray-100 px-4 dark:divide-white/5">
                    <template x-for="(item, itemIdx) in action.display.items" :key="itemIdx">
                        <div class="py-3">
                            <div class="flex items-center justify-between gap-2">
                                {{-- The item's record identity as plain bold text (chips are
                                     reserved for inline clickable references); the summary text
                                     is the fallback for an entity without a glyph or a quoted
                                     title. The data attributes stay: they mark identity, not a
                                     pill. --}}
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

                                {{-- Per-item resolved chip (Created / Skipped). --}}
                                <template x-if="itemResult(action, itemIdx)">
                                    <span class="flex shrink-0 items-center gap-2 text-xs">
                                        @include('chat::livewire.chat.partials._proposal-item-chips')
                                    </span>
                                </template>
                            </div>
                            <div class="mt-2 divide-y divide-gray-100 border-t border-gray-100 dark:divide-white/5 dark:border-white/5">
                                <template x-for="(field, fieldIdx) in (item.fields || [])" :key="fieldIdx">
                                    <div class="py-2">
                                        @include('chat::livewire.chat.partials._proposal-field')
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
    </div>
</template>
