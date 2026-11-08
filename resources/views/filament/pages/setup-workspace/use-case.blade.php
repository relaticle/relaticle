<form wire:submit="saveUseCase" class="flex flex-1 flex-col">
    <x-onboarding.step-heading wire:key="setup-step-heading-use-case">
        {{ __('filament/pages/workspaces.create_workspace.headings.use_case') }}
    </x-onboarding.step-heading>
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
        {{ __('filament/pages/workspaces.create_workspace.headings.use_case_description') }}
    </p>
    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
        {{ __('filament/pages/workspaces.create_workspace.headings.use_case_hint') }}
    </p>

    <div class="mt-6">
        {{ $this->form }}
    </div>

    <div class="mt-auto pt-6">
        <x-filament::button type="submit" size="lg" class="w-full" wire:target="saveUseCase">
            {{ __('filament/pages/workspaces.create_workspace.actions.continue') }}
        </x-filament::button>
    </div>
</form>
