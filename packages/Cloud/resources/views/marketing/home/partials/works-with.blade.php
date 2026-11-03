@php
    $assistantName = (string) config('chat.assistant_name');

    $answers = [
        [
            __('You approve every change'),
            __(':name proposes a create, update or delete as a card. Nothing is saved until you approve it.', ['name' => $assistantName]),
            __('How approval works'),
            route('help.show', ['category' => 'ai-assistant', 'slug' => 'approve-what-the-assistant-changes']),
        ],
        [
            __('Your data is yours'),
            __('Export every record type to CSV or Excel, or run Relaticle on your own server under the AGPL-3.0.'),
            __('Self-host Relaticle'),
            route('selfHosted'),
        ],
        [
            __('$:price a month, unlimited users', ['price' => \App\Enums\Plan::Pro->yearlyPricePerMonth()]),
            __('One price per workspace, billed yearly, with unlimited users and records. Every workspace starts on a :days-day trial with no card.', ['days' => \App\Models\Workspace::PRO_TRIAL_DAYS]),
            __('See pricing'),
            route('pricing'),
        ],
    ];

    $clients = [
        ['ri-claude-fill', 'text-[#D4763C]', 'Claude', \Relaticle\Cloud\Marketing\Enums\ConnectableAssistant::Claude],
        ['ri-openai-fill', 'text-gray-900 dark:text-gray-200', 'ChatGPT', \Relaticle\Cloud\Marketing\Enums\ConnectableAssistant::ChatGPT],
        ['ri-cursor-ai-fill', 'text-gray-900 dark:text-gray-200', 'Cursor', null],
        ['ri-gemini-fill', 'text-blue-500', 'Gemini', null],
    ];
@endphp

<section aria-label="{{ __('Works with') }}" class="bg-surface border-rail relative border-y">
    <div class="mx-auto max-w-6xl">
        <div class="divide-rail border-rail grid grid-cols-2 md:grid-cols-[2.4fr_repeat(4,1fr)_1.4fr] divide-x divide-y md:divide-y-0 sm:border-x">
            <div class="col-span-2 md:col-span-1 flex items-center px-5 py-4 md:py-6">
                <x-cloud::marketing.eyebrow>{{ __('Works with the AI you already use') }}</x-cloud::marketing.eyebrow>
            </div>

            @foreach($clients as [$icon, $color, $name, $assistant])
                @if($assistant)
                    <a href="{{ route($assistant->routeName()) }}" class="flex items-center justify-center gap-2.5 px-4 py-5 md:py-6 hover:bg-tint transition-colors">
                        <x-dynamic-component :component="$icon" class="w-5 h-5 shrink-0 {{ $color }}"/>
                        <span class="text-sm font-semibold tracking-tight text-gray-800 dark:text-gray-200">{{ $name }}</span>
                    </a>
                @else
                    <div class="flex items-center justify-center gap-2.5 px-4 py-5 md:py-6">
                        <x-dynamic-component :component="$icon" class="w-5 h-5 shrink-0 {{ $color }}"/>
                        <span class="text-sm font-semibold tracking-tight text-gray-800 dark:text-gray-200">{{ $name }}</span>
                    </div>
                @endif
            @endforeach

            @if(\Laravel\Pennant\Feature::active(\App\Features\Documentation::class))
                <a href="{{ route('documentation.index') }}" class="group col-span-2 md:col-span-1 flex items-center justify-center gap-1.5 px-3 py-5 md:py-6 whitespace-nowrap text-sm font-medium text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white transition-colors">
                    {{ __('+ any MCP client') }}
                    <x-ri-arrow-right-up-line class="w-3.5 h-3.5 text-gray-400 dark:text-gray-500 group-hover:text-current transition-colors"/>
                </a>
            @else
                <div class="col-span-2 md:col-span-1 flex items-center justify-center px-3 py-5 md:py-6 whitespace-nowrap text-sm font-medium text-gray-500 dark:text-gray-400">
                    {{ __('+ any MCP client') }}
                </div>
            @endif
        </div>

        <div class="bg-rail border-rail grid grid-cols-1 gap-px border-t sm:border-x md:grid-cols-3">
            @foreach($answers as [$title, $sentence, $linkLabel, $linkHref])
                <div class="bg-surface flex flex-col p-6 md:px-8">
                    <p class="text-base font-medium text-gray-950 dark:text-white">{{ $title }}</p>
                    <p class="type-prose mt-1.5 text-gray-500 dark:text-gray-400">{{ $sentence }}</p>
                    <a href="{{ $linkHref }}" class="mt-auto inline-flex w-fit items-center gap-1 pt-4 text-sm font-medium text-gray-950 hover:text-primary dark:text-white dark:hover:text-primary-400">
                        {{ $linkLabel }}
                        <x-ri-arrow-right-line class="h-3.5 w-3.5"/>
                    </a>
                </div>
            @endforeach
        </div>
    </div>
</section>
