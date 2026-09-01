@props([
    'heading',
    'description',
    'icon' => 'heroicon-o-envelope',
])

<div class="flex flex-col items-center justify-center gap-3 px-8 py-16 text-center">
    <div class="flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
        <x-filament::icon :icon="$icon" class="h-8 w-8 text-gray-400 dark:text-gray-500" />
    </div>

    <p class="text-base font-semibold text-gray-900 dark:text-white">{{ $heading }}</p>

    <p class="max-w-sm text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>

    <x-filament::button
        tag="a"
        :href="\Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage::getUrl()"
        icon="heroicon-o-cog-6-tooth"
        class="mt-2"
    >
        {{ __('filament/pages/email-accounts.not_connected.action') }}
    </x-filament::button>
</div>
