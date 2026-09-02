@props([
    'account',
    'icon',
])

<x-filament::badge
    color="info"
    size="sm"
    :icon="$icon"
    class="whitespace-nowrap"
    role="progressbar"
    aria-busy="true"
    aria-valuemin="0"
    aria-valuemax="100"
    aria-valuenow="{{ $account->initialSyncProgressPercent() }}"
    aria-valuetext="{{ __('filament/pages/email-accounts.importing_percent', ['percent' => $account->initialSyncProgressPercent()]) }}"
    aria-label="{{ __('filament/pages/email-accounts.importing') }}"
>
    {{ __('filament/pages/email-accounts.importing') }}
    {{ __('filament/pages/email-accounts.importing_percent', ['percent' => $account->initialSyncProgressPercent()]) }}
</x-filament::badge>
