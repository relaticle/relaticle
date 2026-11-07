@props([
    'search',
    'canCompose' => false,
])

<div {{ $attributes->class(['flex min-h-full flex-col items-center justify-center']) }}>
    @if (filled($search))
        <x-filament::empty-state
            :heading="__('filament/pages/email-inbox.list_empty.no_results', ['search' => $search])"
            icon="lucide-search"
            icon-color="gray"
            :contained="false"
        />
    @else
        <x-filament::empty-state
            :heading="__('filament/pages/record-emails.empty.heading')"
            :description="__('filament/pages/record-emails.empty.description')"
            icon="lucide-mail"
            icon-color="gray"
            :contained="false"
        >
            @if ($canCompose)
                <x-slot:footer>
                    <x-filament::button
                        icon="lucide-mail"
                        wire:click="$dispatch('composer:open')"
                    >
                        {{ __('filament/pages/record-emails.empty.compose') }}
                    </x-filament::button>
                </x-slot:footer>
            @endif
        </x-filament::empty-state>
    @endif
</div>
