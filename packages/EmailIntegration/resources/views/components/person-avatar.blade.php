@props([
    'src' => null,
    'alt' => '',
    'size' => 'md',
])

@php
    use App\Enums\CrmEntity;

    $isSmall = $size === 'sm';
@endphp

@if (filled($src))
    <x-filament::avatar
        :src="$src"
        :alt="$alt"
        :size="$isSmall ? 'size-6' : 'md'"
        :circular="true"
        data-testid="member-avatar"
        :attributes="$attributes->class(['block shrink-0'])"
    />
@else
    <span
        data-testid="person-silhouette"
        aria-hidden="true"
        {{ $attributes->class([
            'flex shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-600 ring-1 ring-gray-950/5',
            'dark:bg-white/5 dark:text-gray-300 dark:ring-white/10',
            'size-6' => $isSmall,
            'size-8' => ! $isSmall,
        ]) }}
    >
        <x-filament::icon
            :icon="CrmEntity::People->icon()"
            @class([
                'size-3.5' => $isSmall,
                'size-4' => ! $isSmall,
            ])
        />
    </span>
@endif
