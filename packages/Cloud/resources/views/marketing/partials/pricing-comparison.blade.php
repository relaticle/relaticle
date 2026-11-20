@use(Illuminate\Support\Number)
@use(App\Enums\Plan)
@php
    $plans = [
        'self' => ['label' => __('Self-hosted'), 'price' => __('Free')],
        'pro' => ['label' => __('billing.plans.cloud_pro'), 'price' => __(':price/mo', ['price' => '$'.Plan::Pro->yearlyPricePerMonth()])],
        'scale' => ['label' => __('billing.plans.cloud_scale'), 'price' => __(':price/mo', ['price' => '$'.Plan::Scale->yearlyPricePerMonth()])],
        'enterprise' => ['label' => __('billing.plans.enterprise'), 'price' => __('From :price/yr', ['price' => '$'.Number::abbreviate(config('relaticle.enterprise.starting_price_yearly'), maxPrecision: 1)])],
    ];

    $credits = fn (Plan $plan): string => __(':credits credits a month', ['credits' => Number::format($plan->credits())]);
    $agreed = __('cloud::billing.allowance.agreed');
    $requestLimit = fn (Plan $plan): string => __(':multiplierx the request limit', ['multiplier' => $plan->throughputMultiplier()]);
    $emailActive = \Laravel\Pennant\Feature::active(\App\Features\EmailIntegration::class);

    $groups = array_filter([
        __('Workspace') => [
            [__('Users and records'), __('Everyone who signs in, and every company, person and opportunity'), [__('Unlimited'), __('Unlimited'), __('Unlimited'), __('Unlimited')]],
            [__('Where it runs'), null, [__('Your own server'), __('Relaticle runs it'), __('Relaticle runs it'), __('Agreed deployment and maintenance')]],
        ],
        __('AI') => [
            [__(':name, the AI assistant', ['name' => config('chat.assistant_name')]), __('Each reply uses credits, depending on the model and the work it does'), [__('Not metered, with your own AI provider key'), $credits(Plan::Pro), $credits(Plan::Scale), $agreed]],
            [__('AI models'), null, [__('Any provider key or local model'), __('Every offered model'), __('Every offered model'), __('Every offered model')]],
        ],
        __('Email and calendar') => $emailActive ? [
            [__('Connected mailboxes'), __('Gmail and Outlook accounts that sync email and meetings'), [__('Unlimited, with your own Google or Microsoft app'), __('Up to :count', ['count' => Plan::Pro->connectedMailboxes()]), __('Up to :count', ['count' => Plan::Scale->connectedMailboxes()]), $agreed]],
        ] : [],
        __('cloud::billing.comparison.integrations') => [
            [__('REST API and MCP server'), __('Connect Claude, ChatGPT and your own tools'), [true, true, $requestLimit(Plan::Scale), $agreed]],
        ],
        __('Setup and support') => [
            [__('Setup'), null, [__('You install it with Docker Compose'), __('You set it up yourself'), __('You set it up yourself'), __('A scoped implementation project')]],
            [__('cloud::billing.comparison.support'), null, [__('Community on Discord'), __('Email'), __('Priority email'), __('Agreed support with the founding team')]],
        ],
    ]);

    $sharedByEveryPlan = fn (array $values): bool => array_filter($values, fn (string|bool $value): bool => $value !== $values[0]) === [];

    $headerCell = 'bg-surface border-rail sticky top-(--header-height) z-10 border-b py-4 text-left align-bottom';
    $rowHeader = 'block px-6 pt-5 pb-2 text-left align-top sm:table-cell sm:py-4 md:px-10';
    $valueCell = 'block px-6 py-1 text-sm leading-relaxed text-gray-700 before:mr-1.5 before:font-medium before:text-gray-950 before:content-[attr(data-label)] last:pb-5 sm:table-cell sm:px-5 sm:py-4 sm:align-top sm:before:content-none lg:px-6 dark:text-gray-300 dark:before:text-white';
@endphp

<table class="w-full border-separate border-spacing-0 sm:table-fixed">
    <caption class="sr-only">{{ __('Compare self-hosting, Cloud Pro, Cloud Scale and Enterprise') }}</caption>
    <colgroup class="hidden sm:table-column-group">
        <col class="sm:w-[28%]">
        @foreach($plans as $plan)
            <col>
        @endforeach
    </colgroup>
    <thead class="hidden sm:table-header-group">
        <tr>
            <th scope="col" class="{{ $headerCell }} type-label px-6 text-gray-500 md:px-10 dark:text-gray-400">{{ __('What’s included') }}</th>
            @foreach($plans as $key => $plan)
                <th scope="col" class="{{ $headerCell }} px-5 lg:px-6">
                    @if($key === 'scale')
                        <span class="bg-primary absolute inset-x-5 top-0 h-0.5 lg:inset-x-6 dark:bg-primary-400" aria-hidden="true"></span>
                    @endif
                    <span class="block text-sm font-medium text-gray-950 dark:text-white">{{ $plan['label'] }}</span>
                    <span class="mt-0.5 block text-sm font-normal text-gray-500 tabular-nums dark:text-gray-400">{{ $plan['price'] }}</span>
                </th>
            @endforeach
        </tr>
    </thead>
    @foreach($groups as $group => $rows)
        <tbody>
            <tr class="block sm:table-row">
                <th scope="colgroup" colspan="5" @class(['type-label block px-6 pt-8 pb-2 text-left text-gray-500 sm:table-cell md:px-10 dark:text-gray-400', 'border-rail border-t' => ! $loop->first])>{{ $group }}</th>
            </tr>
            @foreach($rows as [$feature, $description, $values])
                <tr class="border-rail block border-b last:border-b-0 sm:table-row sm:border-b-0">
                    <th scope="row" class="{{ $rowHeader }}">
                        <span class="block text-sm font-medium text-gray-950 dark:text-white">{{ $feature }}</span>
                        @if($description)
                            <span class="mt-1 block text-sm font-normal leading-snug text-gray-500 dark:text-gray-400">{{ $description }}</span>
                        @endif
                    </th>
                    @if($sharedByEveryPlan($values))
                        <td colspan="{{ count($plans) }}" class="{{ $valueCell }}">{{ __(':value on every plan', ['value' => $values[0]]) }}</td>
                    @else
                        @foreach(array_values($plans) as $index => $plan)
                            <td data-label="{{ $plan['label'] }}:" class="{{ $valueCell }}">
                                @if($values[$index] === true)
                                    <x-lucide-check class="inline size-4 align-[-3px] text-gray-950 dark:text-white" aria-hidden="true" />
                                    <span class="sr-only">{{ __('Included') }}</span>
                                @else
                                    {{ $values[$index] }}
                                @endif
                            </td>
                        @endforeach
                    @endif
                </tr>
            @endforeach
            <tr class="hidden sm:table-row" aria-hidden="true"><td colspan="5" class="h-4"></td></tr>
        </tbody>
    @endforeach
</table>

<p class="border-rail border-t px-6 py-5 text-sm text-gray-500 md:px-10 dark:text-gray-400">
    {{ __('AI credits depend on the model and tool calls. REST API and MCP calls never use them.') }}
    <a href="#pricing-faq" class="font-medium text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white">{{ __('How credits work') }}</a>
</p>
