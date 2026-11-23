@php
    $icon = static fn (string $type): ?string => \Relaticle\Chat\Support\RecordChipRenderer::iconPath($type);
    $chip = static fn (string $type, string $label): string => '<span class="chat-chip" data-record-type="'.$type.'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="'.$icon($type).'"/></svg><span class="chat-chip-label">'.e($label).'</span></span>';
    $expiredSummary = __('Update opportunity "Kovra renewal"');
@endphp

@if($mock === 'approval')
    <div class="w-full max-w-xs">
        <p class="px-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Applied :count change · :when', ['count' => 1, 'when' => __('just now')]) }}</p>
        <div class="mt-1 space-y-0.5">
            <x-cloud::marketing.rela-receipt-line :verb="\Relaticle\Chat\Enums\ProposalEntity::People->receiptVerb(\Relaticle\Chat\Enums\PendingActionOperation::Create)" type="people" name="Sarah Chen"/>
            <x-cloud::marketing.rela-receipt-line outcome="left_out" :name="__('Call Sarah Chen')"/>
        </div>
        <x-cloud::marketing.rela-settled class="mt-4 py-1.5" outcome="expired" :summary="$expiredSummary"/>
    </div>
@elseif($mock === 'batch')
    <div class="w-full max-w-xs">
        <p class="px-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Applied :count changes · :when', ['count' => 2, 'when' => __('just now')]) }}</p>
        <div class="mt-1 space-y-0.5">
            <x-cloud::marketing.rela-receipt-line
                :label="__('Moved :count :entities to :value', ['count' => 2, 'entities' => \Relaticle\Chat\Enums\ProposalEntity::Opportunity->plural(2), 'value' => __('enums/custom-fields.opportunity.stage.options.negotiation_review')])"
                :meta="\Illuminate\Support\Number::currency(86000, 'USD', app()->getLocale())"
            />
            <x-cloud::marketing.rela-receipt-line outcome="left_out" :name="__('Kovra add-on seats')"/>
        </div>
    </div>
@else
    <div class="w-full max-w-xs space-y-3">
        <x-cloud::marketing.actor as="rela"/>
        <p class="px-1 text-sm leading-7 text-gray-700 dark:text-gray-100">
            {!! __(':deal is in Negotiation/Review, worth $48,000. :person at :company is the point of contact.', [
                'deal' => $chip('opportunity', __('Kovra renewal')),
                'person' => $chip('people', 'Sarah Chen'),
                'company' => $chip('company', 'Kovra Systems'),
            ]) !!}
        </p>
    </div>
@endif
