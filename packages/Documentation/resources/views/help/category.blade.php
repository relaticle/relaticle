<x-documentation::shell
    :title="$category->title . ' - ' . config('app.name')"
    :description="$category->description"
    :nav="$nav"
    :current-path="$currentPath">
    <x-slot:breadcrumbs>
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('help.index') }}" class="transition-colors hover:text-gray-900 dark:hover:text-white">{{ __('Help Centre') }}</a></li>
            <li aria-hidden="true" class="text-gray-300 dark:text-gray-600">/</li>
            <li aria-current="page" class="text-gray-900 dark:text-white">{{ $category->title }}</li>
        </ol>
    </x-slot:breadcrumbs>

    <div class="max-w-[45rem]">
        <div class="flex items-start gap-4">
            <span class="hidden h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 sm:inline-flex dark:bg-primary-500/10 dark:text-primary-400">
                <x-documentation::doc-icon :topic="$category->path" class="h-5 w-5" />
            </span>
            <div class="min-w-0">
                <h1 class="font-display text-[2rem] font-bold tracking-[-0.02em] text-balance text-gray-950 sm:text-[2.25rem] dark:text-white">
                    {{ $category->title }}
                </h1>
                <p class="mt-3 text-[17px] leading-relaxed text-gray-500 dark:text-gray-400">{{ $category->description }}</p>
            </div>
        </div>

        @if($categoryBody)
            <div class="prose-docs mt-8">{!! $categoryBody !!}</div>
        @endif

        <h2 class="sr-only">{{ __('Articles in :category', ['category' => $category->title]) }}</h2>
        <ul class="mt-10 divide-y divide-gray-200/80 overflow-hidden rounded-xl border border-gray-200/80 dark:divide-white/[0.06] dark:border-white/[0.06]">
            @foreach($pages as $page)
                <li>
                    <a href="{{ \Relaticle\Documentation\Support\DocUrl::page($page) }}"
                       class="group flex items-center gap-4 px-5 py-4 transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                        <span class="min-w-0 flex-1">
                            <span class="font-display block text-[15px] font-semibold tracking-tight text-gray-900 transition-colors group-hover:text-primary-600 dark:text-white dark:group-hover:text-primary-400">
                                {{ $page->title }}
                            </span>
                            <span class="mt-0.5 block text-[13px] leading-relaxed text-gray-500 dark:text-gray-400">{{ $page->description }}</span>
                        </span>
                        <x-ri-arrow-right-line class="h-4 w-4 shrink-0 text-gray-300 transition-all group-hover:translate-x-0.5 group-hover:text-primary-500 dark:text-gray-600" />
                    </a>
                </li>
            @endforeach
        </ul>
    </div>

    @php
        $jsonLd = (new \Relaticle\Documentation\Support\DocsJsonLd)->breadcrumbs([
            ['name' => __('Help Centre'), 'url' => route('help.index')],
            ['name' => $category->title, 'url' => \Relaticle\Documentation\Support\DocUrl::category($category)],
        ]);
    @endphp

    {!! $jsonLd->toScript() !!}
</x-documentation::shell>
