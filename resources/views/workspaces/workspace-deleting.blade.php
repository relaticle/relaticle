<x-layouts::invitation :title="__('workspaces.accept.deleting.heading', ['workspace' => $workspaceName])">
    <x-slot:icon>
        <x-workspaces.invitation-icon icon="lucide-archive-x" />
    </x-slot:icon>

    <x-slot:heading>
        {{ __('workspaces.accept.deleting.heading', ['workspace' => $workspaceName]) }}
    </x-slot:heading>

    <x-slot:description>
        {{ __('workspaces.accept.deleting.body') }}
    </x-slot:description>

    <x-slot:actions>
        <x-filament::button tag="a" :href="url()->getAppUrl()" class="w-full justify-center">
            {{ __('workspaces.accept.deleting.action') }}
        </x-filament::button>
    </x-slot:actions>
</x-layouts::invitation>
