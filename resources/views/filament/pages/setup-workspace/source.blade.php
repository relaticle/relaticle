<form wire:submit="saveSource" class="flex flex-1 flex-col">
    <x-onboarding.step-heading wire:key="setup-step-heading-source">
        {{ __('filament/pages/workspaces.setup_workspace.source.heading') }}
    </x-onboarding.step-heading>
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
        {{ __('filament/pages/workspaces.setup_workspace.source.description') }}
    </p>

    <div class="mt-6">
        {{ $this->form }}
    </div>

    <div class="mt-auto pt-6">
        <x-filament::button type="submit" size="lg" class="w-full" wire:target="saveSource">
            {{ __('filament/pages/workspaces.create_workspace.actions.continue') }}
        </x-filament::button>
    </div>
</form>
