@php
    $baseTitle = $page->title;
@endphp

<x-documentation::shell
    :title="$baseTitle . ' - ' . config('app.name')"
    :description="$page->description">
    <nav class="mb-8 text-sm text-gray-500 dark:text-gray-400" aria-label="{{ __('Breadcrumb') }}">
        <a href="{{ route('documentation.index') }}" class="hover:text-primary-600 dark:hover:text-primary-400 transition-colors">{{ __('Documentation') }}</a>
        <span class="mx-2">/</span>
        <span class="text-gray-900 dark:text-white">{{ $page->title }}</span>
    </nav>

    <div class="grid grid-cols-12 gap-6 lg:gap-8 min-h-screen">
        <nav class="hidden sm:block col-span-12 sm:col-span-3 lg:col-span-2 relative" aria-label="{{ __('Documentation sections') }}">
            <div class="sticky top-24 pt-0.5 max-h-[calc(100vh-6rem)] overflow-y-auto pr-4 pb-16">
                <h2 class="text-sm font-semibold text-black dark:text-white mb-4 flex items-center space-x-2">
                    <x-heroicon-o-book-open class="h-4 w-4 text-primary dark:text-primary-400" />
                    <span>{{ __('Documentation') }}</span>
                </h2>
                <div class="flex flex-col space-y-1 border-l border-gray-200 dark:border-gray-800">
                    @foreach($pages as $navPage)
                        <a href="{{ route('documentation.show', ['type' => $navPage->slug]) }}"
                           class="pl-4 py-2 text-sm rounded-r-md flex items-center gap-2 transition-all
                                      {{ $navPage->path === $page->path
                                        ? 'border-l-2 border-primary border-l-primary-500 -ml-[1px] pl-[17px] dark:border-l-primary-400 bg-primary-50/50 dark:bg-primary-900/10 text-primary-600 dark:text-primary-400 font-medium'
                                        : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800/50 hover:border-l hover:border-l-gray-300 dark:hover:border-l-gray-700 hover:-ml-[1px] hover:pl-[17px]' }}">
                            <span>{{ $navPage->title }}</span>
                        </a>
                    @endforeach
                    <a href="{{ $apiReference['url'] }}"
                       class="pl-4 py-2 text-sm rounded-r-md flex items-center gap-2 transition-all text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800/50 hover:border-l hover:border-l-gray-300 dark:hover:border-l-gray-700 hover:-ml-[1px] hover:pl-[17px]">
                        <span>{{ $apiReference['title'] }}</span>
                    </a>
                </div>
            </div>
        </nav>

        <div class="col-span-12 sm:col-span-9 lg:col-span-10 px-4 max-w-3xl">
            <h1 class="font-display text-3xl sm:text-4xl font-bold text-gray-950 dark:text-white leading-[1.1] tracking-[-0.02em] mb-2">
                {{ $page->title }}
            </h1>

            @if($page->updated)
                <p class="text-sm text-gray-400 dark:text-gray-500 mb-8">
                    {{ __('Updated :date', ['date' => $page->updated->isoFormat('MMMM D, YYYY')]) }}
                </p>
            @endif

            <div id="documentation-content"
                 class="prose prose-sm sm:prose-base lg:prose-lg dark:prose-invert max-w-none {{ $page->updated ? '' : 'mt-8' }}">
                {!! $body !!}
            </div>

            <div class="mt-10 pt-6 border-t border-gray-200 dark:border-gray-800">
                <a href="https://github.com/Relaticle/relaticle/edit/main/packages/Documentation/resources/content/{{ $page->path }}.md"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="inline-flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 transition-colors">
                    <x-ri-github-fill class="w-4 h-4"/>
                    {{ __('Edit this page on GitHub') }}
                </a>
            </div>
        </div>
    </div>

    @php
        $breadcrumbTrail = [
            ['name' => __('Documentation'), 'url' => route('documentation.index')],
            ['name' => $page->title, 'url' => route('documentation.show', ['type' => $page->slug])],
        ];

        $jsonLd = (new \Relaticle\Documentation\Support\DocsJsonLd)->article(
            $page,
            route('documentation.show', ['type' => $page->slug]),
            $breadcrumbTrail,
        );
    @endphp

    {!! $jsonLd->toScript() !!}
</x-documentation::shell>
