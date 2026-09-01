@props([
    'icon',
    'label',
])

<button
    type="button"
    {{ $attributes->merge(['class' => 'rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/10 dark:hover:text-gray-200']) }}
    aria-label="{{ $label }}"
    x-tooltip="{ content: @js($label), theme: $store.theme }"
>
    <x-dynamic-component :component="$icon" class="h-4 w-4" />
</button>
