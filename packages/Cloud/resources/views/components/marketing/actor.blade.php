@props([
    'as',
    'size' => 'sm',
])

@php
    $actors = [
        'you' => [__('You'), 'ri-user-3-fill', 'bg-gray-900 text-white dark:bg-white dark:text-gray-950'],
        'rela' => [(string) config('chat.assistant_name'), 'ri-sparkling-2-fill', 'bg-primary-600 text-white'],
        'claude' => ['Claude', 'ri-claude-fill', 'bg-anthropic text-white'],
        'chatgpt' => ['ChatGPT', 'ri-openai-fill', 'bg-gray-600 text-white dark:bg-gray-300 dark:text-gray-950'],
        'gemini' => ['Gemini', 'ri-gemini-fill', 'bg-gemini text-white'],
        'mailbox' => [__('Mailbox'), 'ri-mail-fill', 'bg-emerald-700 text-white'],
    ];

    [$label, $icon, $classes] = $actors[$as];
@endphp

<span {{ $attributes->class([
    'inline-flex items-center gap-1.5 rounded-full py-0.5 pl-0.5 font-medium',
    'pr-2.5 text-xs' => $size === 'sm',
    'pr-3 text-sm sm:text-base' => $size === 'lg',
    $classes,
]) }}>
    <span @class([
        'flex items-center justify-center rounded-full bg-white/20',
        'h-5 w-5' => $size === 'sm',
        'h-6 w-6' => $size === 'lg',
    ]) aria-hidden="true">
        <x-dynamic-component :component="$icon" :class="$size === 'lg' ? 'h-3.5 w-3.5' : 'h-3 w-3'"/>
    </span>
    <span>{{ $label }}</span>
</span>
