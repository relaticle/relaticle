@php
    $headingContext = $category->name ?? $tag->name ?? null;
    $title = $headingContext
        ? $headingContext.' - '.config('app.name').' Blog'
        : config('app.name').' - Engineering Blog';
    $description = match (true) {
        isset($category) => 'Posts about '.$category->name.' from the Relaticle engineering team.',
        isset($tag) => 'Posts tagged "'.$tag->name.'" from the Relaticle engineering team.',
        default => 'Engineering blog from the Relaticle team. Deep dives into building an open-source CRM with MCP, AI agents, and modern Laravel.',
    };

    // Listings paginate, so page 2+ must self-canonicalise or a post reachable only
    // from there has no canonical page pointing at it. Only `page` is carried over —
    // search and tracking params stay out. Search results are noindex,follow: they
    // are an infinite, low-value URL space that should not be indexed.
    // Trim-and-compare rather than a truthiness test: "0" is a real search term that
    // ink's controller filters on, but PHP reads it as falsy — which left /blog?q=0
    // an indexable results page and mislabelled its empty state.
    $searchQuery = trim((string) request()->query('q', ''));
    $currentPage = (int) request()->query('page', 1);
    $canonical = $currentPage > 1
        ? url()->current().'?page='.$currentPage
        : url()->current();

    // A page past the end still answers 200 with a "that page doesn't exist" body —
    // a soft 404. Keep those out of the index rather than letting a crawler bank an
    // unbounded set of empty URLs.
    $isBeyondLastPage = $posts->isEmpty() && $posts->total() > 0;
    $robots = ($searchQuery !== '' || $isBeyondLastPage) ? 'noindex,follow' : null;
@endphp

<x-guest-layout
    :title="$title"
    :description="$description"
    :ogTitle="$title"
    :canonical="$canonical"
    :robots="$robots">
    @push('header')
        <x-ink::feed-link />
    @endpush

    <div class="pt-32 pb-24 md:pt-40 md:pb-32 bg-white dark:bg-black">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center space-y-5 mb-12">
                <h1 class="font-display text-4xl sm:text-5xl font-bold text-gray-950 dark:text-white leading-[1.1] tracking-[-0.02em]">
                    Engineering Blog
                </h1>

                @if(isset($category))
                    <p class="text-lg text-gray-500 dark:text-gray-400 leading-relaxed">
                        Posts in <span class="font-medium text-gray-900 dark:text-white">{{ $category->name }}</span>
                        &middot; <a href="{{ route('blog.index') }}" class="text-primary dark:text-primary-400 hover:underline">All posts</a>
                    </p>
                @elseif(isset($tag))
                    <p class="text-lg text-gray-500 dark:text-gray-400 leading-relaxed">
                        Posts tagged <span class="font-medium text-gray-900 dark:text-white">#{{ $tag->name }}</span>
                        &middot; <a href="{{ route('blog.index') }}" class="text-primary dark:text-primary-400 hover:underline">All posts</a>
                    </p>
                @else
                    <p class="text-lg text-gray-500 dark:text-gray-400 max-w-2xl mx-auto leading-relaxed">
                        Deep dives into building an open-source CRM for AI agents.
                    </p>
                @endif
            </div>

            @if($posts->isEmpty())
                <div class="text-center py-16 space-y-4">
                    @if($posts->total() > 0)
                        <p class="text-gray-500 dark:text-gray-400">That page doesn't exist — the archive only goes up to page {{ $posts->lastPage() }}.</p>
                        <a href="{{ $posts->url(1) }}"
                           class="inline-flex items-center gap-1.5 text-sm font-medium text-primary dark:text-primary-400 hover:underline">
                            <x-ri-arrow-left-line class="w-4 h-4" />
                            Back to the first page
                        </a>
                    @elseif($searchQuery !== '' || isset($category) || isset($tag))
                        {{-- total() counts the filtered set, so "no posts yet" would be a
                             lie here: the archive has posts, this filter just matched none. --}}
                        <p class="text-gray-500 dark:text-gray-400">Nothing matched. Try another search or browse everything.</p>
                        <a href="{{ route('blog.index') }}"
                           class="inline-flex items-center gap-1.5 text-sm font-medium text-primary dark:text-primary-400 hover:underline">
                            <x-ri-arrow-left-line class="w-4 h-4" />
                            All posts
                        </a>
                    @else
                        <p class="text-gray-500 dark:text-gray-400">No posts yet. Check back soon.</p>
                    @endif
                </div>
            @else
                <div class="divide-y divide-gray-200/60 dark:divide-white/[0.04]">
                    @foreach($posts as $post)
                        <x-ink::post-card :post="$post" />
                    @endforeach
                </div>

                <div class="mt-12 pt-8 border-t border-gray-200/60 dark:border-white/[0.04]">
                    {{ $posts->links('blog.pagination') }}
                </div>
            @endif
        </div>
    </div>
</x-guest-layout>
