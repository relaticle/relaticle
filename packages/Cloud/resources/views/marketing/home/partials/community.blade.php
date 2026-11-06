@php
    $stats = array_values(array_filter([
        $githubStars > 0 ? [__(':count+', ['count' => $formattedGithubStars]), __('GitHub stars'), 'https://github.com/relaticle/relaticle'] : null,
        $formattedDockerPulls !== null ? [$formattedDockerPulls, __('Docker pulls'), 'https://hub.docker.com/r/manukminasyan/relaticle'] : null,
        $formattedDiscordMembers !== null ? [$formattedDiscordMembers, __('Discord members'), route('discord')] : null,
    ]));

    $links = [
        ['https://github.com/relaticle/relaticle', 'ri-github-fill', 'text-gray-900 dark:text-white', __('GitHub'), __('Star the repo, report issues, and read every line of code.'), true],
        [route('discord'), 'ri-discord-fill', 'text-[#5865F2]', __('Discord'), __('Talk with the team and other builders, and share ideas.'), true],
        [route('selfHosted'), 'ri-server-line', 'text-gray-900 dark:text-white', __('Self-host'), __('Deploy with Docker Compose on your own server. Your CRM database stays there.'), false],
    ];

    if (\Laravel\Pennant\Feature::active(\App\Features\Documentation::class)) {
        $links[] = [route('documentation.index'), 'ri-book-open-line', 'text-primary dark:text-primary-400', __('Developer docs'), __('Self-host with Docker, use the REST API, and connect agents over MCP.'), false];
    }
@endphp

<x-cloud::marketing.frame id="community">
    <x-cloud::marketing.frame-header :label="__('Open source')" :lead="__('Built in the open.')">
        {{ __('Run it on your own server, or let us host it.') }}
    </x-cloud::marketing.frame-header>

    @if($stats !== [])
        <div class="bg-rail border-rail grid grid-cols-1 gap-px border-b sm:grid-cols-[repeat(auto-fit,minmax(0,1fr))]">
            @foreach($stats as [$value, $label, $href])
                <x-cloud::marketing.stat :value="$value" :label="$label" :href="$href"/>
            @endforeach
        </div>
    @endif

    <div class="bg-surface border-rail border-b px-6 py-5 md:px-8">
        <p class="type-prose text-gray-700 dark:text-gray-300">{{ __('All Relaticle code in a self-hosted install is AGPL-3.0. Only the code behind relaticle.com, the hosted business, is proprietary.') }}</p>
    </div>

    <div class="bg-rail grid grid-cols-1 gap-px sm:grid-cols-2 md:grid-cols-[repeat(auto-fit,minmax(0,1fr))]">
        @foreach($links as [$url, $icon, $iconClass, $title, $description, $external])
            <a href="{{ $url }}" @if($external) target="_blank" rel="noopener noreferrer" @endif class="bg-surface hover:bg-tint group flex flex-col p-6 transition-colors md:p-8">
                <div class="mb-5 flex items-center justify-between">
                    <x-dynamic-component :component="$icon" class="h-5 w-5 {{ $iconClass }}"/>
                    <x-ri-arrow-right-up-line class="h-4 w-4 text-gray-300 transition-all group-hover:-translate-y-0.5 group-hover:translate-x-0.5 group-hover:text-gray-500 dark:text-gray-600"/>
                </div>
                <h3 class="text-base font-medium text-gray-950 dark:text-white">{{ $title }}</h3>
                <p class="mt-1.5 text-sm leading-relaxed text-gray-500 dark:text-gray-400">{{ $description }}</p>
            </a>
        @endforeach
    </div>
</x-cloud::marketing.frame>
