@props(['posts'])

@if($posts->isNotEmpty())
    <section class="mt-10 pt-10 border-t border-gray-200/60 dark:border-white/[0.04]">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Related posts</h2>
        <div class="divide-y divide-gray-200/60 dark:divide-white/[0.04]">
            @foreach($posts as $relatedPost)
                <x-blog.post-card :post="$relatedPost" />
            @endforeach
        </div>
    </section>
@endif
