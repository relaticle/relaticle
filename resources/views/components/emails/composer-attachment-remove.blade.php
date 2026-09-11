<button
    type="button"
    {{ $attributes->class(['shrink-0 rounded-md p-1 text-gray-400 transition hover:bg-gray-100 hover:text-danger-600 dark:hover:bg-white/10 dark:hover:text-danger-400']) }}
    aria-label="{{ __('filament/emails/composer.actions.remove_attachment') }}"
>
    <x-heroicon-m-x-mark class="h-4 w-4" />
</button>
