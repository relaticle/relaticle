@php
    $icon = static fn (string $type): ?string => \Relaticle\Chat\Support\RecordChipRenderer::iconPath($type);
    $block = 'w-full max-w-xs overflow-hidden rounded-xl border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)]';
    $chip = static fn (string $type, string $label): string => '<span class="chat-chip" data-record-type="'.$type.'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="'.$icon($type).'"/></svg><span class="chat-chip-label">'.e($label).'</span></span>';
@endphp

@if($mock === 'approval')
    <ul class="{{ $block }}">
        @foreach([['people', 'Sarah Chen', __('Created'), 'bg-green-500'], ['task', __('Call Sarah Chen'), __('Rejected'), 'bg-red-500'], ['opportunity', __('Kovra renewal'), __('Expired'), 'bg-gray-500']] as [$type, $record, $outcome, $dot])
            <li class="flex min-w-0 items-center gap-2.5 border-b border-white/5 px-3.5 py-2.5 last:border-b-0">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-primary-500/10 text-primary-400" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon($type) }}"/></svg>
                </span>
                <span class="min-w-0 truncate text-sm font-semibold text-white">{{ $record }}</span>
                <span class="ms-auto inline-flex shrink-0 items-center gap-1.5 text-micro font-medium text-gray-400">
                    <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dot }}"></span>
                    {{ $outcome }}
                </span>
            </li>
        @endforeach
    </ul>
@elseif($mock === 'batch')
    <ul class="{{ $block }}">
        @foreach([[__('Kovra renewal'), true], [__('Kovra add-on seats'), false], [__('Kovra platform expansion'), true]] as [$deal, $saved])
            <li class="flex items-center gap-2.5 border-b border-white/5 px-3.5 py-2.5 last:border-b-0">
                {!! $chip('opportunity', $deal) !!}
                <span class="ms-auto inline-flex shrink-0 items-center gap-1.5 text-micro font-medium text-gray-400">
                    <span @class(['h-1.5 w-1.5 shrink-0 rounded-full', 'bg-green-500' => $saved, 'bg-gray-500' => ! $saved])></span>
                    {{ $saved ? __('Updated') : __('Skipped') }}
                </span>
            </li>
        @endforeach
    </ul>
@else
    <div class="w-full max-w-xs space-y-3">
        <x-cloud::marketing.actor as="rela"/>
        <p class="px-1 text-sm leading-7 text-gray-100">
            {!! __(':deal is in Negotiation, worth $48,000. :person at :company is the point of contact.', [
                'deal' => $chip('opportunity', __('Kovra renewal')),
                'person' => $chip('people', 'Sarah Chen'),
                'company' => $chip('company', 'Kovra Systems'),
            ]) !!}
        </p>
    </div>
@endif
