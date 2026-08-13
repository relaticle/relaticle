<x-documentation::shell
    :title="$page->title . ' - ' . config('app.name')"
    :description="$page->description"
    :nav="$nav"
    :current-path="$currentPath">
    <x-slot:breadcrumbs>
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('help.index') }}" class="transition-colors hover:text-gray-900 dark:hover:text-white">{{ __('Docs') }}</a></li>
            <li aria-hidden="true" class="text-gray-300 dark:text-gray-600">/</li>
            <li><a href="{{ route('documentation.index') }}" class="transition-colors hover:text-gray-900 dark:hover:text-white">{{ __('Developers') }}</a></li>
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
        :eyebrow="__('Developer guides')"
        :eyebrow-url="route('documentation.index')" />

    @php
        $jsonLd = (new \Relaticle\Documentation\Support\DocsJsonLd)->article(
            $page,
            route('documentation.show', ['type' => $page->slug]),
            [
                ['name' => __('Documentation'), 'url' => route('documentation.index')],
                ['name' => $page->title, 'url' => route('documentation.show', ['type' => $page->slug])],
            ],
        );
    @endphp

    {!! $jsonLd->toScript() !!}
</x-documentation::shell>
