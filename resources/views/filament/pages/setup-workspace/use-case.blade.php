<form wire:submit="saveUseCase" class="flex flex-1 flex-col">
    <h3 class="text-xl font-bold tracking-tight text-gray-950 dark:text-white">
        {{ __('filament/pages/workspaces.create_workspace.headings.use_case') }}
    </h3>
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
        <x-filament::button type="submit" size="lg" class="w-full">
            {{ __('filament/pages/workspaces.create_workspace.actions.get_started') }}
        </x-filament::button>
    </div>
</form>
