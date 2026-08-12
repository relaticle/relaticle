@php
    $baseTitle = __('Documentation');
    $pageDescription = __('Guides and resources to help you get the most out of Relaticle CRM.');

    $documentIcons = [
        'getting-started' => 'ri-rocket-2-line',
        'import' => 'ri-upload-2-line',
        'developer' => 'ri-code-s-slash-line',
        'self-hosting' => 'ri-server-line',
        'mcp' => 'ri-cpu-line',
    ];

    $cards = $pages->map(fn ($page): array => [
        'title' => $page->title,
        'description' => $page->description,
        'link' => route('documentation.show', ['type' => $page->slug]),
        'icon' => $documentIcons[$page->slug] ?? null,
    ])->push([
        'title' => $apiReference['title'],
        'description' => $apiReference['description'],
        'link' => $apiReference['url'],
        'icon' => 'ri-terminal-box-line',
    ]);
@endphp

<x-documentation::shell
    :title="$baseTitle . ' - ' . config('app.name')"
    :description="$pageDescription">
    <div class="absolute inset-0 bg-[linear-gradient(to_right,rgba(0,0,0,0.015)_1px,transparent_1px),linear-gradient(to_bottom,rgba(0,0,0,0.015)_1px,transparent_1px)] dark:bg-[linear-gradient(to_right,rgba(255,255,255,0.025)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.025)_1px,transparent_1px)] bg-[size:3rem_3rem] [mask-image:radial-gradient(ellipse_70%_50%_at_50%_50%,black_30%,transparent_100%)] pointer-events-none"></div>

    <div class="max-w-4xl mx-auto relative">
        <div class="text-center space-y-5 max-w-3xl mx-auto mb-12">
            <h1 class="font-display text-4xl sm:text-5xl font-bold text-gray-950 dark:text-white leading-[1.1] tracking-[-0.02em]">
                {{ $baseTitle }}
            </h1>
            <p class="text-lg text-gray-500 dark:text-gray-400 max-w-2xl mx-auto leading-relaxed">
                {{ $pageDescription }}
            </p>
        </div>

        <h2 class="sr-only">{{ __('Available Guides') }}</h2>
        <div class="border-t border-gray-200/60 dark:border-white/[0.04] divide-y divide-gray-200/60 dark:divide-white/[0.04]">
            <div class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0 md:divide-x divide-gray-200/60 dark:divide-white/[0.04]">
                @foreach($cards as $card)
                    @if($loop->index % 2 === 0 && ! $loop->first)
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0 md:divide-x divide-gray-200/60 dark:divide-white/[0.04]">
                    @endif
                    <x-documentation::card
                        :title="$card['title']"
                        :description="$card['description']"
                        :link="$card['link']"
                        :icon="$card['icon']"
                    />
                @endforeach
            </div>
        </div>
    </div>

    @php
        $jsonLd = (new \Relaticle\Documentation\Support\DocsJsonLd)->breadcrumbs([
            ['name' => $baseTitle, 'url' => route('documentation.index')],
        ]);
    @endphp

    {!! $jsonLd->toScript() !!}
</x-documentation::shell>
