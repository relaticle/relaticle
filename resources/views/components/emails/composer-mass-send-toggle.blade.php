@props([
    'state' => '$wire.$entangle(\'isMassSend\').live',
])

<div {{ $attributes->class(['flex items-center gap-1.5']) }}>
    <x-filament::toggle :state="$state" class="shrink-0 scale-75" />
    <span class="text-xs text-gray-700 dark:text-gray-200">
        {{ __('filament/emails/composer.mass_send.toggle') }}
    </span>
</div>
