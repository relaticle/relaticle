@php
    $columns = [
        ['key' => 'prospecting', 'label' => __('Prospecting'), 'badge' => 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300', 'count' => [2, 1], 'cards' => [
            ['title' => __('Notion API Integration'), 'company' => 'Notion', 'icon' => 'ri-notion-fill', 'amount' => '$18,000.00', 'date' => __('Oct 25')],
            ['title' => __('Figma Design System Rollout'), 'company' => 'Figma', 'icon' => 'ri-figma-fill', 'amount' => '$12,500.00', 'date' => __('Nov 6'), 'dragged' => true],
        ]],
        ['key' => 'qualification', 'label' => __('Qualification'), 'badge' => 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300', 'count' => [1, 2], 'cards' => [
            ['title' => __('Dropbox SSO Upgrade'), 'company' => 'Dropbox', 'icon' => 'ri-dropbox-fill', 'amount' => '$9,800.00', 'date' => __('Nov 1')],
        ], 'slot' => true],
        ['key' => 'needs-analysis', 'label' => __('Needs Analysis'), 'badge' => 'bg-teal-50 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300', 'count' => [2, 2], 'cards' => [
            ['title' => __('Apple Developer Partnership'), 'company' => 'Apple', 'icon' => 'ri-apple-fill', 'amount' => '$25,000.00', 'date' => __('Nov 4')],
            ['title' => __('Vercel Partner Program'), 'company' => 'Vercel', 'icon' => 'ri-vercel-fill', 'amount' => '$30,000.00', 'date' => __('Oct 18')],
        ]],
        ['key' => 'value-proposition', 'label' => __('Value Proposition'), 'badge' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300', 'count' => [2, 2], 'cards' => [
            ['title' => __('GitHub Enterprise Migration'), 'company' => 'GitHub', 'icon' => 'ri-github-fill', 'amount' => '$48,000.00', 'date' => __('Oct 23')],
            ['title' => __('Spotify Ads Pilot'), 'company' => 'Spotify', 'icon' => 'ri-spotify-fill', 'amount' => '$24,000.00', 'date' => __('Oct 14')],
        ]],
    ];
@endphp

<x-cloud::marketing.window class="absolute left-4 top-14 w-[74rem] md:left-10" data-board>
    <div class="flex items-center gap-3 border-b border-gray-200/60 px-4 py-2.5 dark:border-white/[0.06]">
        <span class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-gray-200 px-2.5 text-sm font-medium text-gray-900 dark:border-white/10 dark:text-white">
            <x-ri-layout-column-line class="h-4 w-4 text-gray-500 dark:text-gray-400"/>
            {{ __('Board') }}
            <x-ri-arrow-down-s-line class="h-3.5 w-3.5 text-gray-400"/>
        </span>
        <span class="inline-flex h-8 w-64 items-center gap-2 rounded-lg border border-gray-200 px-2.5 text-sm text-gray-400 dark:border-white/10 dark:text-gray-500">
            <x-ri-search-line class="h-4 w-4"/>
            {{ __('Search') }}
        </span>
        <x-ri-filter-3-line class="ml-auto h-4 w-4 text-gray-400"/>
    </div>

    <div class="flex gap-3 p-3 md:gap-5 md:p-4">
        @foreach($columns as $column)
            <div class="w-[232px] shrink-0 md:w-[300px]">
                <div class="flex items-center justify-between gap-2 px-1 pb-2">
                    <div class="flex items-center gap-2">
                        <h4 class="text-sm font-medium text-gray-900 dark:text-white">{{ $column['label'] }}</h4>
                        <span class="{{ $column['badge'] }} grid rounded-md px-1.5 py-0.5 text-xs font-medium tabular-nums" @if($column['count'][0] !== $column['count'][1]) data-board-count @endif>
                            <span class="col-start-1 row-start-1" data-count-before>{{ $column['count'][0] }}</span>
                            @if($column['count'][0] !== $column['count'][1])
                                <span class="invisible col-start-1 row-start-1" data-count-after>{{ $column['count'][1] }}</span>
                            @endif
                        </span>
                    </div>
                    <x-ri-add-line class="h-4 w-4 text-gray-400"/>
                </div>

                <div class="min-h-[14rem] space-y-2 rounded-2xl bg-gray-100 p-2 dark:bg-white/[0.04]">
                    @foreach($column['cards'] as $card)
                        <div class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" @if($card['dragged'] ?? false) data-board-card @endif>
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $card['title'] }}</p>
                                <x-ri-more-2-fill class="h-4 w-4 shrink-0 text-gray-400"/>
                            </div>
                            <p class="mt-2 inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                <x-dynamic-component :component="$card['icon']" class="h-4 w-4 text-gray-900 dark:text-white"/>
                                {{ $card['company'] }}
                            </p>
                            <div class="mt-2.5 flex flex-wrap gap-1.5">
                                <span class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-1.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20">
                                    <x-ri-money-dollar-circle-line class="h-3.5 w-3.5"/>
                                    {{ $card['amount'] }}
                                </span>
                                <span class="inline-flex items-center gap-1 rounded-md bg-gray-50 px-1.5 py-0.5 text-xs font-medium text-gray-600 ring-1 ring-gray-200 dark:bg-white/[0.04] dark:text-gray-300 dark:ring-white/10">
                                    <x-ri-calendar-line class="h-3.5 w-3.5"/>
                                    {{ $card['date'] }}
                                </span>
                            </div>
                        </div>
                    @endforeach

                    @if($column['slot'] ?? false)
                        <div class="rounded-xl opacity-0 outline-dashed outline-[1.5px] -outline-offset-[1.5px] outline-primary-500" data-board-slot aria-hidden="true"></div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <svg data-board-cursor class="pointer-events-none absolute left-0 top-0 z-30 h-5 w-5 opacity-0 drop-shadow" viewBox="0 0 24 24" aria-hidden="true">
        <path d="M4 2.5l15 9.2-6.6 1.3 3.8 7.3-2.6 1.3-3.8-7.3L4 19z" fill="#111827" stroke="#fff" stroke-width="1.5" stroke-linejoin="round"/>
    </svg>
</x-cloud::marketing.window>
