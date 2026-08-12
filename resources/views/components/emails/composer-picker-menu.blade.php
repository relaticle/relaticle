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

    <x-filament::dropdown.list>
        @if (filled($noneLabel))
            <x-filament::dropdown.list.item
                :attributes="new \Illuminate\View\ComponentAttributeBag($bind(null))"
                :color="filled($selected) ? 'gray' : 'primary'"
            >
                {{ $noneLabel }}
            </x-filament::dropdown.list.item>
        @endif

        @foreach ($options as $id => $name)
            <x-filament::dropdown.list.item
                :attributes="new \Illuminate\View\ComponentAttributeBag($bind((string) $id))"
                :color="$selected === (string) $id ? 'primary' : 'gray'"
            >
                {{ $name }}
            </x-filament::dropdown.list.item>
        @endforeach

        @if ($options === [] && filled($emptyLabel))
            <x-filament::dropdown.list.item tag="div" color="gray">
                {{ $emptyLabel }}
            </x-filament::dropdown.list.item>
        @endif
    </x-filament::dropdown.list>
</x-filament::dropdown>
