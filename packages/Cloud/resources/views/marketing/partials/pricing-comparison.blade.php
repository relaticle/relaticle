@use(Illuminate\Support\Number)
@use(App\Enums\Plan)
@php
    $plans = [
        'pro' => ['label' => __('billing.plans.cloud_pro'), 'price' => __(':price/mo', ['price' => '$'.Plan::Pro->yearlyPricePerMonth()])],
        'scale' => ['label' => __('billing.plans.cloud_scale'), 'price' => __(':price/mo', ['price' => '$'.Plan::Scale->yearlyPricePerMonth()])],
        'enterprise' => ['label' => __('billing.plans.enterprise'), 'price' => __('From :price/yr', ['price' => '$'.Number::abbreviate(config('relaticle.enterprise.starting_price_yearly'), maxPrecision: 1)])],
    ];
    $recommended = 1;

    $onEveryPlan = [
        __('Unlimited users and records'),
        __('Every AI model on offer'),
        __('REST API and MCP server'),
        __('Export to CSV anytime'),
    ];

    $credits = fn (Plan $plan): array => [
        __(':credits credits a month', ['credits' => Number::format($plan->credits())]),
        __('cloud::billing.allowance.replies', ['replies' => Number::format($plan->typicalReplies())]),
    ];
    $agreed = __('cloud::billing.allowance.agreed');
    $emailActive = \Laravel\Pennant\Feature::active(\App\Features\EmailIntegration::class);

    $rows = [
        [__(':name, the AI assistant', ['name' => config('chat.assistant_name')]), __('Each reply uses credits, depending on the model and the work it does'), [$credits(Plan::Pro), $credits(Plan::Scale), $agreed]],
        ...($emailActive ? [
            [__('Connected mailboxes'), __('Gmail and Outlook accounts that sync email and meetings'), [__('Up to :count', ['count' => Plan::Pro->connectedMailboxes()]), __('Up to :count', ['count' => Plan::Scale->connectedMailboxes()]), $agreed]],
        ] : []),
        [__('REST API and MCP throughput'), __('How many requests Claude, ChatGPT and your own tools can send'), [__('Standard request limit'), __(':multiplierx the request limit', ['multiplier' => Plan::Scale->throughputMultiplier()]), $agreed]],
        [__('Where it runs'), null, [__('Relaticle runs it'), __('Relaticle runs it'), __('Agreed deployment and maintenance')]],
        [__('Setup'), null, [__('You set it up yourself'), __('You set it up yourself'), __('A scoped implementation project')]],
        [__('cloud::billing.comparison.support'), null, [__('Email'), __('Priority email'), __('Agreed support with the founding team')]],
    ];

    $headerCell = 'bg-surface border-rail sticky top-(--header-height) z-10 border-b py-4 text-left align-bottom';
    $tint = fn (int $index): string => $index === $recommended ? 'sm:bg-highlight' : '';
    $rowHeader = 'block px-6 pt-5 pb-2 text-left align-top sm:table-cell sm:py-5 md:px-10';
    $valueCell = 'block px-6 py-1 text-sm leading-relaxed text-gray-700 before:mr-1.5 before:font-medium before:text-gray-950 before:content-[attr(data-label)] last:pb-5 sm:table-cell sm:px-5 sm:py-5 sm:align-top sm:before:content-none lg:px-6 dark:text-gray-300 dark:before:text-white';
@endphp

<div class="border-rail flex flex-col gap-x-8 gap-y-3 border-b px-6 py-6 md:px-10 lg:flex-row lg:items-center">
    <p class="type-label shrink-0 text-gray-500 dark:text-gray-400">{{ __('On every plan') }}</p>
    <ul class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-gray-950 dark:text-white">
        @foreach($onEveryPlan as $item)
            <li class="flex items-center gap-2">
                <x-lucide-check class="size-4 shrink-0 text-primary dark:text-primary-400" aria-hidden="true" />
                {{ $item }}
            </li>
        @endforeach
    </ul>
</div>

<table class="w-full border-separate border-spacing-0 sm:table-fixed">
    <caption class="sr-only">{{ __('Compare Cloud Pro, Cloud Scale and Enterprise') }}</caption>
    <colgroup class="hidden sm:table-column-group">
        <col class="sm:w-[31%]">
        @foreach($plans as $plan)
            <col>
        @endforeach
    </colgroup>
    <thead class="hidden sm:table-header-group">
        <tr>
            <th scope="col" class="{{ $headerCell }} type-label px-6 font-normal text-gray-500 md:px-10 dark:text-gray-400">{{ __('Where plans differ') }}</th>
            @foreach(array_values($plans) as $index => $plan)
                <th scope="col" class="{{ $headerCell }} {{ $tint($index) }} px-5 lg:px-6">
                    @if($index === $recommended)
                        <span class="bg-primary absolute inset-x-0 top-0 h-0.5 dark:bg-primary-400" aria-hidden="true"></span>
                    @endif
                    <span class="block text-sm font-medium text-gray-950 dark:text-white">{{ $plan['label'] }}</span>
                    <span class="mt-0.5 block text-sm font-normal text-gray-500 tabular-nums dark:text-gray-400">{{ $plan['price'] }}</span>
                </th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach($rows as [$feature, $description, $values])
            <tr class="border-rail block border-b last:border-b-0 sm:table-row sm:border-b-0">
                <th scope="row" @class([$rowHeader, 'sm:border-rail sm:border-t' => ! $loop->first])>
                    <span class="block text-sm font-medium text-gray-950 dark:text-white">{{ $feature }}</span>
                    @if($description)
                        <span class="mt-1 block text-sm font-normal leading-snug text-gray-500 dark:text-gray-400">{{ $description }}</span>
                    @endif
                </th>
                @foreach(array_values($plans) as $index => $plan)
                    <td data-label="{{ $plan['label'] }}:" @class([$valueCell, $tint($index), 'sm:border-rail sm:border-t' => ! $loop->parent->first])>
                        @if(is_array($values[$index]))
                            {{ $values[$index][0] }}<span class="text-gray-500 sm:hidden dark:text-gray-400" aria-hidden="true">,</span>
                            <span class="text-gray-500 sm:block dark:text-gray-400">{{ $values[$index][1] }}</span>
                        @else
                            {{ $values[$index] }}
                        @endif
                    </td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>

<p class="border-rail border-t px-6 py-5 text-sm text-gray-500 md:px-10 dark:text-gray-400">
    {{ __('AI credits depend on the model and tool calls. REST API and MCP calls never use them.') }}
    <a href="#pricing-faq" class="font-medium text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white">{{ __('How credits work') }}</a>
</p>
