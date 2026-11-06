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
            {{ __('Review the proposal below to update the opportunity.') }}
        </p>
    </div>

    <x-cloud::marketing.window class="ring-[3px] ring-primary-100 dark:ring-primary-400/10" data-approval-card>
        <div class="flex items-center justify-between gap-3 px-4 pt-3">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Update Opportunity') }}</p>
            <x-cloud::marketing.actor as="rela"/>
        </div>

        <div class="flex min-w-0 items-center gap-2.5 px-4 pb-2.5 pt-1.5">
            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-amber-500 text-white" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $opportunityIcon }}"/>
                </svg>
            </span>
            <p class="min-w-0 truncate text-sm font-semibold text-gray-900 dark:text-white">{{ __('Northwind renewal') }}</p>
        </div>

        <div class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-white/5 dark:border-white/5">
            <div class="flex items-center gap-3 px-4 py-2 text-xs font-medium text-gray-500 dark:text-gray-400" aria-hidden="true">
                <span class="size-4 shrink-0"></span>
                <span class="w-24 shrink-0 sm:w-28">{{ __('Attribute') }}</span>
                <span>{{ __('New value') }}</span>
            </div>

            @foreach([
                ['stageIncluded', __('Stage'), __('Negotiation/Review'), null],
                ['closeIncluded', __('Close date'), __('Not set'), $closeDate],
            ] as [$included, $field, $before, $date])
                <div class="flex items-start gap-3 px-4 py-2.5 text-sm" data-approval-field="{{ $included }}">
                    <button
                        type="button"
                        role="checkbox"
                        aria-checked="true"
                        x-bind:aria-checked="{{ $included }}.toString()"
                        x-bind:disabled="decision !== 'pending'"
                        x-on:click="{{ $included }} = ! {{ $included }}"
                        aria-label="{{ __('Include :field', ['field' => $field]) }}"
                        class="mt-0.5 flex size-4 shrink-0 cursor-pointer items-center justify-center rounded border border-primary-600 bg-primary-600 text-white transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-default aria-[checked=false]:border-gray-300 aria-[checked=false]:bg-white aria-[checked=false]:text-transparent dark:aria-[checked=false]:border-white/20 dark:aria-[checked=false]:bg-white/5"
                    >
                        <x-heroicon-m-check class="size-3" aria-hidden="true"/>
                    </button>

                    <span class="w-24 shrink-0 truncate leading-5 text-gray-700 sm:w-28 dark:text-gray-300" x-bind:class="{ 'opacity-50': ! {{ $included }} }">{{ $field }}</span>

                    @if($date)
                        <div class="min-w-0 flex-1" x-show="editing" style="display: none">
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

                    <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-1.5 gap-y-0.5" @if($date) x-show="! editing" @endif x-bind:class="{ 'opacity-50': ! {{ $included }} }">
                        <span class="text-gray-500 line-through decoration-gray-300 dark:text-gray-400 dark:decoration-gray-600">{{ $before }}</span>
                        <x-heroicon-m-arrow-right class="size-3 shrink-0 text-gray-400 dark:text-gray-500" aria-hidden="true"/>
                        @if($date)
                            <span class="font-medium text-gray-900 dark:text-white" x-text="formatted(closeDate)" data-approval-close-date>{{ $date->format('M j, Y') }}</span>
                            <button
                                type="button"
                                x-show="decision === 'pending'"
                                x-on:click="editing = true; draftDate = closeDate; $nextTick(() => $refs.dateInput.focus())"
                                aria-label="{{ __('Edit :field', ['field' => $field]) }}"
                                class="ms-auto inline-flex shrink-0 cursor-pointer items-center justify-center rounded-md p-1 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-gray-200"
                            >
                                <x-heroicon-o-pencil-square class="size-3.5" aria-hidden="true"/>
                            </button>
                        @else
                            <span class="font-medium text-gray-900 dark:text-white">{{ __('Closed Won') }}</span>
                        @endif
                    </span>
                </div>
            @endforeach
        </div>

        <div class="grid border-t border-gray-100 dark:border-white/5">
            <div class="col-start-1 row-start-1 flex items-center justify-end gap-2 px-4 py-2.5" x-bind:class="{ 'invisible': decision !== 'pending' }" data-approval-pending>
                <button type="button" x-on:click="decision = 'discarded'; editing = false" class="inline-flex h-7 cursor-pointer items-center rounded-md px-2.5 text-xs font-medium text-gray-600 transition hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white">{{ __('Discard') }}</button>
                <button
                    type="button"
                    x-on:click="decision = 'approved'; editing = false"
                    x-bind:disabled="! stageIncluded && ! closeIncluded"
                    class="inline-flex h-7 cursor-pointer items-center rounded-md bg-primary-600 px-2.5 text-xs font-medium text-white shadow-sm transition hover:bg-primary-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-not-allowed disabled:opacity-50"
                    data-approval-button
                >{{ __('Save changes') }}</button>
            </div>
            <p class="invisible col-start-1 row-start-1 flex items-center gap-1.5 px-4 py-2.5 text-xs font-medium text-emerald-700 dark:text-emerald-400" x-bind:class="{ 'invisible': decision !== 'approved' }" data-approval-done>
                <x-ri-checkbox-circle-fill class="size-4"/>
                {{ __('Approved by you. Saved to Northwind renewal.') }}
            </p>
            <p class="invisible col-start-1 row-start-1 flex items-center gap-1.5 px-4 py-2.5 text-xs font-medium text-gray-600 dark:text-gray-300" x-bind:class="{ 'invisible': decision !== 'discarded' }" data-approval-discarded>
                <x-ri-close-circle-line class="size-4"/>
                {{ __('Discarded. Nothing was written.') }}
            </p>
        </div>
    </x-cloud::marketing.window>

    <p class="invisible px-1 text-sm leading-relaxed text-gray-900 dark:text-gray-100" x-bind:class="{ 'invisible': decision === 'pending' }" x-text="reply" aria-live="polite" data-approval-followup></p>
</div>
