<x-filament-panels::page>
    @include('email-integration::filament.pages.partials.cluster-header')

    @livewire(\App\Livewire\App\Email\UserEmailPrivacySettings::class)
</x-filament-panels::page>
