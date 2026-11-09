@props([
    'post',
    'relatedPosts',
])

@php
    $readTime = max(1, (int) ceil(str_word_count(strip_tags($post->content)) / 200));
    $crumbClass = 'transition-colors hover:text-gray-950 dark:hover:text-white';
@endphp

<x-cloud::marketing.hero align="start">
    <x-slot:eyebrow>
        <nav aria-label="{{ __('Breadcrumb') }}">
            <ol class="type-label flex flex-wrap items-center gap-2 text-gray-500 dark:text-gray-400">
                <li><a href="{{ route('blog.index') }}" class="{{ $crumbClass }}">{{ __('Blog') }}</a></li>
                @if($post->category)
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ route('blog.category', $post->category->slug) }}" class="{{ $crumbClass }}">{{ $post->category->name }}</a></li>
                @endif
            </ol>
        </nav>
    </x-slot:eyebrow>

    <x-slot:title><span class="break-words">{{ $post->title }}</span></x-slot:title>

    <x-slot:aside>
        @if($post->excerpt)
            <p class="text-base leading-relaxed text-gray-500 md:text-lg dark:text-gray-400">{{ $post->excerpt }}</p>
        @endif

        <p @class(['flex flex-wrap items-center gap-x-2 text-sm text-gray-500 dark:text-gray-400', 'mt-5' => $post->excerpt])>
            @if($post->author)
                <span class="font-medium text-gray-950 dark:text-white">{{ $post->author->name }}</span>
                <span aria-hidden="true">&middot;</span>
            @endif
            @if($post->published_at)
                <time datetime="{{ $post->published_at->toIso8601String() }}">{{ $post->published_at->format('F j, Y') }}</time>
            @else
                <span>{{ __('Draft') }}</span>
            @endif
            <span aria-hidden="true">&middot;</span>
            <span>{{ __(':count min read', ['count' => $readTime]) }}</span>
        </p>
    </x-slot:aside>

    @if($post->featured_image)
        <div class="bg-tint border-rail border-t p-6 md:p-10">
            <img
                src="{{ asset('storage/'.$post->featured_image) }}"
                alt="{{ $post->title }}"
                fetchpriority="high"
                class="border-rail aspect-video w-full rounded-xl border object-cover"
            >
        </div>
    @endif
</x-cloud::marketing.hero>

<x-cloud::marketing.frame>
    <div class="bg-rail grid grid-cols-1 gap-px lg:grid-cols-12">
        {{-- break-words inherits, so a long unbreakable token in the body wraps
             instead of painting over the table of contents. --}}
        <article class="bg-surface blog-prose min-w-0 break-words px-6 py-12 md:px-10 md:py-16 lg:col-span-8">
            <div class="prose max-w-none dark:prose-invert prose-headings:font-medium prose-headings:tracking-tight prose-headings:text-gray-950 dark:prose-headings:text-white prose-a:text-primary-700 prose-a:no-underline hover:prose-a:underline dark:prose-a:text-primary-300 prose-code:text-sm prose-img:rounded-xl">
                {!! $post->renderedContent() !!}
            </div>

            @if($post->tags->isNotEmpty())
                <div class="border-rail mt-12 flex flex-wrap items-center gap-2 border-t pt-8">
                    <span class="type-label mr-1 text-gray-500 dark:text-gray-400">{{ __('Tagged') }}</span>
                    @foreach($post->tags as $tag)
                        <a href="{{ route('blog.tag', $tag->slug) }}"
                           class="border-rail hover:bg-tint inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-medium text-gray-700 transition-colors dark:text-gray-200">
                            #{{ $tag->name }}
                        </a>
                    @endforeach
                </div>
            @endif
        </article>

        <aside class="bg-surface hidden p-10 lg:col-span-4 lg:block">
            <x-cloud::blog.toc :post="$post" />
        </aside>
    </div>
</x-cloud::marketing.frame>

<x-cloud::blog.related-posts :posts="$relatedPosts" />
