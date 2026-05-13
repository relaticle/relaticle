<x-guest-layout
    :title="$post->title . ' - ' . config('app.name') . ' Blog'"
    :description="$post->excerpt"
    :ogTitle="$post->title"
    :ogDescription="$post->excerpt"
    :ogImage="$post->featured_image ? asset('storage/' . $post->featured_image) : null">
    @push('header')
        <x-ink::meta-tags :post="$post" />
        <x-ink::feed-link />
    @endpush

    <x-ink::structured-data :post="$post" />

    <div class="pt-32 pb-24 md:pt-40 md:pb-32 bg-white dark:bg-black">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-12 gap-6 lg:gap-12">
                <!-- Main Content -->
                <article class="col-span-12 lg:col-span-8 xl:col-span-9">
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
