@php
    /** @var string $code */
    /** @var string $label */
    /** @var bool $on */
    /** @var bool $disabled */

    $onClasses = \Illuminate\Support\Arr::toCssClasses([
        'fi-toggle-on',
        ...\Filament\Support\get_component_color_classes(\Filament\Support\View\Components\ToggleComponent::class, 'primary'),
    ]);
    $offClasses = \Illuminate\Support\Arr::toCssClasses([
        'fi-toggle-off',
        ...\Filament\Support\get_component_color_classes(\Filament\Support\View\Components\ToggleComponent::class, 'gray'),
    ]);
@endphp

<button
    x-data="{ state: {{ $on ? 'true' : 'false' }} }"
    x-bind:aria-checked="state ? 'true' : 'false'"
    x-bind:class="state ? @js($onClasses) : @js($offClasses)"
    @if ($on)
        x-cloak
    @endif
    role="switch"
    type="button"
    class="fi-toggle {{ $on ? $onClasses : $offClasses }}"
    wire:key="inline-boolean-{{ $code }}"
    aria-checked="{{ $on ? 'true' : 'false' }}"
    aria-label="{{ $label }}"
    @disabled($disabled)
    @unless ($disabled)
        x-on:click="state = ! state; $wire.toggleInlineBoolean({{ \Illuminate\Support\Js::from($code) }})"
    @endunless
>
    <div>
        <div aria-hidden="true"></div>
        <div aria-hidden="true"></div>
    </div>
</button>
