@php
    $stageColors = \App\Enums\CustomFields\OpportunityField::STAGE->getOptionColors() ?? [];

    $columns = [
        ['stage' => 'Prospecting', 'label' => __('Prospecting'), 'count' => [2, 1], 'cards' => [
            ['title' => __('Notion API Integration'), 'company' => 'Notion', 'icon' => 'ri-notion-fill', 'amount' => '$18,000.00', 'date' => __('Oct 25')],
            ['title' => __('Figma Design System Rollout'), 'company' => 'Figma', 'icon' => 'ri-figma-fill', 'amount' => '$12,500.00', 'date' => __('Nov 6'), 'dragged' => true],
        ]],
        ['stage' => 'Qualification', 'label' => __('Qualification'), 'count' => [1, 2], 'cards' => [
            ['title' => __('Dropbox SSO Upgrade'), 'company' => 'Dropbox', 'icon' => 'ri-dropbox-fill', 'amount' => '$9,800.00', 'date' => __('Nov 1')],
        ], 'slot' => true],
        ['stage' => 'Needs Analysis', 'label' => __('Needs Analysis'), 'count' => [2, 2], 'cards' => [
            ['title' => __('Apple Developer Partnership'), 'company' => 'Apple', 'icon' => 'ri-apple-fill', 'amount' => '$25,000.00', 'date' => __('Nov 4')],
            ['title' => __('Vercel Partner Program'), 'company' => 'Vercel', 'icon' => 'ri-vercel-fill', 'amount' => '$30,000.00', 'date' => __('Oct 18')],
        ]],
        ['stage' => 'Value Proposition', 'label' => __('Value Proposition'), 'count' => [2, 2], 'cards' => [
            ['title' => __('GitHub Enterprise Migration'), 'company' => 'GitHub', 'icon' => 'ri-github-fill', 'amount' => '$48,000.00', 'date' => __('Oct 23')],
            ['title' => __('Spotify Ads Pilot'), 'company' => 'Spotify', 'icon' => 'ri-spotify-fill', 'amount' => '$24,000.00', 'date' => __('Oct 14')],
        ]],
    ];
@endphp

<div class="absolute inset-x-6 bottom-0 top-10 flex gap-3 md:inset-x-10 md:gap-5" data-board>
    @foreach($columns as $column)
        <div class="flex w-[232px] shrink-0 flex-col rounded-t-2xl bg-gray-100 ring-1 ring-gray-950/5 md:w-[300px] dark:bg-white/[0.04] dark:ring-white/10">
            <div class="flex items-center justify-between gap-2 px-4 pb-3 pt-4">
                <div class="flex min-w-0 items-center gap-2">
                    <span class="h-2 w-2 shrink-0 rounded-full" style="background-color: {{ $stageColors[$column['stage']] ?? 'currentColor' }}" aria-hidden="true"></span>
                    <h4 class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $column['label'] }}</h4>
                    <span class="grid rounded-md bg-white px-1.5 py-0.5 text-xs font-medium tabular-nums text-gray-600 ring-1 ring-gray-950/5 dark:bg-white/[0.06] dark:text-gray-300 dark:ring-white/10" @if($column['count'][0] !== $column['count'][1]) data-board-count @endif>
                        <span class="col-start-1 row-start-1" data-count-before>{{ $column['count'][0] }}</span>
                        @if($column['count'][0] !== $column['count'][1])
                            <span class="invisible col-start-1 row-start-1" data-count-after>{{ $column['count'][1] }}</span>
                        @endif
                    </span>
                </div>
                <x-lucide-plus class="h-4 w-4 shrink-0 text-gray-400"/>
            </div>

            <div class="flex-1 space-y-2 px-2 pb-2">
                @foreach($column['cards'] as $card)
                    <div class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" @if($card['dragged'] ?? false) data-board-card @endif>
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $card['title'] }}</p>
                            <x-lucide-ellipsis-vertical class="h-4 w-4 shrink-0 text-gray-400"/>
                        </div>
                        <p class="mt-2 inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                            <x-dynamic-component :component="$card['icon']" class="h-4 w-4 text-gray-900 dark:text-white"/>
                            {{ $card['company'] }}
                        </p>
                        <div class="mt-2.5 flex flex-wrap gap-1.5">
                            <span class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-1.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20">
                                <x-lucide-circle-dollar-sign class="h-3.5 w-3.5"/>
                                {{ $card['amount'] }}
                            </span>
                            <span class="inline-flex items-center gap-1 rounded-md bg-gray-50 px-1.5 py-0.5 text-xs font-medium text-gray-600 ring-1 ring-gray-200 dark:bg-white/[0.04] dark:text-gray-300 dark:ring-white/10">
                                <x-lucide-calendar class="h-3.5 w-3.5"/>
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

<div class="pointer-events-none absolute inset-y-0 right-0 hidden w-28 bg-linear-to-r from-transparent to-tint md:block" aria-hidden="true"></div>
