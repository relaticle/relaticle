@php
    use App\Enums\CustomFields\OpportunityField;
    use Relaticle\Chat\Enums\PendingActionOperation;
    use Relaticle\Chat\Enums\ProposalEntity;

    $opportunityIcon = \Relaticle\Chat\Support\RecordChipRenderer::iconPath('opportunity');
    $companyIcon = \Relaticle\Chat\Support\RecordChipRenderer::iconPath('company');
    $name = __('Northwind platform expansion');
    $amount = 48000;
    $closeDate = today()->addMonthNoOverflow()->endOfMonth();
    $stage = __('enums/custom-fields.opportunity.stage.options.qualification');
    $locale = str_replace('_', '-', app()->getLocale());
    $labels = [
        'stage' => OpportunityField::STAGE->getDisplayName(),
        'amount' => OpportunityField::AMOUNT->getDisplayName(),
        'close' => OpportunityField::CLOSE_DATE->getDisplayName(),
    ];
@endphp

<div
    class="relative w-full max-w-md space-y-3"
    data-approval
    data-decision="pending"
    x-data="{
        decision: 'pending',
        stageIncluded: true,
        amountIncluded: true,
        closeIncluded: true,
        editing: false,
        amount: {{ $amount }},
        draftAmount: {{ $amount }},
        reset() {
            this.decision = 'pending'
            this.stageIncluded = true
            this.amountIncluded = true
            this.closeIncluded = true
            this.editing = false
            this.amount = {{ $amount }}
            this.draftAmount = this.amount
        },
        money(value) {
            return new Intl.NumberFormat(@js($locale), { style: 'currency', currency: 'USD' }).format(value)
        },
        saveAmount() {
            if (Number(this.draftAmount) > 0) {
                this.amount = Number(this.draftAmount)
            }

            this.editing = false
        },
        get leftOut() {
            const left = [this.stageIncluded ? null : @js($labels['stage']), this.amountIncluded ? null : @js($labels['amount']), this.closeIncluded ? null : @js($labels['close'])].filter(Boolean)

            return left.length ? @js(__('(:fields left out)')).replace(':fields', left.join(', ')) : ''
        },
        get reply() {
            if (this.decision === 'discarded') {
                return @js(__('Okay, I did not add :name.', ['name' => $name]))
            }

            return this.amountIncluded
                ? @js(__('Done. :name is in your pipeline at :amount.', ['name' => $name])).replace(':amount', this.money(this.amount))
                : @js(__('Done. :name is in your pipeline with no amount yet.', ['name' => $name]))
        },
    }"
    x-bind:data-decision="decision"
    x-on:demo-reset="reset()"
