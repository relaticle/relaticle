<x-filament-panels::page>
    <x-filament::breadcrumbs :breadcrumbs="$this->getBreadcrumbs()" />

    <x-filament::section class="-mt-2">
        <x-slot name="heading">
            <div class="flex items-start gap-3">
                <x-brand.logomark size="sm" class="mt-0.5 shrink-0 text-gray-950 dark:text-white" />

                <div class="min-w-0">
                    <p class="truncate text-base font-semibold text-gray-950 dark:text-white">
                        {{ $this->forwardingAddress->fullAddress() }}
                    </p>
                    <p class="mt-1 text-sm font-normal text-gray-500 dark:text-gray-400">
                        {{ __('filament/pages/forwarding-address-settings.subheading') }}
                    </p>
                </div>
            </div>
        </x-slot>

        {{ $this->form }}

        <div class="mt-6 flex justify-end">
            {{ $this->saveAction }}
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>
