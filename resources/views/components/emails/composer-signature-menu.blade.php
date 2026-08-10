@props([
    'options' => [],
    'selected' => null,
])

<x-filament::dropdown placement="top-start">
    <x-slot name="trigger">
        <button
            type="button"
            class="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800"
            aria-label="{{ __('filament/emails/composer.actions.signature') }}"
            x-tooltip="{ content: @js(__('filament/emails/composer.actions.signature')), theme: $store.theme }"
        >
            <x-heroicon-o-pencil-square class="h-4 w-4" />
        </button>
    </x-slot>

    <x-filament::dropdown.list>
        <x-filament::dropdown.list.item
            wire:click="$set('signatureId', null)"
            :color="filled($selected) ? 'gray' : 'primary'"
        >
            {{ __('filament/emails/composer.fields.signature_none') }}
        </x-filament::dropdown.list.item>

        @foreach ($options as $id => $name)
            <x-filament::dropdown.list.item
                wire:click="$set('signatureId', '{{ $id }}')"
                :color="$selected === (string) $id ? 'primary' : 'gray'"
            >
                {{ $name }}
            </x-filament::dropdown.list.item>
        @endforeach
    </x-filament::dropdown.list>
</x-filament::dropdown>
