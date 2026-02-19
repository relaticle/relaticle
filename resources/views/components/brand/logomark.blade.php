@props([
    'size' => 'md',
])

<x-brand.logo-lockup
    :show-wordmark="false"
    :size="$size"
    {{ $attributes }}
/>
