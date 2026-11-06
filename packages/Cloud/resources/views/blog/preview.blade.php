<x-cloud::guest-layout title="Preview - {{ $post->title }}">
    @push('header')
        <meta name="robots" content="noindex, nofollow">
    @endpush

    <x-ink::preview-banner :post="$post" :editUrl="$editUrl ?? null" />

    <x-cloud::blog.article :post="$post" :relatedPosts="$relatedPosts" />
</x-cloud::guest-layout>
