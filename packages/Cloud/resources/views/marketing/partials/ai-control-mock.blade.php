@php
    $icon = static fn (string $type): ?string => \Relaticle\Chat\Support\RecordChipRenderer::iconPath($type);
    $chip = static fn (string $type, string $label): string => '<span class="chat-chip" data-record-type="'.$type.'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="'.$icon($type).'"/></svg><span class="chat-chip-label">'.e($label).'</span></span>';
@endphp

@if($mock === 'approval')
    <div class="w-full max-w-xs space-y-0.5">
        <p class="px-2 text-xs text-gray-400">{{ __('Applied 1 change · just now') }}</p>
        <div class="flex min-w-0 items-center gap-1.5 px-2 py-1.5 text-sm text-gray-200">
            <x-heroicon-o-check class="size-4 shrink-0 text-gray-400" aria-hidden="true"/>
            <span class="shrink-0">{{ __('Created') }}</span>
            {!! $chip('people', 'Sarah Chen') !!}
        </div>
        <div class="flex min-w-0 items-center gap-1.5 px-2 py-1.5 text-sm text-gray-400">
            <x-heroicon-o-no-symbol class="size-4 shrink-0 text-gray-500" aria-hidden="true"/>
            <span class="min-w-0 truncate line-through decoration-gray-600">{{ __('Call Sarah Chen') }}</span>
            <span class="shrink-0">&middot; {{ __('left out') }}</span>
        </div>
        <div class="flex min-w-0 items-center gap-1.5 px-2 py-1.5 text-sm text-gray-400">
            <x-heroicon-o-clock class="size-4 shrink-0 text-gray-500" aria-hidden="true"/>
            <span class="min-w-0 truncate line-through decoration-gray-600">{{ __('Update Kovra renewal') }}</span>
            <span class="shrink-0">&middot; {{ __('expired') }}</span>
        </div>
    </div>
@elseif($mock === 'batch')
    <div class="w-full max-w-xs space-y-0.5">
        <p class="px-2 text-xs text-gray-400">{{ __('Applied 2 changes · just now') }}</p>
        <div class="flex min-w-0 items-center gap-1.5 px-2 py-1.5 text-sm text-gray-200">
            <x-heroicon-o-check class="size-4 shrink-0 text-gray-400" aria-hidden="true"/>
            <span class="min-w-0 truncate">{{ __('Moved :count opportunities to :stage', ['count' => 2, 'stage' => __('Negotiation/Review')]) }}</span>
        </div>
        <div class="flex min-w-0 items-center gap-1.5 px-2 py-1.5 text-sm text-gray-400">
            <x-heroicon-o-no-symbol class="size-4 shrink-0 text-gray-500" aria-hidden="true"/>
            <span class="min-w-0 truncate line-through decoration-gray-600">{{ __('Kovra add-on seats') }}</span>
            <span class="shrink-0">&middot; {{ __('left out') }}</span>
        </div>
    </div>
@else
    <div class="w-full max-w-xs space-y-3">
        <x-cloud::marketing.actor as="rela"/>
        <p class="px-1 text-sm leading-7 text-gray-100">
            {!! __(':deal is in Negotiation/Review, worth $48,000. :person at :company is the point of contact.', [
                'deal' => $chip('opportunity', __('Kovra renewal')),
                'person' => $chip('people', 'Sarah Chen'),
                'company' => $chip('company', 'Kovra Systems'),
            ]) !!}
        </p>
    </div>
@endif
