<div class="space-y-3 text-sm text-gray-700 dark:text-gray-300">
    <p>{{ __('filament/emails/composer.variables.warning_description') }}</p>
    @foreach ($warnings as $code => $addresses)
        <p><strong>{{ $labels[$code] ?? $code }}</strong>: {{ implode(', ', $addresses) }}</p>
    @endforeach
</div>
