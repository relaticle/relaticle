<x-filament-panels::page>
    {{ $this->form }}

    <div class="mt-6">
        {{ $this->saveAction }}
    </div>

    <div class="mt-6">
        @livewire(\App\Livewire\App\Email\UserEmailPrivacySettings::class)
    </div>
</x-filament-panels::page>
