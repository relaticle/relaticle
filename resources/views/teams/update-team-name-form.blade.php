<x-form-section submit="updateTeamName">
    <x-slot name="title">
        {{ __('Workspace Name') }}
    </x-slot>

    <x-slot name="description">
        {{ __('The workspace\'s name and owner information.') }}
    </x-slot>

    <x-slot name="form">
        <!-- Workspace Owner Information -->
        <div class="col-span-6">
            <x-label value="{{ __('Workspace Owner') }}"/>

            <div class="flex items-center mt-2">
                <img class="w-12 h-12 rounded-full object-cover" src="{{ $team->owner->profile_photo_url }}"
                     alt="{{ $team->owner->name }}">

                <div class="ms-4 leading-tight">
                    <div class="text-gray-900 dark:text-white">{{ $team->owner->name }}</div>
                    <div class="text-gray-700 dark:text-gray-300 text-sm">{{ $team->owner->email }}</div>
                </div>
            </div>
        </div>

        <!-- Workspace Name -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="name" value="{{ __('Workspace Name') }}"/>

            <x-filament::input.wrapper>
                <x-filament::input id="name" type="text" wire:model="state.name" :disabled="! Gate::check('update', $team)" />
            </x-filament::input.wrapper>

            <x-input-error for="name" class="mt-2"/>
        </div>
    </x-slot>

    @if (Gate::check('update', $team))
        <x-slot name="actions">
            <x-action-message class="me-3" on="saved">
                {{ __('Saved.') }}
            </x-action-message>

            <x-button>
                {{ __('Save') }}
            </x-button>
        </x-slot>
    @endif
</x-form-section>
