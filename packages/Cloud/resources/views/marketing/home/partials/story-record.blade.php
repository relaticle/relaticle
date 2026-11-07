@php
    $icon = static fn (string $type): ?string => \Relaticle\Chat\Support\RecordChipRenderer::iconPath($type);
    $chip = 'inline-flex items-center gap-1 rounded-md bg-gray-100 px-1.5 py-0.5 text-xs text-gray-700 dark:bg-white/[0.06] dark:text-gray-200';
    $mailboxWriter = $emailIntegrationActive ? 'mailbox' : null;

    $fields = [
        [__('Company'), 'company', 'Northwind', $mailboxWriter],
        [__('Point of Contact'), 'people', 'Lena Ortiz', $mailboxWriter],
        [__('Amount'), null, '$48,000', null],
        [__('Close Date'), null, __('Nov 6, 2026'), null],
    ];

    $stages = [
        'proposal' => [__('Proposal/Price Quote'), 'bg-gray-100 text-gray-700 dark:bg-white/[0.08] dark:text-gray-200', null],
        'negotiation' => [__('Negotiation/Review'), 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300', 'you'],
        'won' => [__('Closed Won'), 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300', 'rela'],
    ];

    $flash = 'pointer-events-none absolute inset-0 bg-primary-500/10 opacity-0 dark:bg-primary-400/15';
@endphp

<x-cloud::marketing.window :class="$cardClass ?? 'w-full max-w-lg'">
    <div class="flex min-w-0 items-center gap-2.5 px-4 py-3">
        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-amber-500 text-white" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon('opportunity') }}"/></svg>
        </span>
        <p class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-900 dark:text-white">{{ __('Northwind renewal') }}</p>
        <span class="grid">
            @foreach($stages as $state => [$label, $tone])
                <span data-story-state="{{ $state }}" @class(['col-start-1 row-start-1 justify-self-end rounded-md px-1.5 py-0.5 text-xs font-medium', $tone, 'invisible' => $state !== 'won'])>{{ $label }}</span>
            @endforeach
        </span>
    </div>

    <dl class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-white/5 dark:border-white/5">
        @foreach($fields as [$field, $type, $value, $writer])
            <div class="relative flex min-h-11 items-center gap-3 px-4 py-2 text-sm">
                @if($writer)
                    <span data-story-flash="{{ $writer }}" class="{{ $flash }}" aria-hidden="true"></span>
                @endif
                <dt class="w-24 shrink-0 text-gray-500 sm:w-28 dark:text-gray-400">{{ $field }}</dt>
                <dd @if($writer) data-story-field="{{ $writer }}" @endif class="relative flex min-w-0 flex-1 items-center justify-between gap-2 font-medium text-gray-900 dark:text-white">
                    @if($type)
                        <span class="{{ $chip }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-3 w-3 text-gray-400" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon($type) }}"/></svg>
                            {{ $value }}
                        </span>
                    @else
                        <span>{{ $value }}</span>
                    @endif
                    @if($writer)
                        <x-cloud::marketing.actor :as="$writer" class="shrink-0"/>
                    @endif
                </dd>
            </div>
        @endforeach

        <div class="relative flex min-h-11 items-center gap-3 px-4 py-2 text-sm">
            <span data-story-flash="you" class="{{ $flash }}" aria-hidden="true"></span>
            <span data-story-flash="rela" class="{{ $flash }}" aria-hidden="true"></span>
            <dt class="w-24 shrink-0 text-gray-500 sm:w-28 dark:text-gray-400">{{ __('Stage') }}</dt>
            <dd class="relative grid min-w-0 flex-1">
                @foreach($stages as $state => [$label, , $writer])
                    <span data-story-state="{{ $state }}" @class(['col-start-1 row-start-1 flex items-center justify-between gap-2 font-medium text-gray-900 dark:text-white', 'invisible' => $state !== 'won'])>
                        <span>{{ $label }}</span>
                        @if($writer)
                            <x-cloud::marketing.actor :as="$writer" class="shrink-0"/>
                        @endif
                    </span>
                @endforeach
            </dd>
        </div>
    </dl>

    <div class="relative flex items-center gap-3 border-t border-gray-100 px-4 py-3 dark:border-white/5">
        <span data-story-flash="claude" class="{{ $flash }}" aria-hidden="true"></span>
        <div data-story-field="claude" class="relative flex min-w-0 flex-1 items-center gap-3">
            <span class="h-4 w-4 shrink-0 rounded-full border-2 border-gray-300 dark:border-white/20" aria-hidden="true"></span>
            <p class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900 dark:text-white">{{ __('Follow up with Lena Ortiz') }}</p>
            <span class="inline-flex shrink-0 items-center gap-1 rounded-md bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                <x-lucide-calendar class="h-3 w-3"/>{{ __('Thursday') }}
            </span>
            <x-cloud::marketing.actor as="claude" class="shrink-0"/>
        </div>
    </div>
</x-cloud::marketing.window>
