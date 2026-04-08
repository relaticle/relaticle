<x-guest-layout title="Preview - {{ $post->title }}">
    @push('header')
        <meta name="robots" content="noindex, nofollow">
    @endpush

    <x-blog::preview-banner :post="$post" :editUrl="$editUrl ?? null" />

    <div class="pt-32 pb-24 md:pt-40 md:pb-32 bg-white dark:bg-black">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <x-blog::post-header :post="$post" />
            <x-blog::post-body :post="$post" />
            <x-blog::related-posts :posts="$relatedPosts" />
        </div>
    </div>
</x-guest-layout>
