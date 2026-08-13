<x-documentation::shell
    :title="$page->title . ' - ' . config('app.name')"
    :description="$page->description"
    :nav="$nav"
    :current-path="$currentPath">
    <x-slot:breadcrumbs>
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('help.index') }}" class="transition-colors hover:text-gray-900 dark:hover:text-white">{{ __('Help Centre') }}</a></li>
            @if($category)
                <li aria-hidden="true" class="text-gray-300 dark:text-gray-600">/</li>
                <li><a href="{{ route('help.category', ['category' => $page->category]) }}" class="transition-colors hover:text-gray-900 dark:hover:text-white">{{ $category->title }}</a></li>
            @endif
            <li aria-hidden="true" class="text-gray-300 dark:text-gray-600">/</li>
            <li aria-current="page" class="text-gray-900 dark:text-white">{{ $page->title }}</li>
        </ol>
    </x-slot:breadcrumbs>

    <x-documentation::article
        :page="$page"
        :body="$body"
        :headings="$headings"
        :previous="$previous"
        :next="$next"
        :related="$related"
        :eyebrow="$category?->title"
        :eyebrow-url="$category ? route('help.category', ['category' => $page->category]) : null" />

    @php
        $breadcrumbTrail = [
            ['name' => __('Help Centre'), 'url' => route('help.index')],
        ];

        if ($category) {
            $breadcrumbTrail[] = ['name' => $category->title, 'url' => route('help.category', ['category' => $page->category])];
        }

        $breadcrumbTrail[] = ['name' => $page->title, 'url' => route('help.show', ['category' => $page->category, 'slug' => $page->slug])];

        $jsonLd = (new \Relaticle\Documentation\Support\DocsJsonLd)->article(
            $page,
            route('help.show', ['category' => $page->category, 'slug' => $page->slug]),
            $breadcrumbTrail,
        );
    @endphp

    {!! $jsonLd->toScript() !!}
</x-documentation::shell>
