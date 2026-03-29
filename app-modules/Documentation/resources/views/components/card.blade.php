@props([
    'title',
    'description',
    'link',
    'icon' => null,
])

<a href="{{ $link }}" {{ $attributes->merge(['class' => 'group block p-6 md:p-8 transition-colors duration-200 hover:bg-gray-50/50 dark:hover:bg-white/[0.02]']) }}>
    <div class="flex flex-col h-full">
        <div class="mb-4 text-gray-400 dark:text-gray-500 group-hover:text-primary dark:group-hover:text-primary-400 transition-colors duration-200">
            @if($icon)
                <x-dynamic-component :component="$icon" class="h-6 w-6"/>
            @else
                <x-heroicon-o-document-text class="h-6 w-6"/>
            @endif
        </div>

        <h3 class="font-display text-[15px] font-semibold text-gray-900 dark:text-white mb-2">
            {{ $title }}
        </h3>
        <div class="text-[13px] leading-relaxed text-gray-500 dark:text-gray-400 mb-5">
            @if($description)
                <p>{{ $description }}</p>
            @else
                {{ $slot }}
            @endif
        </div>

        <div class="mt-auto">
            <span class="inline-flex items-center gap-1 text-xs font-medium text-gray-900 dark:text-white group-hover:text-primary dark:group-hover:text-primary-400 transition-colors duration-200">
                Read docs
                <x-ri-arrow-right-line class="w-3 h-3 group-hover:translate-x-0.5 transition-transform duration-200"/>
            </span>
        </div>
    </div>
</a>
