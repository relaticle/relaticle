@props([
    'email' => null,
    'grantUrl' => null,
])

<div {{ $attributes->merge(['class' => 'flex min-h-0 flex-1 flex-col items-center justify-center gap-4 px-8 py-16 text-center']) }}>
    <div class="flex items-center gap-2" aria-hidden="true">
        <div class="relative flex h-16 w-16 items-center justify-center">
            <span class="absolute inset-0 rounded-full border border-dashed border-primary-400/40"></span>
            <span class="absolute inset-2 rounded-full border border-primary-500/50"></span>
            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-50 dark:bg-primary-500/10">
                <x-heroicon-o-envelope class="h-5 w-5 text-primary-600 dark:text-primary-400" />
            </span>
        </div>

        <span class="w-8 border-t border-dashed border-gray-300 dark:border-gray-600"></span>

        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-50 ring-1 ring-gray-200 dark:bg-white/5 dark:ring-white/10">
            <x-heroicon-o-cube class="h-5 w-5 text-gray-400 dark:text-gray-500" />
        </div>
    </div>

    <p class="max-w-md text-base font-semibold text-gray-900 dark:text-white">
        {{ filled($email)
            ? __('filament/emails/composer.grant_send.heading', ['email' => $email])
            : __('filament/emails/composer.grant_send.heading_generic') }}
    </p>

    <p class="max-w-sm text-sm text-gray-500 dark:text-gray-400">
        {{ __('filament/emails/composer.grant_send.description') }}
    </p>

    <div class="mt-1">
        @if (isset($slot) && ! $slot->isEmpty())
            {{ $slot }}
        @elseif (filled($grantUrl))
            <x-filament::button
                tag="a"
                :href="$grantUrl"
                color="primary"
            >
                {{ __('filament/emails/composer.actions.grant_send.label') }}
            </x-filament::button>
        @endif
    </div>
</div>
