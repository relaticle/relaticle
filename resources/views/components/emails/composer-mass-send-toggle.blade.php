@props([
    'state' => '$wire.$entangle(\'isMassSend\').live',
])

<div {{ $attributes->class(['flex items-center gap-1.5']) }}>
    <x-filament::toggle :state="$state" id="composer-mass-send-toggle" class="shrink-0 scale-75" />
    <label for="composer-mass-send-toggle" class="cursor-pointer text-xs text-gray-700 dark:text-gray-200">
        {{ __('filament/emails/composer.mass_send.toggle') }}
    </label>
</div>
