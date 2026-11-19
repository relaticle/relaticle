<form wire:submit="finish" class="flex flex-1 flex-col">
    <x-onboarding.step-heading wire:key="setup-step-heading-invite">
        {{ __('filament/pages/workspaces.setup_workspace.invite.heading') }}
    </x-onboarding.step-heading>
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
        {{ __('filament/pages/workspaces.setup_workspace.invite.description') }}
    </p>

    <div class="mt-6">
        {{ $this->form }}

        <p class="mt-2 text-sm text-pretty text-gray-600 dark:text-gray-400">
            {{ __('filament/pages/workspaces.setup_workspace.invite.helper') }}
        </p>
    </div>

    <div class="mt-auto pt-6">
        <x-filament::button type="submit" size="lg" class="w-full" wire:target="finish">
            <span
                x-data
                x-text="($wire.data?.emails ?? '').trim() === '' ? @js(__('filament/pages/workspaces.create_workspace.actions.get_started')) : @js(__('filament/pages/workspaces.setup_workspace.invite.send_and_start'))"
            >{{ __('filament/pages/workspaces.create_workspace.actions.get_started') }}</span>
        </x-filament::button>
    </div>
</form>
