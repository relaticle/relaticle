@props([
    'quote',
    'name',
    'role',
    'company',
    'url' => null,
    'avatar' => null,
])

<figure {{ $attributes->class('bg-surface flex flex-col justify-between gap-8') }}>
    <blockquote class="type-prose text-gray-700 dark:text-gray-200">
        <p>{{ $quote }}</p>
    </blockquote>

    <figcaption class="flex items-center gap-3">
        @if($avatar)
            <img
                src="{{ Str::isUrl($avatar) ? $avatar : Vite::asset($avatar) }}"
                alt=""
                width="40"
                height="40"
                loading="lazy"
                class="h-10 w-10 shrink-0 rounded-full object-cover"
            />
        @endif

        <div>
            @if($url)
                <a href="{{ $url }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-medium text-gray-950 transition-colors hover:text-primary dark:text-white dark:hover:text-primary-400">
                    {{ $name }}
                    <x-lucide-arrow-up-right class="h-3.5 w-3.5" aria-hidden="true"/>
                </a>
            @else
                <p class="font-medium text-gray-950 dark:text-white">{{ $name }}</p>
            @endif

            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __(':role, :company', ['role' => $role, 'company' => $company]) }}</p>
        </div>
    </figcaption>
</figure>
