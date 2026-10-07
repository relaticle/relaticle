@php
    $mailbox = $this->connectedMailbox();
@endphp

<form wire:submit="saveSharing" wire:poll.5s class="flex flex-1 flex-col">
    <h3 class="text-xl font-bold tracking-tight text-gray-950 dark:text-white">
        {{ __('filament/pages/workspaces.setup_workspace.sharing.heading') }}
    </h3>
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
        @if ($mailbox?->isActive())
            {{ __('filament/pages/workspaces.setup_workspace.sharing.syncing') }}
        @endif
        {{ __('filament/pages/workspaces.setup_workspace.sharing.description') }}
    </p>

    @if ($mailbox)
        <div class="mt-5 flex items-center gap-2.5 text-sm font-medium text-gray-950 dark:text-white">
            <span class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-white ring-1 ring-gray-950/10 dark:ring-white/15">
                @include('filament.pages.setup-workspace.provider-logo', ['provider' => $mailbox->provider->value])
            </span>
            <span class="truncate">{{ $mailbox->email_address }}</span>
            @if ($mailbox->isActive())
                <span class="ms-auto flex shrink-0 items-center gap-1 text-xs font-medium text-success-700 dark:text-success-400">
                    <x-filament::icon icon="ri-checkbox-circle-fill" class="size-3.5" />
                    {{ __('filament/pages/workspaces.setup_workspace.sharing.connected') }}
                </span>
            @endif
        </div>
    @endif

    <div class="mt-5 flex flex-col gap-2.5" role="radiogroup" aria-label="{{ __('filament/pages/workspaces.setup_workspace.sharing.heading') }}">
        @foreach ($this->sharingOptions() as $value => $option)
            <label
                data-tier="{{ $value }}"
                @class([
                    'flex cursor-pointer items-center gap-3.5 rounded-xl bg-white p-3 pe-3.5 dark:bg-gray-900',
                    'ring-2 ring-primary-600 outline-4 outline-offset-2 outline-primary-600/15 dark:ring-primary-500 dark:outline-primary-500/20' => $sharingTier === $value,
                    'ring-1 ring-gray-950/10 dark:ring-white/10' => $sharingTier !== $value,
                ])
            >
                <span class="flex h-16 w-21 shrink-0 flex-col gap-1.5 overflow-hidden rounded-lg bg-gray-50 px-2 pt-2 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10" aria-hidden="true">
                    <span class="flex items-center gap-1">
                        <span class="size-2.5 shrink-0 rounded-full bg-primary-300"></span>
                        <span class="h-1 w-8 rounded-full bg-gray-400"></span>
                    </span>
                    @if ($option['subjectShown'])
                        <span class="h-1.5 w-12 rounded-full bg-gray-700 dark:bg-gray-200"></span>
                    @else
                        <x-filament::icon icon="ri-lock-line" class="size-2.5 text-gray-400" />
                    @endif
                    <span class="h-1 w-full rounded-full bg-gray-200 dark:bg-white/10"></span>
                    <span class="h-1 w-2/3 rounded-full bg-gray-200 dark:bg-white/10"></span>
                </span>

                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-semibold text-gray-950 dark:text-white">{{ $option['label'] }}</span>
                    <span class="mt-0.5 block text-sm/4.5 text-gray-500 dark:text-gray-400">{{ $option['description'] }}</span>
                </span>

                <input type="radio" name="sharingTier" wire:model.live="sharingTier" value="{{ $value }}" class="fi-radio-input shrink-0" />
            </label>
        @endforeach
    </div>

    @error('sharingTier')
        <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
    @enderror

    <div class="mt-auto pt-6">
        <x-filament::button type="submit" size="lg" class="w-full" wire:target="saveSharing">
            {{ __('filament/pages/workspaces.create_workspace.actions.continue') }}
        </x-filament::button>
        <p class="mt-3 flex items-center justify-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
            <x-filament::icon icon="ri-lock-line" class="size-3.5" />
            {{ __('filament/pages/workspaces.setup_workspace.sharing.footer') }}
        </p>
    </div>
</form>
