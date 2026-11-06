@php
    $assistantName = (string) config('chat.assistant_name');
    $opportunityIcon = \Relaticle\Chat\Support\RecordChipRenderer::iconPath('opportunity');
    $closeDate = today();
@endphp

<div
    class="relative w-full max-w-md space-y-3"
    data-approval
    data-decision="pending"
    x-data="{
        decision: 'pending',
        stageIncluded: true,
        closeIncluded: true,
        editing: false,
        closeDate: @js($closeDate->toDateString()),
        draftDate: @js($closeDate->toDateString()),
        reset() {
            this.decision = 'pending'
            this.stageIncluded = true
            this.closeIncluded = true
            this.editing = false
            this.closeDate = @js($closeDate->toDateString());
            this.draftDate = this.closeDate
        },
        formatted(date) {
            return new Date(`${date}T00:00:00`).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
        },
        saveDate() {
            if (this.draftDate) {
                this.closeDate = this.draftDate
            }

            this.editing = false
        },
        get receiptMeta() {
            const stage = @js(__('Stage'));
            const close = @js(__('Close date'));
            const written = [this.stageIncluded ? stage : null, this.closeIncluded ? close : null].filter(Boolean)
            const left = [this.stageIncluded ? null : stage, this.closeIncluded ? null : close].filter(Boolean)

            return '· ' + written.join(', ') + (left.length ? ' ' + @js(__('(:fields left out)')).replace(':fields', left.join(', ')) : '')
        },
        get reply() {
            if (this.decision === 'discarded') {
                return @js(__('Okay, I left Northwind renewal as it was.'));
            }

            const date = this.formatted(this.closeDate)

            if (this.stageIncluded && this.closeIncluded) {
                return @js(__('Done. Northwind renewal is Closed Won, closing :date.')).replace(':date', date)
            }

            return this.stageIncluded
                ? @js(__('Done. Northwind renewal is Closed Won. The close date stays as it was.'))
                : @js(__('Done. Northwind renewal now closes :date. The stage stays Negotiation/Review.')).replace(':date', date)
        },
    }"
    x-bind:data-decision="decision"
    x-on:demo-reset="reset()"
