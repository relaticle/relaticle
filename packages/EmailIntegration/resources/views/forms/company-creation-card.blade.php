@php
    $statePath = $getStatePath();
    $toggleState = '$wire.$entangle(\''.$statePath.'\', true)';
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="ei-sharing-card"
        x-on:click="if (! $event.target.closest('.fi-toggle')) $refs.companyToggle.click()"
    >
        <span class="ei-sharing-card-icon flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 transition dark:bg-white/10 dark:text-gray-400">
            <x-filament::icon icon="heroicon-o-building-office-2" class="h-5 w-5" />
        </span>

        <span class="min-w-0 flex-1">
            <span class="ei-sharing-card-title block text-sm font-medium text-gray-950 dark:text-white">
                {{ __('filament/pages/email-privacy-settings.record_creation.companies.label') }}
            </span>
            <span class="mt-0.5 block text-sm text-gray-600 dark:text-gray-400">
                {{ __('filament/pages/email-privacy-settings.record_creation.companies.description') }}
            </span>
        </span>

        <x-filament::toggle
            x-ref="companyToggle"
            class="mt-1 shrink-0"
            :state="$toggleState"
            :aria-label="__('filament/pages/email-privacy-settings.record_creation.companies.label')"
        />
    </div>
</x-dynamic-component>
