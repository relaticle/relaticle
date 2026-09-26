@props([
    'count',
])

<span {{ $attributes->class([
    'inline-flex items-center rounded-md bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200',
]) }}>
    {{ __('filament/emails/composer.mass_send.summary', ['count' => $count]) }}
</span>