>
    <div class="flex justify-end" data-approval-ask>
        <p class="max-w-[85%] rounded-2xl rounded-br-md bg-gray-100 px-4 py-2.5 text-sm leading-relaxed text-gray-900 dark:bg-white/10 dark:text-gray-100">
            {{ __('Mark') }}
            <span class="chat-chip" data-record-type="opportunity">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $opportunityIcon }}"/>
                </svg>
                <span class="chat-chip-label">{{ __('Northwind renewal') }}</span>
            </span>
            {{ __('as won and close it today') }}
        </p>
    </div>

    <div class="grid px-1 text-sm leading-relaxed">
        <p class="invisible col-start-1 row-start-1 flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300" data-approval-tool aria-hidden="true">
            <span class="size-1.5 rounded-full bg-gray-400 dark:bg-gray-500"></span>
            {{ __('Looking up Northwind renewal…') }}
        </p>
        <p class="col-start-1 row-start-1 text-gray-900 dark:text-gray-100" data-approval-reply>
            {{ __('I\'ll mark Northwind renewal as Closed Won and set its close date to today.') }}
        </p>
    </div>

    <div class="grid">
        <div class="col-start-1 row-start-1 space-y-2" x-bind:class="{ 'invisible': decision !== 'pending' }">
            <x-cloud::marketing.window class="border-primary-200 ring-[3px] ring-primary-100 dark:border-primary-400/30 dark:ring-primary-400/10" data-approval-card>
                <div class="flex items-start gap-2.5 px-4 pb-1.5 pt-3">
                    <span class="mt-px flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $opportunityIcon }}"/>
                        </svg>
                    </span>
                    <p class="min-w-0 truncate text-sm leading-6">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('Update') }}</span>
                        <span class="font-semibold text-gray-900 dark:text-white">{{ __('Northwind renewal') }}</span>
                    </p>
                </div>

                <div class="space-y-1 pb-2.5 pe-3 ps-[3.125rem]">
                    @foreach([
                        ['stageIncluded', __('Stage'), __('Negotiation/Review'), null],
                        ['closeIncluded', __('Close date'), __('Not set'), $closeDate],
                    ] as [$included, $field, $before, $date])
                        <div class="group/field flex items-start gap-3 text-sm" data-approval-field="{{ $included }}">
                            <span class="w-24 shrink-0 truncate text-xs leading-8 text-gray-500 sm:w-28 dark:text-gray-400" x-bind:class="{ 'line-through opacity-60': ! {{ $included }} }">{{ $field }}</span>

                            @if($date)
                                <div class="min-w-0 flex-1 py-1" x-show="editing" style="display: none">
                                    <input
                                        type="date"
                                        x-model="draftDate"
                                        x-ref="dateInput"
                                        aria-label="{{ __('Close date') }}"
                                        class="block w-full rounded-md border border-gray-300 bg-white px-2 py-1 text-sm text-gray-900 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20 dark:border-white/15 dark:bg-white/5 dark:text-white dark:[color-scheme:dark]"
                                    >
                                    <div class="mt-2 flex items-center gap-2">
                                        <button type="button" x-on:click="saveDate()" class="inline-flex h-7 cursor-pointer items-center rounded-md bg-primary-600 px-2.5 text-xs font-medium text-white shadow-sm hover:bg-primary-500">{{ __('Save') }}</button>
                                        <button type="button" x-on:click="editing = false; draftDate = closeDate" class="inline-flex h-7 cursor-pointer items-center rounded-md px-2.5 text-xs font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-white/5">{{ __('Cancel') }}</button>
                                    </div>
                                </div>
                            @endif

                            <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-1.5 gap-y-0.5 leading-8" @if($date) x-show="! editing" @endif x-bind:class="{ 'line-through opacity-60': ! {{ $included }} }">
                                @if($date)
                                    <span class="text-gray-500 line-through decoration-gray-300 dark:text-gray-400 dark:decoration-gray-600">{{ $before }}</span>
                                    <x-heroicon-m-arrow-right class="size-3 shrink-0 text-gray-400 dark:text-gray-500" aria-hidden="true"/>
                                    <span class="text-gray-900 dark:text-white" x-text="formatted(closeDate)" data-approval-close-date>{{ $date->format('M j, Y') }}</span>
                                    <button
                                        type="button"
                                        x-show="decision === 'pending'"
                                        x-on:click="editing = true; draftDate = closeDate; $nextTick(() => $refs.dateInput.focus())"
                                        aria-label="{{ __('Edit :field', ['field' => $field]) }}"
                                        class="inline-flex min-h-8 min-w-8 shrink-0 cursor-pointer items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 sm:opacity-0 sm:group-hover/field:opacity-100 dark:hover:bg-white/10 dark:hover:text-gray-300"
                                    >
                                        <x-heroicon-o-pencil-square class="h-3.5 w-3.5" aria-hidden="true"/>
                                    </button>
                                @else
                                    <span class="flex basis-full flex-col py-1.5 leading-5">
                                        <span class="text-xs text-gray-500 line-through decoration-gray-300 dark:text-gray-400 dark:decoration-gray-600">{{ $before }}</span>
                                        <span class="text-gray-900 dark:text-white">{{ __('Closed Won') }}</span>
                                    </span>
                                @endif
                            </span>

                            <button
                                type="button"
                                x-bind:disabled="decision !== 'pending'"
                                x-on:click="{{ $included }} = ! {{ $included }}"
                                x-bind:aria-label="{{ $included }} ? @js(__('Leave out :field', ['field' => $field])) : @js(__('Keep :field', ['field' => $field]))"
                                aria-label="{{ __('Leave out :field', ['field' => $field]) }}"
                                class="inline-flex min-h-8 min-w-8 shrink-0 cursor-pointer items-center justify-center rounded-md transition focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500"
                                x-bind:class="{{ $included }} ? 'text-gray-400 hover:bg-gray-100 hover:text-gray-700 sm:opacity-0 sm:group-hover/field:opacity-100 dark:hover:bg-white/10 dark:hover:text-gray-200' : 'px-1.5 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400'"
                            >
                                <x-heroicon-o-x-mark class="h-3.5 w-3.5" x-show="{{ $included }}" aria-hidden="true"/>
                                <span x-show="! {{ $included }}" style="display: none">{{ __('Keep') }}</span>
                            </button>
                        </div>
                    @endforeach
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-gray-100 px-4 py-2 dark:border-white/5" data-approval-pending>
                    <button type="button" x-on:click="decision = 'discarded'; editing = false" class="inline-flex h-7 cursor-pointer items-center rounded-md px-2.5 text-xs font-medium text-gray-600 transition hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white">{{ __('Discard') }}</button>
                    <x-cloud::marketing.rela-approve
                        :label="__('Approve')"
                        x-on:click="decision = 'approved'; editing = false"
                        x-bind:disabled="! stageIncluded && ! closeIncluded"
                        data-approval-button
                    />
                </div>
            </x-cloud::marketing.window>

            <x-cloud::marketing.rela-locked/>
        </div>

        <div class="col-start-1 row-start-1 self-start space-y-3">
        <div class="grid">
        <div class="invisible col-start-1 row-start-1" x-bind:class="{ 'invisible': decision !== 'approved' }" data-approval-done>
            <p class="px-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Applied 1 change · just now') }}</p>
            <div class="mt-1 flex min-w-0 items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm text-gray-700 dark:text-gray-300">
                <x-heroicon-o-check class="size-4 shrink-0 text-gray-500 dark:text-gray-400" aria-hidden="true"/>
                <span class="shrink-0 whitespace-nowrap">{{ __('Updated') }}</span>
                <span class="chat-chip min-w-0" data-record-type="opportunity">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3 w-3" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $opportunityIcon }}"/>
                    </svg>
                    <span class="chat-chip-label">{{ __('Northwind renewal') }}</span>
                </span>
                <span class="min-w-0 truncate text-gray-500 dark:text-gray-400" x-text="receiptMeta"></span>
            </div>
        </div>

        <x-cloud::marketing.rela-settled
            class="invisible col-start-1 row-start-1 py-1.5"
            x-bind:class="{ 'invisible': decision !== 'discarded' }"
            :summary="__('Update Northwind renewal')"
            data-approval-discarded
        />
        </div>
        <p class="invisible px-1 text-sm leading-relaxed text-gray-900 dark:text-gray-100" x-bind:class="{ 'invisible': decision === 'pending' }" x-text="reply" aria-live="polite" data-approval-followup></p>
        </div>
    </div>


</div>
