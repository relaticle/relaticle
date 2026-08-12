@php
    $baseTitle = __('Help Centre');
    $pageDescription = __('Guides for setting up your workspace, importing records, and using Relaticle day to day.');
@endphp

<x-guest-layout
    :title="$baseTitle . ' - ' . config('app.name')"
    :description="$pageDescription"
    :ogTitle="$baseTitle . ' - ' . config('app.name')"
    :ogDescription="$pageDescription">
    @pushonce('header')
        @vite(['packages/Documentation/resources/js/documentation.js', 'packages/Documentation/resources/css/documentation.css'])
    @endpushonce

    <div class="pt-32 pb-24 md:pt-40 md:pb-32 bg-white dark:bg-black relative">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
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

                <h2 class="sr-only">{{ __('Help categories') }}</h2>
                <div class="border-t border-gray-200/60 dark:border-white/[0.04] divide-y divide-gray-200/60 dark:divide-white/[0.04]">
                    <div class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0 md:divide-x divide-gray-200/60 dark:divide-white/[0.04]">
                        @foreach($categories as $category)
                            <x-documentation::card
                                :title="$category->title"
                                :description="$category->description"
                                :link="route('help.category', ['category' => \Illuminate\Support\Str::after($category->path, '/')])"
                            />
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    @php
        $jsonLd = (new \Relaticle\Documentation\Support\DocsJsonLd)->breadcrumbs([
            ['name' => $baseTitle, 'url' => route('help.index')],
        ]);
    @endphp

    {!! $jsonLd->toScript() !!}
</x-guest-layout>
