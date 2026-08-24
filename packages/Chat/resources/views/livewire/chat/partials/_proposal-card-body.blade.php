{{-- The contents of a transcript proposal card, without its surface.

     Rendered on its own inside the bordered card below (a single proposal), and
     stacked inside one shared card for a plan, where the surface belongs to the
     plan rather than to each of its steps. Expects the Alpine scope var `action`.

     A DECIDED proposal collapses to one line. The pending dock shows every field
     because you may not approve what you were not shown; once you have decided,
     the fields are an audit trail, not a decision, and five expanded steps of
     them bury the reply they belong to. The line keeps what a reader scans for
     (what it was, what happened to it, and a link to the record) and the whole
     line opens the fields. $inPlan drops the entity glyph, because the plan card
     already draws a numbered rail down the left. --}}
@php
    $inPlan = $inPlan ?? false;
    $operationLabels = ['create' => __('Create'), 'update' => __('Update'), 'delete' => __('Delete')];
    $outcomeLabels = ['approved' => __('Approved'), 'rejected' => __('Rejected'), 'expired' => __('Expired'), 'superseded' => __('Replaced')];
    $summaryExpression = "action.display?.summary ?? ((".\Illuminate\Support\Js::from($operationLabels).")[action.operation] ?? action.operation)";
@endphp
{{-- COMPACT progress view while the batch is still docked. --}}
<template x-if="action.status === 'pending'">
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
            'ps-9 pe-4' => $inPlan,
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

            @unless ($inPlan)
                <span
                    class="pointer-events-none relative flex h-6 w-6 shrink-0 items-center justify-center rounded-md"
                    :class="{
                        'bg-blue-50 text-blue-600 dark:bg-blue-400/10 dark:text-blue-400': action.operation === 'create',
                        'bg-amber-50 text-amber-600 dark:bg-amber-400/10 dark:text-amber-400': action.operation === 'update',
                        'bg-red-50 text-red-600 dark:bg-red-400/10 dark:text-red-400': action.operation === 'delete',
                    }"
                    aria-hidden="true"
                >
                    <template x-if="window.ChatModules.recordChipIcon(action.entity_type)">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" :d="window.ChatModules.recordChipIcon(action.entity_type)"></path>
                        </svg>
                    </template>

                    <template x-if="!window.ChatModules.recordChipIcon(action.entity_type)">
                        <span>
                            <template x-if="action.operation === 'update'">
                                <x-heroicon-o-pencil-square class="h-3.5 w-3.5" />
                            </template>
                            <template x-if="action.operation === 'delete'">
                                <x-heroicon-o-trash class="h-3.5 w-3.5" />
                            </template>
                            <template x-if="action.operation !== 'update' && action.operation !== 'delete'">
                                <x-heroicon-o-plus class="h-3.5 w-3.5" />
                            </template>
                        </span>
                    </template>
                </span>
            @endunless

            <span class="pointer-events-none relative flex min-w-0 flex-1 items-center gap-1.5">
                <span class="min-w-0 truncate text-sm font-medium text-gray-900 dark:text-white" x-text="{{ $summaryExpression }}"></span>

                {{-- The record, one click away and never in the row's own click
                     path: opening the page you just wrote to should not be
                     something you do by aiming at a line you meant to expand. --}}
                <template x-if="action.status === 'approved' && action.record && action.record.url">
                    <a
                        :href="action.record.url"
                        wire:navigate
                        data-proposal-record-link
                        :aria-label="action.record.label ? @js(__('View :label')).replace(':label', action.record.label) : @js(__('View'))"
                        :title="action.record.label ? @js(__('View :label')).replace(':label', action.record.label) : @js(__('View'))"
                        class="pointer-events-auto inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-primary-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:hover:bg-white/10 dark:hover:text-primary-400"
                    >
                        <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                    </a>
                </template>
            </span>

            {{-- Translated label map, not charAt-capitalized enum values:
                 'superseded' also reads as jargon, so it shows as Replaced. --}}
            <span
                class="pointer-events-none relative inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[length:var(--text-micro)] font-medium"
                :class="{
                    'bg-green-50 text-green-700 dark:bg-green-400/10 dark:text-green-400': action.status === 'approved',
                    'bg-red-50 text-red-700 dark:bg-red-400/10 dark:text-red-400': action.status === 'rejected',
                    'bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400': action.status === 'expired' || action.status === 'superseded',
                }"
                x-text="(@js($outcomeLabels))[action.status] ?? action.status"
            ></span>

            {{-- The affordance is labelled rather than a bare chevron: on touch
                 there is no hover to reveal that the row does anything at all. --}}
            <span
                class="pointer-events-none relative inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-[length:var(--text-micro)] font-medium text-gray-400 transition group-hover:bg-gray-100 group-hover:text-gray-600 dark:group-hover:bg-white/10 dark:group-hover:text-gray-300"
                aria-hidden="true"
            >
                <span>{{ __('Details') }}</span>
                <x-heroicon-o-chevron-down class="h-3 w-3 transition-transform" ::class="open ? 'rotate-180' : ''" />
            </span>
        </div>

        <div x-show="open" x-cloak data-proposal-details @class([
            'border-t border-gray-100 dark:border-white/5',
            'ps-5' => $inPlan,
        ])>
            <template x-if="action.display?.duplicate_warning">
                <div class="mx-4 mt-3 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-400/10 dark:text-amber-200">
                    <x-heroicon-o-exclamation-triangle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <span x-text="action.display.duplicate_warning"></span>
                </div>
            </template>

            <template x-if="Array.isArray(action.display?.fields) && action.display.fields.length > 0">
                <div class="space-y-2.5 px-4 py-3">
                    <template x-for="(field, fieldIdx) in (action.display?.fields || [])" :key="fieldIdx">
                        @include('chat::livewire.chat.partials._proposal-field')
                    </template>
                </div>
            </template>

            {{-- Batch items (records[] proposals): per-item summary, fields, and resolved chip. --}}
            <template x-if="Array.isArray(action.display?.items) && action.display.items.length > 0">
                <div class="divide-y divide-gray-100 px-4 dark:divide-white/5">
                    <template x-for="(item, itemIdx) in action.display.items" :key="itemIdx">
                        <div class="py-3">
                            <div class="flex items-center justify-between gap-2">
                                <div class="min-w-0 truncate text-sm font-medium text-gray-900 dark:text-white" x-text="item.summary"></div>

                                {{-- Per-item resolved chip (Created / Skipped). --}}
                                <template x-if="itemResult(action, itemIdx)">
                                    <span class="flex shrink-0 items-center gap-2 text-xs">
                                        @include('chat::livewire.chat.partials._proposal-item-chips')
                                    </span>
                                </template>
                            </div>
                            <div class="mt-1.5 space-y-1.5">
                                <template x-for="(field, fieldIdx) in (item.fields || [])" :key="fieldIdx">
                                    @include('chat::livewire.chat.partials._proposal-field')
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
