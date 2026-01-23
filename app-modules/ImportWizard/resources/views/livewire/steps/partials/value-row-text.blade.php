{{-- Text value row: Bordered container with text input --}}
@php
    $validationError = $valueData->validation_error;
    $isValid = $validationError === null;
@endphp

<div class="flex-1 flex items-center rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
    @unless ($isValid)
        <span
            x-tooltip="{ content: @js($validationError), theme: $store.theme }"
            class="ml-2 cursor-help"
        >
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-4 h-4 text-warning-500 shrink-0"/>
        </span>
    @else
        <x-filament::icon icon="heroicon-o-pencil" class="w-4 h-4 text-gray-400 shrink-0 ml-2"/>
    @endunless
    <input
        type="text"
        value="{{ $mappedValue }}"
        wire:change.preserve-scroll="updateMappedValue({{ Js::from($rawValue) }}, $event.target.value)"
        class="flex-1 text-sm pl-2 pr-2 py-1 bg-transparent border-0 text-gray-900 dark:text-white focus:ring-0 focus:outline-none"
    />
    <x-import-wizard-new::value-row-actions
        :selected-column="$selectedColumn"
        :raw-value="$rawValue"
        :has-correction="$hasCorrection"
    />
</div>
