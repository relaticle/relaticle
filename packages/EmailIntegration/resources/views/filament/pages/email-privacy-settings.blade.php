<x-filament-panels::page>
    @include('email-integration::filament.pages.partials.cluster-header')

    {{ $this->form }}

    <div class="mt-6">
        {{ $this->saveAction }}
    </div>
</x-filament-panels::page>
