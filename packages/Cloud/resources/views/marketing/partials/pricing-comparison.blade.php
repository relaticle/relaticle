@php
    $plans = [
        'pro' => __('billing.plans.cloud_pro'),
        'scale' => __('billing.plans.cloud_scale'),
        'enterprise' => __('billing.plans.enterprise'),
    ];

    $rows = array_values(array_filter([
        [__(':name, the AI assistant', ['name' => config('chat.assistant_name')]), __(':credits credits each month', ['credits' => $proCredits]), __(':credits credits each month', ['credits' => $scaleCredits]), __('Agreed in your contract')],
        \Laravel\Pennant\Feature::active(\App\Features\EmailIntegration::class)
            ? [__('Email and calendar'), __('Up to :count connected mailboxes', ['count' => $proPlan->connectedMailboxes()]), __('Up to :count connected mailboxes', ['count' => $scalePlan->connectedMailboxes()]), __('Unlimited connected mailboxes')]
            : null,
        [__('cloud::billing.comparison.integrations'), __('REST API and MCP server'), __(':multiplierx REST API and MCP throughput', ['multiplier' => $scalePlan->throughputMultiplier()]), __('Custom integrations and API limits by agreement')],
        [__('Getting started'), __('Self-service setup'), __('Self-service setup'), __('A scoped implementation project')],
        [__('Hosting and updates'), __('Managed by Relaticle'), __('Managed by Relaticle'), __('Agreed deployment and maintenance')],
        [__('cloud::billing.comparison.support'), __('Email support'), __('Priority support'), __('Agreed support with the founding team')],
    ]));

    $headerCell = 'bg-surface border-rail sticky top-16 z-10 border-b px-6 py-4 text-left md:px-8';
    $rowHeader = 'block px-6 pt-5 pb-2 text-left text-sm font-medium text-gray-950 sm:table-cell sm:px-6 sm:py-5 sm:align-top md:px-10 dark:text-white';
    $valueCell = 'border-rail block px-6 py-1 text-sm leading-relaxed text-gray-600 before:mr-1.5 before:font-medium before:text-gray-950 before:content-[attr(data-label)] last:pb-5 sm:table-cell sm:border-l sm:px-6 sm:py-5 lg:px-8 sm:align-top sm:before:content-none dark:text-gray-400 dark:before:text-white';
@endphp

<table class="w-full border-separate border-spacing-0 sm:table-fixed">
    <caption class="sr-only">{{ __('Compare Cloud Pro, Cloud Scale and Enterprise') }}</caption>
    <thead class="hidden sm:table-header-group">
        <tr>
            <th scope="col" class="{{ $headerCell }} type-label text-gray-500 md:px-10 dark:text-gray-400">{{ __('What’s included') }}</th>
            @foreach($plans as $key => $label)
                <th scope="col" class="{{ $headerCell }} border-l text-sm font-medium text-gray-950 dark:text-white">
                    @if($key === 'scale')
                        <span class="bg-primary absolute inset-x-0 top-0 h-0.5 dark:bg-primary-400" aria-hidden="true"></span>
                    @endif
                    {{ $label }}
                </th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        <tr class="border-rail block border-b sm:table-row">
            <th scope="row" class="{{ $rowHeader }} sm:border-rail sm:border-b">{{ __('Users and records') }}</th>
            <td colspan="3" class="{{ $valueCell }} sm:border-b">{{ __('Unlimited on every plan') }}</td>
        </tr>
        @foreach($rows as [$feature, $pro, $scale, $enterprise])
            <tr class="border-rail block border-b last:border-b-0 sm:table-row">
                <th scope="row" @class([$rowHeader, 'sm:border-rail sm:border-b' => ! $loop->last])>{{ $feature }}</th>
                @foreach(array_combine(array_keys($plans), [$pro, $scale, $enterprise]) as $key => $value)
                    <td data-label="{{ $plans[$key] }}:" @class([$valueCell, 'sm:border-b' => ! $loop->parent->last])>{{ $value }}</td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>

<p class="border-rail border-t px-6 py-5 text-sm text-gray-500 md:px-10 dark:text-gray-400">
    {{ __('AI credits depend on the model and tool calls. REST API and MCP calls never use them.') }}
    <a href="#pricing-faq" class="font-medium text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white">{{ __('How credits work') }}</a>
</p>
