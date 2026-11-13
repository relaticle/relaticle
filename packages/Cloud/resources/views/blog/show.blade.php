@php
    // The panel's SEO section writes title/description to the post's `seo` row and
    // wins when set. Ink's own renderer can't honour it, because getDynamicSEOData()
    // always returns the post title, which takes precedence in prepareForUsage().
    // The override has to be applied here instead.
    $seoTitle = $post->seo->title ?: $post->title;
    $seoDescription = $post->seo->description
        ?: ($post->excerpt ?: \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($post->content))), 155));
@endphp

<x-cloud::guest-layout
    :title="$seoTitle . ' - ' . config('app.name')"
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

    <x-cloud::blog.article :post="$post" :relatedPosts="$relatedPosts" />
</x-cloud::guest-layout>
