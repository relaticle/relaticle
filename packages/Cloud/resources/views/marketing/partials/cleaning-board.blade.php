@php
    $columns = [
        ['label' => __('Walkthrough'), 'color' => \App\Enums\OptionColor::Purple->value, 'cards' => [
            ['title' => __(':company cleaning', ['company' => 'Fairmead Learning Centre']), 'company' => 'Fairmead Learning Centre', 'amount' => null],
            ['title' => __(':company cleaning', ['company' => 'Dunmore Coworking']), 'company' => 'Dunmore Coworking', 'amount' => null],
        ]],
        ['label' => __('Quote'), 'color' => \App\Enums\OptionColor::Blue->value, 'cards' => [
            ['title' => __(':company office cleaning', ['company' => 'Meridian House']), 'company' => 'Meridian House', 'amount' => 4800],
            ['title' => __(':company daily cleaning', ['company' => 'Kestrel Fitness']), 'company' => 'Kestrel Fitness', 'amount' => 2900],
            ['title' => __(':company clinical cleaning', ['company' => 'Oakline Clinic']), 'company' => 'Oakline Clinic', 'amount' => 1600],
        ]],
        ['label' => __('Trial clean'), 'color' => \App\Enums\OptionColor::Cyan->value, 'cards' => []],
        ['label' => __('Contract'), 'color' => \App\Enums\OptionColor::Amber->value, 'cards' => []],
        ['label' => __('Active'), 'color' => \App\Enums\OptionColor::Green->value, 'cards' => []],
    ];
@endphp

<x-cloud::marketing.window class="w-full">
    <div class="border-rail flex items-center justify-between gap-3 border-b px-4 py-3">
        <p class="text-sm font-medium text-gray-950 dark:text-white">{{ __('Pipeline') }}</p>
        <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Example workspace: Clearwell Cleaning') }}</span>
    </div>

    <div class="relative overflow-x-auto lg:overflow-visible">
        <div class="flex gap-3 p-3 md:gap-4 md:p-4" data-cleaning-board>
            @foreach($columns as $column)
                <div class="flex w-[220px] shrink-0 flex-col rounded-2xl lg:w-auto lg:min-w-0 lg:flex-1 lg:shrink bg-gray-100 ring-1 ring-gray-950/5 md:w-[236px] dark:bg-white/[0.04] dark:ring-white/10">
                    <div class="flex items-center gap-2 px-4 pb-3 pt-4">
                        <span class="h-2 w-2 shrink-0 rounded-full" style="background-color: {{ $column['color'] }}" aria-hidden="true"></span>
                        <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $column['label'] }}</p>
                        <span class="text-sm tabular-nums text-gray-600 dark:text-gray-400">{{ count($column['cards']) }}</span>
                    </div>

                    <div class="flex-1 space-y-2 px-2 pb-2">
                        @foreach($column['cards'] as $card)
                            <div class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $card['title'] }}</p>
                                <p class="mt-2 inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                    <x-lucide-building-2 class="h-4 w-4 text-gray-500 dark:text-gray-400"/>
                                    {{ $card['company'] }}
                                </p>
                                @if($card['amount'] !== null)
                                    <div class="mt-2.5">
                                        <span class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-1.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20">
                                            <x-lucide-circle-dollar-sign class="h-3.5 w-3.5"/>
                                            {{ \Illuminate\Support\Number::currency($card['amount'], 'USD', precision: 0) }}
                                        </span>
                                    </div>
                                @endif
                            </div>
                        @endforeach

                        <p class="flex items-center gap-1.5 px-3 py-2 text-sm text-gray-600 dark:text-gray-400">
                            <x-lucide-plus class="size-4 shrink-0"/>
                            {{ __('Add opportunity') }}
                        </p>
                    </div>
                </div>
            @endforeach
        </div>

            </div>
</x-cloud::marketing.window>
