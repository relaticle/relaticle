@php
    $included = [
        __('Unlimited users and data'),
        __('MCP server for AI agents'),
        __('REST API with full CRUD'),
        __('Custom fields'),
        __('Multiple workspaces'),
    ];

    $options = [
        [
            'name' => __('Cloud'),
            'tagline' => __('Managed hosting with automatic updates and backups'),
            'price' => '$0',
            'unit' => __('/ month'),
            'note' => __('Generous free tier. Always.'),
            'benefitsLabel' => __('Cloud benefits'),
            'benefits' => [__('Zero-downtime updates'), __('Automatic daily backups'), __('No server maintenance'), __('Email support')],
        ],
        [
            'name' => __('Self-Hosted'),
            'tagline' => __('Your server, your data, your rules'),
            'price' => __('Free'),
            'unit' => __('forever'),
            'note' => __('AGPL-3.0 open source'),
            'benefitsLabel' => __('Self-hosted benefits'),
            'benefits' => [__('Full source code access'), __('Docker Compose deployment'), __('Data never leaves your server'), __('Community support (Discord)')],
        ],
    ];
@endphp

<div class="border-rail bg-rail grid gap-px border-t md:grid-cols-2">
    @foreach($options as $index => $option)
        <section class="bg-surface relative flex flex-col">
            @if($index === 0)
                <span class="bg-primary absolute inset-x-0 top-0 h-0.5 dark:bg-primary-400" aria-hidden="true"></span>
            @endif

            <div class="p-6 md:p-10">
                <h2 class="text-lg font-medium text-gray-950 dark:text-white">{{ $option['name'] }}</h2>
                <p class="mt-1.5 text-base text-gray-500 dark:text-gray-400">{{ $option['tagline'] }}</p>

                <p class="mt-8 flex items-baseline gap-1.5">
                    <span class="text-5xl font-semibold tracking-[-0.03em] text-gray-950 dark:text-white">{{ $option['price'] }}</span>
                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ $option['unit'] }}</span>
                </p>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $option['note'] }}</p>

                @if($index === 0)
                    <x-cloud::marketing.button :href="route('login')" class="mt-8 w-full" data-analytics-intent="signup" data-analytics-placement="pricing">
                        {{ __('Start for free') }}
                    </x-cloud::marketing.button>
                @else
                    <x-cloud::marketing.button variant="secondary" href="https://github.com/relaticle/relaticle" icon="ri-github-fill" external class="mt-8 w-full">
                        {{ __('View on GitHub') }}
                    </x-cloud::marketing.button>
                @endif
            </div>

            <div class="border-rail flex flex-1 flex-col gap-6 border-t p-6 md:px-10">
                @foreach([[__('Everything included'), $included], [$option['benefitsLabel'], $option['benefits']]] as [$label, $features])
                    <div>
                        <p class="type-label mb-5 text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <ul class="space-y-3 text-sm leading-5 text-gray-700 dark:text-gray-300">
                            @foreach($features as $feature)
                                <li class="flex items-start gap-3">
                                    <x-ri-check-line class="mt-0.5 size-4 shrink-0 text-gray-950 dark:text-white" />
                                    {{ $feature }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach
</div>
