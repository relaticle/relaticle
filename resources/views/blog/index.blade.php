<x-guest-layout
    :title="isset($category) ? $category->name . ' - ' . config('app.name') . ' Blog' : config('app.name') . ' - Engineering Blog'"
    :description="isset($category) ? 'Posts about ' . $category->name . ' from the Relaticle engineering team.' : 'Engineering blog from the Relaticle team. Deep dives into building an open-source CRM with MCP, AI agents, and modern Laravel.'"
    :ogTitle="isset($category) ? $category->name . ' - ' . config('app.name') . ' Blog' : config('app.name') . ' - Engineering Blog'">
    @push('header')
        <x-blog::feed-link />
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
                @else
                    <p class="text-lg text-gray-500 dark:text-gray-400 max-w-2xl mx-auto leading-relaxed">
                        Deep dives into building an open-source CRM for AI agents.
                    </p>
                @endif
            </div>

            @if($posts->isEmpty())
                <div class="text-center py-16">
                    <p class="text-gray-500 dark:text-gray-400">No posts yet. Check back soon.</p>
                </div>
            @else
                <div class="divide-y divide-gray-200/60 dark:divide-white/[0.04]">
                    @foreach($posts as $post)
                        <x-blog::post-card :post="$post" />
                    @endforeach
                </div>

                <div class="mt-12">
                    {{ $posts->links() }}
                </div>
            @endif
        </div>
    </div>
</x-guest-layout>
