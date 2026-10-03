@use('Illuminate\Support\Number')

@props([
    'account',
    'icon',
])

@php
    $percent = $account->syncDisplayPercent();
    $showsPercent = $percent !== null
        && ($account->showsMailboxHistoryImportPercent() || $account->showsCalendarSyncProgress() || $account->isEmailSyncing());
    $imported = $percent === null ? $account->syncEmailsProcessedCount() : 0;
    $importedLabel = trans_choice('filament/pages/email-accounts.importing_count', $imported, ['count' => Number::format($imported)]);
@endphp

<x-filament::badge
    color="info"
    size="sm"
    :icon="$icon"
    class="whitespace-nowrap"
    role="progressbar"
    aria-busy="true"
    aria-valuemin="0"
    aria-valuemax="100"
    :aria-valuenow="$percent"
    :aria-valuetext="$percent === null
        ? $importedLabel
        : __('filament/pages/email-accounts.importing_percent', ['percent' => $percent])"
    :aria-label="__('filament/pages/email-accounts.importing')"
>
    {{ __('filament/pages/email-accounts.importing') }}
    @if ($showsPercent)
        {{ __('filament/pages/email-accounts.importing_percent', ['percent' => $percent]) }}
    @elseif ($imported > 0)
        {{ $importedLabel }}
    @endif
</x-filament::badge>
