@props([
    'account',
    'icon',
])

@php
    $incrementalLabel = $account->incrementalSyncStatusLabel();
    $percent = $incrementalLabel === null ? $account->initialSyncProgressPercent() : null;
@endphp

@if ($incrementalLabel !== null)
    <x-filament::badge
        color="info"
        size="sm"
        :icon="$icon"
        class="whitespace-nowrap"
        role="progressbar"
        aria-busy="true"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-label="$incrementalLabel"
    >
        {{ $incrementalLabel }}
    </x-filament::badge>
@else
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
        :aria-valuetext="__('filament/pages/email-accounts.importing_percent', ['percent' => $percent])"
        :aria-label="__('filament/pages/email-accounts.importing')"
    >
        {{ __('filament/pages/email-accounts.importing') }}
        {{ __('filament/pages/email-accounts.importing_percent', ['percent' => $percent]) }}
    </x-filament::badge>
@endif
