{{-- The contents of a transcript proposal card, without its surface.

     Rendered on its own inside the bordered card below (a single proposal), and
     stacked inside one shared card for a plan, where the surface belongs to the
     plan rather than to each of its steps. Expects the Alpine scope var `action`. --}}
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

{{-- Full read-only audit card once the proposal is finalized. --}}
<template x-if="action.status !== 'pending'">
    <div>
        {{-- Header strip: the record's own glyph in the operation's colour, the
             human summary, and how it was resolved. Same glyph set as the chips in
             the reply above and the docked card, so one record reads as one thing
             wherever chat draws it. --}}
        <div class="flex items-center gap-2.5 border-b border-gray-100 px-4 py-2.5 dark:border-white/5">
            <span
                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md"
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

            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold leading-5 text-gray-900 dark:text-white" x-text="action.display?.summary ?? ((@js([
                    'create' => __('Create'),
                    'update' => __('Update'),
                    'delete' => __('Delete'),
                ]))[action.operation] ?? action.operation)"></p>
            </div>

            {{-- Translated label map, not charAt-capitalized enum values:
                 'superseded' also reads as jargon, so it shows as Replaced. --}}
            <span
                class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[length:var(--text-micro)] font-medium"
                :class="{
                    'bg-green-50 text-green-700 dark:bg-green-400/10 dark:text-green-400': action.status === 'approved',
                    'bg-red-50 text-red-700 dark:bg-red-400/10 dark:text-red-400': action.status === 'rejected',
                    'bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400': action.status === 'expired' || action.status === 'superseded',
                }"
                x-text="(@js([
                    'approved' => __('Approved'),
                    'rejected' => __('Rejected'),
                    'expired' => __('Expired'),
                    'superseded' => __('Replaced'),
                ]))[action.status] ?? action.status"
            ></span>
        </div>

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

        {{-- Record link — SINGLE proposals only. Batch items each carry their own
             View link above, and the outcome summary sits below the card, so a
             batch-level link row here would just repeat the same links. --}}
        <template x-if="!(Array.isArray(action.display?.items) && action.display.items.length > 0) && action.status === 'approved' && action.record && action.record.url">
            <div class="px-4 pb-3 text-xs">
                @include('chat::livewire.chat.partials._proposal-record-link', ['record' => 'action.record'])
            </div>
        </template>
    </div>
</template>
