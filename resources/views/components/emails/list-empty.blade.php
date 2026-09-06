@props([
    'search',
    'folder',
    'canCompose' => false,
])

<div {{ $attributes->class(['flex min-h-full flex-col items-center justify-center']) }}>
    @if (filled($search))
        <x-filament::empty-state
            :heading="__('filament/pages/email-inbox.list_empty.no_results', ['search' => $search])"
            icon="heroicon-o-magnifying-glass"
            icon-color="gray"
            :contained="false"
        />
    @else
        <x-filament::empty-state
            :heading="__('filament/pages/email-inbox.list_empty.'.$folder->value)"
            :description="__('filament/pages/record-emails.empty.description')"
            icon="heroicon-o-envelope"
            icon-color="gray"
            :contained="false"
        >
            @if ($canCompose)
                <x-slot:footer>
                    <x-filament::button
                        icon="heroicon-o-envelope"
                        wire:click="$dispatch('composer:open')"
                    >
                        {{ __('filament/pages/record-emails.empty.compose') }}
                    </x-filament::button>
                </x-slot:footer>
            @endif
        </x-filament::empty-state>
    @endif
</div>
