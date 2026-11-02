@php
    $links = [
        ['https://github.com/relaticle/relaticle', 'ri-github-fill', 'text-gray-900 dark:text-white', __('GitHub'), __('Star the repo, report issues, and read every line of code.'), true],
        [route('discord'), 'ri-discord-fill', 'text-[#5865F2]', __('Discord'), __('Talk with the team and other builders, and share ideas.'), true],
    ];

    if (\Laravel\Pennant\Feature::active(\App\Features\Documentation::class)) {
        $links[] = [route('documentation.index'), 'ri-book-open-line', 'text-primary dark:text-primary-400', __('Developer docs'), __('Self-host with Docker, use the REST API, and connect agents over MCP.'), false];
    }

    $facts = [
        [__('Open source'), __('Read and change the code')],
        [__('Unlimited users'), __('No per-seat pricing')],
        [__('Your server or ours'), __('Self-host or use Cloud')],
        [__('Your data'), __('Export it any time')],
    ];
@endphp

<x-cloud::marketing.frame id="community">
    <x-cloud::marketing.frame-header :label="__('Open source')" :lead="__('Built in the open.')">
        <a href="{{ route('selfHosted') }}" class="underline decoration-gray-300 decoration-1 underline-offset-[0.15em] transition-colors hover:text-gray-950 dark:decoration-gray-600 dark:hover:text-white">{{ __('Run it on your own server') }}</a>{{ __(', or let us host it.') }}
    </x-cloud::marketing.frame-header>

    <dl class="border-rail bg-rail grid grid-cols-2 gap-px border-b lg:grid-cols-4">
        @foreach($facts as [$value, $label])
            <div class="bg-surface flex flex-col px-6 py-6 md:px-8">
                <dt class="order-2 mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                <dd class="order-1 text-base font-medium text-gray-950 dark:text-white">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="bg-rail grid grid-cols-1 gap-px md:grid-cols-3">
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
