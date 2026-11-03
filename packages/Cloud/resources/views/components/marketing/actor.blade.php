@props([
    'as',
])

@php
    $actors = [
        'you' => [__('You'), 'ri-user-3-fill', 'bg-gray-900 text-white dark:bg-white dark:text-gray-950'],
        'rela' => [(string) config('chat.assistant_name'), 'ri-sparkling-2-fill', 'bg-primary-600 text-white'],
        'claude' => ['Claude', 'ri-claude-fill', 'bg-anthropic text-white'],
        'mailbox' => [__('Mailbox'), 'ri-mail-fill', 'bg-emerald-700 text-white'],
    ];

    [$label, $icon, $classes] = $actors[$as];
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-full py-0.5 pl-0.5 pr-2.5 text-xs font-medium', $classes]) }}>
    <span class="flex h-5 w-5 items-center justify-center rounded-full bg-white/20" aria-hidden="true">
        <x-dynamic-component :component="$icon" class="h-3 w-3"/>
    </span>
    {{ $label }}
</span>
