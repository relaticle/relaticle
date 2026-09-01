<x-layouts::invitation :title="__('teams.invite_link.expired.heading')">
    <x-slot:icon>
        <x-teams.invitation-icon icon="heroicon-o-clock" />
    </x-slot:icon>

    <x-slot:heading>
        {{ __('teams.invite_link.expired.heading') }}
    </x-slot:heading>

    <x-slot:description>
        {{ __('teams.invite_link.expired.body') }}
    </x-slot:description>

    <x-slot:actions>
        <x-filament::button tag="a" :href="url()->getAppUrl()" class="w-full justify-center">
            {{ __('teams.invite_link.expired.action') }}
        </x-filament::button>
    </x-slot:actions>
</x-layouts::invitation>
