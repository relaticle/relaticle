<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <x-turnstile.scripts />

    <x-turnstile
        wire:model="{{ $getStatePath() }}"
        data-action="register"
        data-theme="auto"
    />
</x-dynamic-component>
