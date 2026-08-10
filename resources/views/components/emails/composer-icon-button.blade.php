@props([
    'icon',
    'label',
])

<button
    type="button"
    {{ $attributes->merge(['class' => 'rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800']) }}
    aria-label="{{ $label }}"
    x-tooltip="{ content: @js($label), theme: $store.theme }"
>
    <x-dynamic-component :component="$icon" class="h-4 w-4" />
</button>
