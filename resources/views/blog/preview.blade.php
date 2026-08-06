<x-guest-layout title="Preview - {{ $post->title }}">
    @push('header')
        <meta name="robots" content="noindex, nofollow">
    @endpush

    {{-- Ink's banner is `sticky top-0 z-[60]`, and the marketing header is `fixed z-50`
         and 65px tall, so on its own the banner paints over the logo and the sign-in
         buttons. Pin it directly beneath the header, below it in the stack. --}}
    <div class="blog-preview-banner">
        <x-ink::preview-banner :post="$post" :editUrl="$editUrl ?? null" />
    </div>

    <div class="pt-32 pb-24 md:pt-40 md:pb-32 bg-white dark:bg-black">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 min-w-0 break-words blog-prose">
            <x-ink::post-header :post="$post" />
            <x-ink::post-body :post="$post" />
            <x-ink::related-posts :posts="$relatedPosts" />
        </div>
    </div>
</x-guest-layout>
