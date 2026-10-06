@php
    $inviteLinkUrl = $this->inviteLinkUrl();
    $copyInviteLink = $inviteLinkUrl === null
        ? null
        : 'navigator.clipboard.writeText('.\Illuminate\Support\Js::from($inviteLinkUrl).').then(() => { copied = true; setTimeout(() => copied = false, 2000) })';
@endphp

<form wire:submit="finish" class="flex flex-1 flex-col">
    <h3 class="text-xl font-bold tracking-tight text-gray-950 dark:text-white">
        {{ __('filament/pages/workspaces.setup_workspace.invite.heading') }}
    </h3>
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
        {{ __('filament/pages/workspaces.setup_workspace.invite.description') }}
    </p>

    <div class="mt-6">
        {{ $this->form }}

        <p class="mt-2 text-sm text-pretty text-gray-600 dark:text-gray-400">
            {{ __('filament/pages/workspaces.setup_workspace.invite.helper') }}
        </p>
    </div>

    <div class="mt-5 flex items-center justify-between gap-3 border-t border-gray-950/5 pt-4 text-sm text-gray-700 dark:border-white/10 dark:text-gray-300">
        <span class="flex items-center gap-2">
            <x-filament::icon icon="ri-link" class="size-4 text-gray-400" />
            {{ __('filament/pages/workspaces.setup_workspace.invite.link_label') }}
        </span>

        @if ($inviteLinkUrl !== null)
            <x-filament::button
                type="button"
                color="gray"
                size="sm"
                outlined
                wire:target="createInviteLink"
                x-data="{ copied: false }"
                x-on:click="{{ $copyInviteLink }}"
            >
                <span x-show="! copied">{{ __('filament/pages/workspaces.setup_workspace.invite.copy_link') }}</span>
                <span x-show="copied" x-cloak>{{ __('workspaces.invite_link.copied') }}</span>
            </x-filament::button>
        @else
            <x-filament::button
                type="button"
                color="gray"
                size="sm"
                outlined
                wire:click="createInviteLink"
                wire:target="createInviteLink"
            >
                {{ __('filament/pages/workspaces.setup_workspace.invite.create_link') }}
            </x-filament::button>
        @endif
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