>
    <div class="flex justify-end" data-approval-ask>
        <p class="max-w-[85%] break-words rounded-2xl rounded-br-md bg-gray-100 px-4 py-2.5 text-sm text-gray-900 dark:bg-white/10 dark:text-gray-100">
            {!! __('Add a platform expansion for :company at $48,000, closing at the end of next month', [
                'company' => '<span class="chat-chip" data-record-type="company"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="'.$companyIcon.'"/></svg><span class="chat-chip-label">Northwind</span></span>',
            ]) !!}
        </p>
    </div>

    <div class="grid px-1 text-sm leading-6">
        <p class="invisible col-start-1 row-start-1 flex items-center gap-2 py-2 text-gray-600 dark:text-gray-300" data-approval-tool aria-hidden="true">
            <span class="size-1.5 rounded-full bg-gray-400 dark:bg-gray-500"></span>
            {{ __('Drafting an opportunity…') }}
        </p>
        <p class="col-start-1 row-start-1 text-gray-900 dark:text-gray-100" data-approval-reply>
            {{ __('I will add :name at $48,000, closing :date.', ['name' => $name, 'date' => $closeDate->isoFormat('ll')]) }}
        </p>
    </div>

    <div class="grid">
        <div class="col-start-1 row-start-1 space-y-2" x-bind:class="{ 'invisible': decision !== 'pending' }">
            <x-cloud::marketing.window class="border-primary-200 ring-[3px] ring-primary-100 dark:border-primary-400/30 dark:ring-primary-400/10" data-approval-card>
                <div class="flex items-start gap-2.5 px-4 pb-1.5 pt-3">
                    <span class="mt-px flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-3.5 w-3.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $opportunityIcon }}"/>
                        </svg>
                    </span>
                    <p class="min-w-0 truncate text-sm leading-6">
                        <span class="text-gray-500 dark:text-gray-400">{{ ProposalEntity::Opportunity->createLabel() }}</span>
                        <span class="font-semibold text-gray-900 dark:text-white">{{ $name }}</span>
                    </p>
                </div>

                <div class="pb-2 pe-2 ps-[3.125rem]">
                    <x-cloud::marketing.rela-dock-field :label="__('Name')" :value="$name" editable/>
                    <x-cloud::marketing.rela-dock-field :label="__('filament/resources/opportunity.fields.company_id.label')" value="Northwind"/>
                    <x-cloud::marketing.rela-dock-field :label="$labels['stage']" :value="$stage" badge editable toggle="stageIncluded" data-approval-field="stageIncluded"/>

                    <div class="group/field flex items-start gap-3" x-show="editing" style="display: none" data-approval-field="amountIncluded">
                        <span class="w-24 shrink-0 truncate py-1.5 text-xs leading-5 text-gray-500 sm:w-32 dark:text-gray-400">{{ $labels['amount'] }}</span>
                        <div class="w-full min-w-0">
                            <label class="flex overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-950/10 focus-within:ring-2 focus-within:ring-primary-600 dark:bg-white/5 dark:ring-white/20">
                                <span class="flex items-center border-e border-gray-200 px-3 text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">$</span>
                                <span class="sr-only">{{ $labels['amount'] }}</span>
                                <input type="number" inputmode="decimal" step="0.01" x-model="draftAmount" x-ref="amountInput" class="block w-full border-0 bg-transparent px-3 py-1.5 text-sm text-gray-950 focus:outline-none focus:ring-0 dark:text-white">
                            </label>
                            <div class="mt-2 flex items-center gap-2">
                                <button type="button" x-on:click="saveAmount()" class="inline-flex h-7 cursor-pointer items-center rounded-lg bg-primary-600 px-2.5 text-xs font-semibold text-white shadow-sm hover:bg-primary-500">{{ __('Save') }}</button>
                                <button type="button" x-on:click="editing = false; draftAmount = amount" class="inline-flex h-7 cursor-pointer items-center rounded-lg bg-white px-2.5 text-xs font-semibold text-gray-950 shadow-sm ring-1 ring-gray-950/10 hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:hover:bg-white/10">{{ __('Cancel') }}</button>
                            </div>
                        </div>
                    </div>
                    <x-cloud::marketing.rela-dock-field
                        x-show="! editing"
                        :label="$labels['amount']"
                        editable="editing = true; draftAmount = amount; $nextTick(() => $refs.amountInput.focus())"
                        toggle="amountIncluded"
                    ><span x-text="money(amount)" data-approval-amount>{{ \Illuminate\Support\Number::currency($amount, 'USD', app()->getLocale()) }}</span></x-cloud::marketing.rela-dock-field>

                    <x-cloud::marketing.rela-dock-field :label="$labels['close']" :value="$closeDate->isoFormat('ll')" editable toggle="closeIncluded" data-approval-field="closeIncluded"/>
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-gray-100 px-4 py-2 dark:border-white/5" x-bind:class="{ 'opacity-50 pointer-events-none': editing }" data-approval-pending>
                    <button type="button" x-on:click="decision = 'discarded'; editing = false" class="inline-flex h-7 cursor-pointer items-center rounded-md px-2.5 text-xs font-medium text-gray-600 transition hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white">{{ ProposalEntity::Opportunity->declineLabel() }}</button>
                    <x-cloud::marketing.rela-approve
                        :label="ProposalEntity::Opportunity->confirmLabel(PendingActionOperation::Create)"
                        x-on:click="decision = 'approved'; editing = false"
                        data-approval-button
                    />
                </div>
            </x-cloud::marketing.window>
        </div>

        <div class="col-start-1 row-start-1 self-start space-y-5 pt-10">
            <div class="grid">
                <div class="invisible col-start-1 row-start-1" x-bind:class="{ 'invisible': decision !== 'approved' }" data-approval-done>
                    <p class="px-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Applied :count change · :when', ['count' => 1, 'when' => __('just now')]) }}</p>
                    <x-cloud::marketing.rela-receipt-line class="mt-1" :verb="ProposalEntity::Opportunity->receiptVerb(PendingActionOperation::Create)" type="opportunity" :name="$name">
                        <x-slot:meta x-show="leftOut !== ''" x-text="leftOut"></x-slot:meta>
                    </x-cloud::marketing.rela-receipt-line>
                </div>

                <x-cloud::marketing.rela-settled
                    class="invisible col-start-1 row-start-1 py-1.5"
                    x-bind:class="{ 'invisible': decision !== 'discarded' }"
                    :summary="ProposalEntity::Opportunity->createSummary($name)"
                    data-approval-discarded
                />
            </div>
            <p class="invisible px-1 text-sm leading-6 text-gray-900 dark:text-gray-100" x-bind:class="{ 'invisible': decision === 'pending' }" x-text="reply" aria-live="polite" data-approval-followup></p>
        </div>
    </div>
</div>
