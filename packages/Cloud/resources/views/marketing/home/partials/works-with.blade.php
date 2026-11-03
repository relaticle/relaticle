@php
    $assistantName = (string) config('chat.assistant_name');

    $proof = array_values(array_filter([
        $githubStars > 0 ? [__(':count+', ['count' => $formattedGithubStars]), __('GitHub stars')] : null,
        $formattedDockerPulls !== null ? [$formattedDockerPulls, __('Docker pulls')] : null,
        [__('AGPL-3.0'), __('Open source, free to self-host')],
        [__('You approve'), __(':name proposes, you decide', ['name' => $assistantName])],
    ]));

    $clients = [
        ['ri-claude-fill', 'text-[#D4763C]', 'Claude', \Relaticle\Cloud\Marketing\Enums\ConnectableAssistant::Claude],
        ['ri-openai-fill', 'text-gray-900 dark:text-gray-200', 'ChatGPT', \Relaticle\Cloud\Marketing\Enums\ConnectableAssistant::ChatGPT],
        ['ri-cursor-ai-fill', 'text-gray-900 dark:text-gray-200', 'Cursor', null],
        ['ri-gemini-fill', 'text-blue-500', 'Gemini', null],
    ];
@endphp

<section aria-label="{{ __('Works with') }}" class="bg-surface border-rail relative border-y">
    <div class="mx-auto max-w-6xl">
        <div class="divide-rail border-rail grid grid-cols-2 md:grid-cols-[2fr_repeat(4,1fr)_1.5fr] divide-x divide-y md:divide-y-0 sm:border-x">
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

        <dl class="bg-rail border-rail grid grid-cols-2 gap-px border-t sm:border-x md:grid-cols-[repeat(auto-fit,minmax(0,1fr))]">
            @foreach($proof as [$value, $label])
                <div class="bg-surface flex flex-col px-5 py-5 md:px-6">
                    <dt class="order-2 mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                    <dd class="order-1 text-base font-medium text-gray-950 dark:text-white">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
</section>
