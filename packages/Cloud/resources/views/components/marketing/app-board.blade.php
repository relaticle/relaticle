{{-- Static twin of the opportunities board (Flowforge, vendor/relaticle/flowforge/resources/css/flowforge-modern.css):
     300px columns 12px apart, 48px when collapsed, cards with the company chip and the amount and close-date badges. --}}
@props([
    /** @var list<array{label: string, color: string, collapsed?: bool, count?: array{0: int, 1: int}, slot?: bool, cards: list<array{title: string, company: string, logo?: string, amount: ?int, date: \Carbon\CarbonInterface, attributes?: array<string, mixed>}>}> */
    'columns',
])

@php
    $dateFormat = __('formats.iso.date_without_year');
    $addLabel = __('flowforge::flowforge.add_card', ['card' => __('filament/resources/opportunity.label')]);
    $tray = 'rounded-2xl bg-gray-50 dark:bg-white/5';
    $badge = 'inline-flex items-center gap-1 rounded-lg px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset';
@endphp

<div {{ $attributes->class('snap-x scroll-px-3 overflow-x-auto') }}>
    <div class="flex min-h-full w-max gap-3 p-3" data-app-board>
        @foreach($columns as $column)
            @if($column['collapsed'] ?? false)
                <div class="{{ $tray }} flex w-12 shrink-0 snap-start flex-col items-center gap-2 px-1 pt-3">
                    <span class="size-2 shrink-0 rounded-full" style="background-color: {{ $column['color'] }}" aria-hidden="true"></span>
                    <p class="text-sm font-medium text-gray-950 [writing-mode:vertical-rl] dark:text-white">{{ $column['label'] }}</p>
                    <span class="text-sm tabular-nums text-gray-500 dark:text-gray-400">{{ count($column['cards']) }}</span>
                </div>
            @else
                <div class="flex w-[300px] shrink-0 snap-start flex-col">
                    <div class="flex items-center justify-between gap-2 pb-2 pl-3 pr-1.5">
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="size-2 shrink-0 rounded-full" style="background-color: {{ $column['color'] }}" aria-hidden="true"></span>
                            <p class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $column['label'] }}</p>
                            @if(isset($column['count']))
                                <span class="grid text-sm tabular-nums text-gray-500 dark:text-gray-400" data-board-count>
                                    <span class="col-start-1 row-start-1" data-count-before>{{ $column['count'][0] }}</span>
                                    <span class="invisible col-start-1 row-start-1" data-count-after>{{ $column['count'][1] }}</span>
                                </span>
                            @else
                                <span class="text-sm tabular-nums text-gray-500 dark:text-gray-400">{{ count($column['cards']) }}</span>
                            @endif
                        </div>
                        <x-lucide-plus class="size-5 shrink-0 text-gray-400" aria-hidden="true"/>
                    </div>

                    <div class="{{ $tray }} flex flex-1 flex-col gap-2 p-3">
                        @foreach($column['cards'] as $card)
                            <div {{ (new \Illuminate\View\ComponentAttributeBag($card['attributes'] ?? []))->class('flex flex-col gap-2 rounded-xl bg-white p-3 shadow-xs ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10') }}>
                                <p class="pr-6 text-sm font-medium leading-5 text-gray-950 dark:text-white">{{ $card['title'] }}</p>
                                <p class="flex min-w-0 items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                    @if(isset($card['logo']))
                                        <x-dynamic-component :component="$card['logo']" class="size-5 shrink-0 text-gray-950 dark:text-white" aria-hidden="true"/>
                                    @else
                                        <span class="flex size-5 shrink-0 items-center justify-center rounded bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300" aria-hidden="true">
                                            <x-lucide-building class="size-3"/>
                                        </span>
                                    @endif
                                    <span class="truncate">{{ $card['company'] }}</span>
                                </p>
                                <div class="flex min-h-[23px] flex-wrap items-start gap-1">
                                    @if($card['amount'] !== null)
                                        <span class="{{ $badge }} bg-green-50 text-green-700 ring-green-600/10 dark:bg-green-400/10 dark:text-green-400 dark:ring-green-400/20">
                                            <x-lucide-circle-dollar-sign class="size-4" aria-hidden="true"/>
                                            {{ \Illuminate\Support\Number::currency($card['amount'], 'USD') }}
                                        </span>
                                    @endif
                                    <span class="{{ $badge }} bg-gray-50 text-gray-600 ring-gray-600/10 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10">
                                        <x-lucide-calendar class="size-4 text-gray-400" aria-hidden="true"/>
                                        {{ $card['date']->isoFormat($dateFormat) }}
                                    </span>
                                </div>
                            </div>
                        @endforeach

                        @if($column['slot'] ?? false)
                            <div class="rounded-xl opacity-0 outline-dashed outline-[1.5px] -outline-offset-[1.5px] outline-primary-500" data-board-slot aria-hidden="true"></div>
                        @endif

                        <p data-board-add class="flex items-center justify-center gap-1.5 rounded-lg border border-dashed border-gray-200 bg-gray-100/70 px-3 py-1.5 text-sm text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400">
                            <x-lucide-plus class="size-4 shrink-0" aria-hidden="true"/>
                            {{ $addLabel }}
                        </p>
                    </div>
                </div>
            @endif
        @endforeach
    </div>
</div>
