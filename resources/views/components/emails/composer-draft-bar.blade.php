{{-- The Draft marker and the one control that deletes. Every × on a Draft bar
     throws the draft away, including a row a previous save already wrote —
     unlike the window chrome's ×, which puts the draft away and keeps it. --}}
<div {{ $attributes->class(['flex shrink-0 items-center justify-between gap-2']) }}>
    <span class="text-sm font-medium text-primary-600 dark:text-primary-400">
        {{ __('filament/emails/composer.draft') }}
    </span>

    <button
        type="button"
        wire:click="discard"
        aria-label="{{ __('filament/emails/composer.actions.discard') }}"
        x-tooltip="{ content: @js(__('filament/emails/composer.actions.discard')), theme: $store.theme }"
        class="rounded-lg p-1.5 text-gray-400 transition hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-400/10 dark:hover:text-danger-400"
    >
        <x-heroicon-m-x-mark class="h-4 w-4" />
    </button>
</div>
