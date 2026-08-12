@php
    $baseTitle = $category->title;
@endphp

<x-guest-layout
    :title="$baseTitle . ' - ' . config('app.name')"
    :description="$category->description"
    :ogTitle="$baseTitle . ' - ' . config('app.name')"
    :ogDescription="$category->description">
    @pushonce('header')
        @vite(['packages/Documentation/resources/js/documentation.js', 'packages/Documentation/resources/css/documentation.css'])
    @endpushonce

    <div class="pt-32 pb-24 md:pt-40 md:pb-32 bg-white dark:bg-black relative">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <nav class="mb-8 text-sm text-gray-500 dark:text-gray-400" aria-label="{{ __('Breadcrumb') }}">
                <a href="{{ route('help.index') }}" class="hover:text-primary-600 dark:hover:text-primary-400 transition-colors">{{ __('Help Centre') }}</a>
                <span class="mx-2">/</span>
                <span class="text-gray-900 dark:text-white">{{ $category->title }}</span>
            </nav>

            <div class="grid grid-cols-12 gap-6 lg:gap-8 min-h-screen">
                <nav class="hidden sm:block col-span-12 sm:col-span-3 lg:col-span-2 relative" aria-label="{{ __('Help categories') }}">
                    <div class="sticky top-24 pt-0.5 max-h-[calc(100vh-6rem)] overflow-y-auto pr-4 pb-16">
                        <h2 class="text-sm font-semibold text-black dark:text-white mb-4 flex items-center space-x-2">
                            <x-heroicon-o-book-open class="h-4 w-4 text-primary dark:text-primary-400" />
                            <span>{{ __('Help Centre') }}</span>
                        </h2>
                        <div class="flex flex-col space-y-1 border-l border-gray-200 dark:border-gray-800">
                            @foreach($categories as $navCategory)
                                <a href="{{ route('help.category', ['category' => \Illuminate\Support\Str::after($navCategory->path, '/')]) }}"
                                   class="pl-4 py-2 text-sm rounded-r-md flex items-center gap-2 transition-all
                                              {{ $navCategory->path === $category->path
                                                ? 'border-l-2 border-primary border-l-primary-500 -ml-[1px] pl-[17px] dark:border-l-primary-400 bg-primary-50/50 dark:bg-primary-900/10 text-primary-600 dark:text-primary-400 font-medium'
                                                : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800/50 hover:border-l hover:border-l-gray-300 dark:hover:border-l-gray-700 hover:-ml-[1px] hover:pl-[17px]' }}">
                                    <span>{{ $navCategory->title }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </nav>

                <div class="col-span-12 sm:col-span-9 lg:col-span-10 px-4">
                    <h1 class="font-display text-3xl sm:text-4xl font-bold text-gray-950 dark:text-white leading-[1.1] tracking-[-0.02em] mb-4">
                        {{ $category->title }}
                    </h1>
                    <p class="text-lg text-gray-500 dark:text-gray-400 max-w-2xl leading-relaxed mb-10">
                        {{ $category->description }}
                    </p>

                    @if($categoryBody)
                        <div class="prose prose-sm sm:prose-base dark:prose-invert max-w-none mb-10">
                            {!! $categoryBody !!}
                        </div>
                    @endif

                    <h2 class="sr-only">{{ __('Articles in :category', ['category' => $category->title]) }}</h2>
                    <div class="border-t border-gray-200/60 dark:border-white/[0.04] divide-y divide-gray-200/60 dark:divide-white/[0.04]">
                        <div class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0 md:divide-x divide-gray-200/60 dark:divide-white/[0.04]">
                            @foreach($pages as $page)
                                <x-documentation::card
                                    :title="$page->title"
                                    :description="$page->description"
                                    :link="route('help.show', ['category' => $page->category, 'slug' => $page->slug])"
                                />
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-guest-layout>
