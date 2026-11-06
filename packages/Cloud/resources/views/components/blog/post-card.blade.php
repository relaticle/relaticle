@props([
    'post',
    'heading' => 'h2',
])

<li class="bg-surface">
    <a href="{{ $post->getUrl() }}" wire:navigate class="hover:bg-tint group grid gap-5 p-6 transition-colors md:grid-cols-12 md:gap-8 md:p-10">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 md:col-span-3 md:flex-col md:items-start">
            @if($post->published_at)
                <time datetime="{{ $post->published_at->toIso8601String() }}" class="type-label text-gray-500 dark:text-gray-400">
                    {{ $post->published_at->format('M j, Y') }}
                </time>
            @endif
            @if($post->category)
                <span class="type-label text-primary-700 dark:text-primary-300">{{ $post->category->name }}</span>
            @endif
        </div>

        <div @class(['min-w-0', 'md:col-span-6' => $post->featured_image, 'md:col-span-9' => ! $post->featured_image])>
            <{{ $heading }} class="text-lg font-medium break-words text-gray-950 transition-colors group-hover:text-primary-700 md:text-xl dark:text-white dark:group-hover:text-primary-300">
                {{ $post->title }}
            </{{ $heading }}>

            @if($post->excerpt)
                <p class="type-prose mt-2 line-clamp-2 text-gray-500 dark:text-gray-400">{{ $post->excerpt }}</p>
            @endif
        </div>

        @if($post->featured_image)
            {{-- Covers are 1200x630; aspect-video keeps them uncropped enough that
                 the title baked into the image survives (a square chops 48% off). --}}
            <div class="md:col-span-3">
                <img
                    src="{{ asset('storage/'.$post->featured_image) }}"
                    alt=""
                    loading="lazy"
                    class="border-rail aspect-video w-full rounded-lg border object-cover sm:w-64 md:w-full"
                >
            </div>
        @endif
    </a>
</li>
