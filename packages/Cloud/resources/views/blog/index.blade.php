@php
    $title = match (true) {
        isset($category) => __(':name: CRM Blog', ['name' => $category->name]),
        isset($tag) => __(':name: CRM Blog Posts', ['name' => $tag->name]),
        default => __('CRM Guides, Comparisons and Engineering'),
    }.' - '.config('app.name');
    $description = match (true) {
        isset($category) => __('Read Relaticle articles in :name on self-hosting, MCP and the code behind an open-source CRM.', ['name' => $category->name]),
        isset($tag) => __('Read Relaticle posts tagged :name about using an open-source CRM, from setup to working with records.', ['name' => $tag->name]),
        default => __('Read Relaticle guides, comparisons and engineering posts on self-hosting, MCP, custom attributes and moving CRM records.'),
    };

    // Listings paginate, so page 2+ must self-canonicalise or a post reachable only
    // from there has no canonical page pointing at it. Only `page` is carried over,
    // so search and tracking params stay out. Search results are noindex,follow: they
    // are an infinite, low-value URL space that should not be indexed.
    $searchQuery = request()->query('q');
    $currentPage = (int) request()->query('page', 1);
    $canonical = $currentPage > 1
        ? url()->current().'?page='.$currentPage
        : url()->current();
    $linkClass = 'font-medium text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white';
@endphp

<x-cloud::guest-layout
    :title="$title"
    :description="$description"
    :ogTitle="$title"
    :canonical="$canonical"
    :robots="$searchQuery ? 'noindex,follow' : null">
    @push('header')
        <x-ink::feed-link />
    @endpush

    <x-cloud::marketing.hero align="start">
        <x-slot:eyebrow>
            <x-cloud::marketing.eyebrow>{{ __('Blog') }}</x-cloud::marketing.eyebrow>
        </x-slot:eyebrow>

        <x-slot:title>
            {{ __('Engineering Blog.') }}
            <span class="text-gray-500 dark:text-gray-400">{{ __('How we build an open-source CRM.') }}</span>
        </x-slot:title>

        <x-slot:aside>
            <p class="text-base leading-relaxed text-gray-500 md:text-lg dark:text-gray-400">
                @if(isset($category))
                    {{ __('Posts in') }} <span class="font-medium text-gray-950 dark:text-white">{{ $category->name }}</span>
                    &middot; <a href="{{ route('blog.index') }}" class="{{ $linkClass }}">{{ __('All posts') }}</a>
                @elseif(isset($tag))
                    {{ __('Posts tagged') }} <span class="font-medium text-gray-950 dark:text-white">#{{ $tag->name }}</span>
                    &middot; <a href="{{ route('blog.index') }}" class="{{ $linkClass }}">{{ __('All posts') }}</a>
                @else
                    {{ __('Deep dives into self-hosting, MCP, custom attributes and the code behind Relaticle.') }}
                @endif
            </p>
        </x-slot:aside>
    </x-cloud::marketing.hero>

    <x-cloud::marketing.frame>
        @if($posts->isEmpty())
            <div class="flex flex-col items-start gap-4 px-6 py-12 md:px-10 md:py-16">
                @if($posts->total() > 0)
                    <p class="type-prose text-gray-700 dark:text-gray-200">{{ __("That page doesn't exist. The archive only goes up to page :page.", ['page' => $posts->lastPage()]) }}</p>
                    <a href="{{ $posts->url(1) }}" class="{{ $linkClass }}">{{ __('Back to the first page') }}</a>
                @elseif($searchQuery || isset($category) || isset($tag))
                    {{-- total() counts the filtered set, so "no posts yet" would be a
                         lie here: the archive has posts, this filter just matched none. --}}
                    <p class="type-prose text-gray-700 dark:text-gray-200">{{ __('Nothing matched. Try another search or browse everything.') }}</p>
                    <a href="{{ route('blog.index') }}" class="{{ $linkClass }}">{{ __('All posts') }}</a>
                @else
                    <p class="type-prose text-gray-700 dark:text-gray-200">{{ __('No posts yet. Check back soon.') }}</p>
                @endif
            </div>
        @else
            <ul class="bg-rail grid gap-px">
                @foreach($posts as $post)
                    <x-cloud::blog.post-card :post="$post" />
                @endforeach
            </ul>

            @if($posts->hasPages())
                <div class="border-rail border-t px-6 py-5 md:px-10">
                    {{ $posts->links('cloud::blog.pagination') }}
                </div>
            @endif
        @endif
    </x-cloud::marketing.frame>
</x-cloud::guest-layout>
