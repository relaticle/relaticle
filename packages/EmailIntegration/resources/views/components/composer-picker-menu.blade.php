@props([
    'icon',
    'label',
    'options' => [],
    'selected' => null,
    'noneLabel' => null,
    'emptyLabel' => null,
    /** 'wire' binds the expression to wire:click, 'alpine' to x-on:click. */
    'handler' => 'wire',
    /** Builds the click expression for an option id (null = the "none" row). */
    'click',
    /** Optional trailing row that creates a new option; bound to wire:click. */
    'createLabel' => null,
    'createClick' => null,
])

@php
    $bind = fn (?string $id): array => [$handler === 'alpine' ? 'x-on:click' : 'wire:click' => $click($id)];
@endphp

<x-filament::dropdown placement="top-start">
    <x-slot name="trigger">
        <button
            type="button"
            class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/10 dark:hover:text-gray-200"
            aria-label="{{ $label }}"
            x-tooltip="{ content: @js($label), theme: $store.theme }"
        >
            <x-dynamic-component :component="$icon" class="h-4 w-4" />
        </button>
    </x-slot>

    @if ($options !== [] || filled($emptyLabel))
        <x-filament::dropdown.list>
            @foreach ($options as $id => $name)
                <x-filament::dropdown.list.item
                    :attributes="new \Illuminate\View\ComponentAttributeBag($bind((string) $id))"
                    :color="$selected === (string) $id ? 'primary' : 'gray'"
                >
                    {{ $name }}
                </x-filament::dropdown.list.item>
            @endforeach

            @if ($options === [] && filled($emptyLabel))
                <p class="composer-picker-note">{{ $emptyLabel }}</p>
            @endif
        </x-filament::dropdown.list>
    @endif

    @if (filled($noneLabel))
        <x-filament::dropdown.list>
            <button
                type="button"
                @class([
                    'composer-picker-note',
                    'is-current' => blank($selected),
                ])
                {!! new \Illuminate\View\ComponentAttributeBag($bind(null)) !!}
            >
                {{ $noneLabel }}
            </button>
        </x-filament::dropdown.list>
    @endif

    @if (filled($createLabel))
        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item wire:click="{{ $createClick }}" icon="heroicon-m-plus" color="gray">
                {{ $createLabel }}
            </x-filament::dropdown.list.item>
        </x-filament::dropdown.list>
    @endif
</x-filament::dropdown>
