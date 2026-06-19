{{-- Transcript audit card for a batch/single proposal.

     Two render modes, both gated by the transcript x-for in chat-interface.blade.php:
       1. status === 'pending' (a partially-resolved batch still docked at the
          composer): COMPACT progress view — only the resolved per-item chips plus
          a muted "N of M resolved" hint. The full editor lives in the dock, so we
          deliberately omit the header, fields, and final badge to avoid a
          confusing duplicate.
       2. status !== 'pending' (finalized / single resolved): the full read-only
          audit card. --}}
<div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
    {{-- COMPACT progress view while the batch is still docked. --}}
    <template x-if="action.status === 'pending'">
        <div>
            <div class="space-y-1.5">
                <template x-for="(item, itemIdx) in (action.display?.items || [])" :key="itemIdx">
                    <template x-if="itemResult(action, itemIdx)">
                        <div class="flex items-center gap-2 text-xs">
                            <span class="text-gray-600 dark:text-gray-300" x-text="item.summary"></span>
                            <template x-if="itemResult(action, itemIdx).status === 'approved'">
                                <span class="inline-flex items-center gap-1 rounded-md bg-green-50 px-1.5 py-0.5 font-medium text-green-700 dark:bg-green-900/20 dark:text-green-400">
                                    <x-heroicon-o-check class="h-3 w-3" aria-hidden="true" /> <span x-text="itemVerb(action)"></span>
                                </span>
                            </template>
                            <template x-if="itemResult(action, itemIdx).status === 'skipped'">
                                <span class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-1.5 py-0.5 font-medium text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                                    Skipped
                                </span>
                            </template>
                            <template x-if="itemResult(action, itemIdx).record && itemResult(action, itemIdx).record.url">
                                <a :href="itemResult(action, itemIdx).record.url" wire:navigate class="inline-flex items-center gap-1 font-medium text-primary-600 hover:underline dark:text-primary-400">
                                    <span x-text="itemResult(action, itemIdx).record.label ? 'View ' + itemResult(action, itemIdx).record.label : 'View'"></span>
                                    <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
                                </a>
                            </template>
                        </div>
                    </template>
                </template>
            </div>

            <p class="mt-2 text-xs text-gray-400 dark:text-gray-500"
               x-text="`${Object.keys(action.itemResults || {}).length} of ${(action.display?.items?.length ?? 0)} resolved — review the rest below`"></p>
        </div>
    </template>

    {{-- Full read-only audit card once the proposal is finalized. --}}
    <template x-if="action.status !== 'pending'">
        <div>
            <div class="flex items-center gap-2">
                <span
                    class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium"
                    :class="{
                        'bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400': action.operation === 'create',
                        'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400': action.operation === 'update',
                        'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400': action.operation === 'delete',
                    }"
                    x-text="action.operation.charAt(0).toUpperCase() + action.operation.slice(1)"
                ></span>
                <span class="text-sm font-medium text-gray-900 dark:text-white" x-text="action.display?.summary"></span>
            </div>

            <template x-if="action.display?.duplicate_warning">
                <div class="mt-2 rounded-md border border-amber-300 bg-amber-50 px-2 py-1.5 text-xs text-amber-800 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-200" x-text="action.display.duplicate_warning"></div>
            </template>

            <div class="mt-2 space-y-1">
                <template x-for="(field, fieldIdx) in (action.display?.fields || [])" :key="fieldIdx">
                    @include('chat::livewire.chat.partials._proposal-field')
                </template>
            </div>

            {{-- Batch items (records[] proposals): per-item summary, fields, and resolved chip. --}}
            <template x-if="Array.isArray(action.display?.items) && action.display.items.length > 0">
                <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-800">
                    <template x-for="(item, itemIdx) in action.display.items" :key="itemIdx">
                        <div class="py-2 first:pt-0 last:pb-0">
                            <div class="text-sm font-medium text-gray-900 dark:text-white" x-text="item.summary"></div>
                            <div class="mt-1 space-y-0.5">
                                <template x-for="(field, fieldIdx) in (item.fields || [])" :key="fieldIdx">
                                    @include('chat::livewire.chat.partials._proposal-field')
                                </template>
                            </div>

                            {{-- Per-item resolved chip (Created / Skipped + link). --}}
                            <template x-if="itemResult(action, itemIdx)">
                                <div class="mt-1.5 flex items-center gap-2 text-xs">
                                    <template x-if="itemResult(action, itemIdx).status === 'approved'">
                                        <span class="inline-flex items-center gap-1 rounded-md bg-green-50 px-1.5 py-0.5 font-medium text-green-700 dark:bg-green-900/20 dark:text-green-400">
                                            <x-heroicon-o-check class="h-3 w-3" aria-hidden="true" /> <span x-text="itemVerb(action)"></span>
                                        </span>
                                    </template>
                                    <template x-if="itemResult(action, itemIdx).status === 'skipped'">
                                        <span class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-1.5 py-0.5 font-medium text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                                            Skipped
                                        </span>
                                    </template>
                                    <template x-if="itemResult(action, itemIdx).record && itemResult(action, itemIdx).record.url">
                                        <a :href="itemResult(action, itemIdx).record.url" wire:navigate class="inline-flex items-center gap-1 font-medium text-primary-600 hover:underline dark:text-primary-400">
                                            <span x-text="itemResult(action, itemIdx).record.label ? 'View ' + itemResult(action, itemIdx).record.label : 'View'"></span>
                                            <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
                                        </a>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </template>

            {{-- Resolved status badge + record link — SINGLE proposals only. Batch items
                 each carry their own Created/Skipped chip and View link above, and the
                 outcome summary sits below the card, so a batch-level status row here
                 would just repeat the same links a third time. --}}
            <template x-if="!(Array.isArray(action.display?.items) && action.display.items.length > 0)">
                <div class="mt-3 flex items-center gap-2">
                    <span
                        class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium"
                        :class="{
                            'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400': action.status === 'approved',
                            'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400': action.status === 'rejected',
                            'bg-gray-50 text-gray-700 dark:bg-gray-900/20 dark:text-gray-400': action.status === 'expired' || action.status === 'superseded',
                        }"
                        x-text="action.status.charAt(0).toUpperCase() + action.status.slice(1)"
                    ></span>
                    <template x-if="action.status === 'approved' && action.record && action.record.url">
                        <a
                            :href="action.record.url"
                            wire:navigate
                            class="inline-flex items-center gap-1 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                        >
                            <span x-text="action.record.label ? 'View ' + action.record.label : 'View'"></span>
                            <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
                        </a>
                    </template>
                </div>
            </template>
        </div>
    </template>
</div>
