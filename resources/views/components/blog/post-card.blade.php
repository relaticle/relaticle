@props(['post'])

<a href="{{ $post->getUrl() }}" wire:navigate class="group block py-6 -mx-4 px-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-900/50 transition-colors duration-200">
    <div class="flex items-start justify-between gap-6">
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-3 mb-2">
                @if($post->category)
                    <x-ink::category-badge :category="$post->category" :linked="false" />
                @endif
                @if($post->published_at)
                    <time datetime="{{ $post->published_at->toIso8601String() }}" class="text-xs text-gray-400 dark:text-gray-500">
                        {{ $post->published_at->format('M j, Y') }}
                    </time>
                @endif
            </div>

            <h2 class="text-lg font-semibold text-gray-900 dark:text-white group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors duration-200 mb-1 break-words">
                {{ $post->title }}
            </h2>

            @if($post->excerpt)
                <p class="text-sm text-gray-500 dark:text-gray-400 line-clamp-2">{{ $post->excerpt }}</p>
            @endif
        </div>

        @if($post->featured_image)
            {{-- Covers are 1200x630; aspect-video keeps them uncropped enough that
                 the title baked into the image survives (a square chops 48% off). --}}
            <img
                src="{{ asset('storage/'.$post->featured_image) }}"
                alt="{{ $post->title }}"
                loading="lazy"
                class="w-28 sm:w-40 aspect-video object-cover rounded-lg shrink-0"
            >
        @endif
    </div>
</a>
