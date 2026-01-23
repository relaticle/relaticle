{{-- Choice field value row: Select dropdown for single/multi choice fields --}}
@php
    $isMulti = $selectedColumn->getType()->isMultiChoiceField();
    $options = $this->choiceOptions;
    $validationError = $valueData->validation_error;
    $isValid = $validationError === null;
    $selectedValue = $isMulti
        ? collect(explode(', ', $mappedValue))->filter()->values()->all()
        : $mappedValue;
    $rowKey = 'choice-' . crc32($rawValue);
@endphp

<div
    wire:key="{{ $rowKey }}"
    x-data="{
        selected: @js($selectedValue),
        options: @js($options),
        isMulti: @js($isMulti),
        rawValue: @js($rawValue),
        csvColumn: @js($selectedColumn),
        updateValue(newVal) {
            const value = this.isMulti && Array.isArray(newVal) ? newVal.join(', ') : newVal;
            $wire.updateMappedValue(this.csvColumn, this.rawValue, value);
        }
    }"
    class="flex-1 min-w-0 flex items-center rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800"
>
    @unless ($isValid)
        <span
            x-tooltip="{ content: @js($validationError . ' Select a valid option from the dropdown.'), theme: $store.theme }"
            class="ml-2 cursor-help"
        >
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-4 h-4 text-warning-500 shrink-0"/>
        </span>
    @endunless

    <div class="flex-1 min-w-0">
        <x-select-menu
            :options="$options"
            :multiple="$isMulti"
            :searchable="count($options) > 5"
            :value="$selectedValue"
            :borderless="true"
            :placeholder="$isValid ? 'Select...' : 'Select to fix...'"
            @input="selected = $event.detail; updateValue($event.detail)"
        />
    </div>

    <x-import-wizard-new::value-row-actions
        :selected-column="$selectedColumn"
        :raw-value="$rawValue"
        :has-correction="$hasCorrection"
        :show-undo="$isValid"
    />
</div>
