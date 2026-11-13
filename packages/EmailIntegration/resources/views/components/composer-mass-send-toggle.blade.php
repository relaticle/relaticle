@props([
    'state' => '$wire.$entangle(\'isMassSend\').live',
])

<div {{ $attributes->class(['flex items-center gap-1.5']) }}>
    <x-filament::toggle :state="$state" id="composer-mass-send-toggle" class="shrink-0 sm:scale-75 max-sm:after:absolute max-sm:after:inset-x-0 max-sm:after:-inset-y-2.5 max-sm:after:content-['']" />
    <label for="composer-mass-send-toggle" class="cursor-pointer text-xs max-sm:py-3.5 text-gray-700 dark:text-gray-200">
        {{ __('filament/emails/composer.mass_send.toggle') }}
    </label>
</div>
