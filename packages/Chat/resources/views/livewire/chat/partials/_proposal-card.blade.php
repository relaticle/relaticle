<div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
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

                                    <template x-if="!(action.edit && action.edit.index === null)">
                                        <div class="mt-2 space-y-1">
                                            <template x-for="(field, fieldIdx) in (action.display?.fields || [])" :key="fieldIdx">
                                                @include('chat::livewire.chat.partials._proposal-field')
                                            </template>
                                        </div>
                                    </template>

                                    {{-- Single-proposal inline edit form --}}
                                    <template x-if="action.edit && action.edit.index === null">
                                        <div class="mt-3 space-y-3 rounded-lg border border-primary-200 bg-primary-50/40 p-3 dark:border-primary-900/40 dark:bg-primary-900/10">
                                            @include('chat::livewire.chat.partials._proposal-edit-form')
                                        </div>
                                    </template>

                                    {{-- Batch items (records[] proposals) --}}
                                    <template x-if="Array.isArray(action.display?.items) && action.display.items.length > 0">
                                        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-800">
                                            <template x-for="(item, itemIdx) in action.display.items" :key="itemIdx">
                                                <div class="py-2 first:pt-0 last:pb-0">
                                                    <div class="text-sm font-medium text-gray-900 dark:text-white" x-text="item.summary"></div>
                                                    <template x-if="!(action.edit && action.edit.index === itemIdx)">
                                                        <div class="mt-1 space-y-0.5">
                                                            <template x-for="(field, fieldIdx) in (item.fields || [])" :key="fieldIdx">
                                                                @include('chat::livewire.chat.partials._proposal-field')
                                                            </template>
                                                        </div>
                                                    </template>

                                                    {{-- Per-item inline edit form --}}
                                                    <template x-if="action.edit && action.edit.index === itemIdx">
                                                        <div class="mt-2 space-y-3 rounded-lg border border-primary-200 bg-primary-50/40 p-3 dark:border-primary-900/40 dark:bg-primary-900/10">
                                                            @include('chat::livewire.chat.partials._proposal-edit-form')
                                                        </div>
                                                    </template>

                                                    {{-- Per-item resolution (batch only) --}}
                                                    <div class="mt-1.5">
                                                        <template x-if="action.status === 'pending' && !itemResult(action, itemIdx) && !(action.edit && action.edit.index === itemIdx)">
                                                            <div class="flex items-center gap-2">
                                                                <button
                                                                    x-on:click="resolveItem(action, itemIdx, 'approve')"
                                                                    class="inline-flex items-center gap-1 rounded-md bg-primary-600 px-2 py-1 text-xs font-medium text-white hover:bg-primary-700"
                                                                >
                                                                    <x-heroicon-o-check class="h-3 w-3" />
                                                                    <span x-text="action.operation === 'delete' ? 'Delete' : 'Create'"></span>
                                                                </button>
                                                                <template x-if="action.operation === 'create'">
                                                                    <button
                                                                        type="button"
                                                                        x-on:click="enterFieldEdit(action, itemIdx)"
                                                                        class="inline-flex items-center gap-1 rounded-md border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                                                                    >
                                                                        <x-heroicon-o-pencil-square class="h-3 w-3" aria-hidden="true" />
                                                                        <span>Edit</span>
                                                                    </button>
                                                                </template>
                                                                <button
                                                                    x-on:click="resolveItem(action, itemIdx, 'reject')"
                                                                    class="inline-flex items-center rounded-md border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                                                                >
                                                                    Skip
                                                                </button>
                                                            </div>
                                                        </template>
                                                        <template x-if="itemResult(action, itemIdx)">
                                                            <div class="flex items-center gap-2 text-xs">
                                                                <template x-if="itemResult(action, itemIdx).status === 'approved'">
                                                                    <span class="inline-flex items-center gap-1 rounded-md bg-green-50 px-1.5 py-0.5 font-medium text-green-700 dark:bg-green-900/20 dark:text-green-400">
                                                                        <x-heroicon-o-check class="h-3 w-3" /> Created
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
                                                </div>
                                            </template>
                                        </div>
                                    </template>

                                    {{-- Action buttons --}}
                                    <template x-if="action.status === 'pending' && !(action.edit && action.edit.index === null)">
                                        <div class="mt-3 flex items-center gap-2">
                                            <template x-if="!isBatchAction(action) && action.operation === 'create' && !action.edit">
                                                <button
                                                    type="button"
                                                    x-on:click="enterFieldEdit(action, null)"
                                                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                                >
                                                    <x-heroicon-o-pencil-square class="h-3.5 w-3.5" aria-hidden="true" />
                                                    <span>Edit</span>
                                                </button>
                                            </template>
                                            <button
                                                x-on:click="isBatchAction(action) ? (anyItemResolved(action) ? resolveRemaining(action, 'approve') : approveAction(action)) : approveAction(action)"
                                                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium text-white transition"
                                                :class="(!isBatchAction(action) && action.operation === 'delete')
                                                    ? 'bg-red-600 hover:bg-red-700'
                                                    : 'bg-primary-600 hover:bg-primary-700'"
                                            >
                                                <x-heroicon-o-check class="h-3.5 w-3.5" />
                                                <span x-text="isBatchAction(action) ? (anyItemResolved(action) ? 'Create remaining' : 'Create all') : primaryActionLabel(action)"></span>
                                                <kbd
                                                    x-show="!isBatchAction(action) && visiblePendingActions().length === 1"
                                                    class="hidden rounded bg-white/20 px-1 font-sans text-[10px] sm:inline"
                                                >&#8984;&#9166;</kbd>
                                            </button>
                                            <button
                                                x-on:click="isBatchAction(action) ? (anyItemResolved(action) ? resolveRemaining(action, 'reject') : rejectAction(action)) : rejectAction(action)"
                                                class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                            >
                                                <span x-text="isBatchAction(action) ? (anyItemResolved(action) ? 'Discard remaining' : 'Discard all') : 'Discard'"></span>
                                            </button>
                                        </div>
                                    </template>

                                    {{-- Error state --}}
                                    <template x-if="action.error">
                                        <div class="mt-2 text-xs text-red-600 dark:text-red-400" x-text="action.error"></div>
                                    </template>

                                    {{-- Resolved state --}}
                                    <template x-if="action.status !== 'pending' && !action.error">
                                        <div class="mt-3 flex items-center gap-2">
                                            <span
                                                class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium"
                                                :class="{
                                                    'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400': action.status === 'approved',
                                                    'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400': action.status === 'rejected',
                                                    'bg-gray-50 text-gray-700 dark:bg-gray-900/20 dark:text-gray-400': action.status === 'expired' || action.status === 'superseded',
                                                    'bg-gradient-to-r from-green-50 to-blue-50 text-blue-700 dark:from-green-900/20 dark:to-blue-900/20 dark:text-blue-300': action.status === 'restored',
                                                }"
                                                x-text="action.status.charAt(0).toUpperCase() + action.status.slice(1)"
                                            ></span>
                                            <template x-if="(action.status === 'approved' || action.status === 'restored') && action.record && action.record.url">
                                                <a
                                                    :href="action.record.url"
                                                    wire:navigate
                                                    class="inline-flex items-center gap-1 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                                                >
                                                    <span x-text="action.record.label ? 'View ' + action.record.label : 'View'"></span>
                                                    <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
                                                </a>
                                            </template>
                                            <template x-if="(action.status === 'approved' || action.status === 'restored') && Array.isArray(action.records) && action.records.length > 0">
                                                <span class="flex flex-wrap gap-2">
                                                    <template x-for="ref in action.records" :key="ref.id">
                                                        <a :href="ref.url" wire:navigate class="inline-flex items-center gap-1 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
                                                            <span x-text="ref.label ? ref.label : 'View'"></span>
                                                            <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
                                                        </a>
                                                    </template>
                                                </span>
                                            </template>
                                        </div>
                                    </template>
                                </div>
