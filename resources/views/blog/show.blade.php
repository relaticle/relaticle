@php
    // The panel's SEO section writes title/description to the post's `seo` row and
    // wins when set. Ink's own renderer can't honour it — getDynamicSEOData() always
    // returns the post title, which takes precedence in prepareForUsage() — so the
    // override has to be applied here.
    $seoTitle = $post->seo->title ?: $post->title;
    $seoDescription = $post->seo->description
        ?: ($post->excerpt ?: \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($post->content))), 155));
@endphp

<x-guest-layout
    :title="$seoTitle . ' - ' . config('app.name') . ' Blog'"
    :description="$seoDescription"
    :ogTitle="$seoTitle"
    :ogDescription="$seoDescription"
    :ogImage="$post->featured_image ? asset('storage/' . $post->featured_image) : null"
    ogType="article">
    {{-- Only the tags the layout does not already emit. <x-ink::meta-tags> repeats
         the whole og/twitter/canonical set, which put two canonicals and a
         conflicting og:type on every post page. --}}
    @push('header')
        @if($post->published_at)
            <meta property="article:published_time" content="{{ $post->published_at->toIso8601String() }}" />
        @endif
        @if($post->updated_at)
            <meta property="article:modified_time" content="{{ $post->updated_at->toIso8601String() }}" />
        @endif
        @if($post->author)
            <meta property="article:author" content="{{ $post->author->name }}" />
        @endif
        @if($post->category)
            <meta property="article:section" content="{{ $post->category->name }}" />
        @endif
        <x-ink::feed-link />
    @endpush

    <x-ink::structured-data :post="$post" />

    <div class="pt-32 pb-24 md:pt-40 md:pb-32 bg-white dark:bg-black">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-12 gap-6 lg:gap-12">
                <!-- Main Content -->
                {{-- break-words inherits, so a long unbreakable token in the title or
                     the body wraps instead of painting over the table of contents. --}}
                <article class="col-span-12 lg:col-span-8 xl:col-span-9 min-w-0 break-words blog-prose">
                    <div class="mb-6">
                        <a href="{{ route('blog.index') }}"
                           class="inline-flex items-center gap-1.5 text-sm text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                            <x-ri-arrow-left-line class="w-4 h-4" />
                            Back to blog
                        </a>
                    </div>

                    <x-ink::post-header :post="$post" />
                    <x-ink::post-body :post="$post" />

                    @if($post->tags->isNotEmpty())
                        <div class="mt-12 pt-8 border-t border-gray-200/60 dark:border-white/[0.04] flex flex-wrap items-center gap-2">
                            <span class="text-sm text-gray-500 dark:text-gray-400">Tagged:</span>
                            @foreach($post->tags as $tag)
                                <a href="{{ route('blog.tag', $tag->slug) }}"
                                   class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 dark:bg-white/[0.06] text-gray-700 dark:text-gray-300 hover:bg-primary-50 dark:hover:bg-primary-500/10 hover:text-primary-700 dark:hover:text-primary-300 transition-colors">
                                    #{{ $tag->name }}
                                </a>
                            @endforeach
                        </div>
                    @endif

                    <x-ink::related-posts :posts="$relatedPosts" />
                </article>

                <!-- Right Sidebar: Table of Contents -->
                <aside class="hidden lg:block col-span-4 xl:col-span-3">
                    <x-blog.toc :post="$post" />
                </aside>
            </div>
        </div>
    </div>
</x-guest-layout>
