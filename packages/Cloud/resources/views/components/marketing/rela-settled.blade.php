{{-- Static twin of the one grey line a discarded or expired proposal leaves
     (packages/Chat/resources/views/livewire/chat/partials/_proposal-receipt.blade.php). --}}
@props(['summary' => '', 'outcome' => 'discarded'])

@php
    $settled = \Relaticle\Chat\Enums\ProposalOutcome::from($outcome);
@endphp

<div {{ $attributes->class(['flex flex-wrap items-center gap-x-2 gap-y-1 px-2 text-sm text-gray-500 dark:text-gray-400']) }} data-mock-settled>
    <x-dynamic-component :component="'heroicon-o-'.$settled->icon()" @class(['size-4 shrink-0', $settled->iconClass()]) aria-hidden="true"/>
    <span class="min-w-0 line-through decoration-gray-300 dark:decoration-gray-600">{{ $summary }}</span>
    <span>&middot; {{ $settled->label() }}</span>
    <span class="text-xs font-medium text-primary-600 dark:text-primary-400">{{ __('Propose again') }}</span>
</div>
