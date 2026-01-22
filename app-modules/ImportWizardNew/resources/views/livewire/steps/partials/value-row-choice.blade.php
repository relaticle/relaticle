{{-- Choice field value row: Select dropdown for single/multi choice fields --}}
@php
    $isMulti = $this->isSelectedColumnMultiChoice;
    $options = $this->selectedColumnOptions;
    $currentValue = $hasCorrection ? $mappedValue : $rawValue;
    $isValidChoice = $this->isValidChoiceValue($currentValue);
    $normalizedValue = $this->normalizeChoiceValue($currentValue);
    $rowKey = 'choice-' . crc32($rawValue);
@endphp

@if ($isValidChoice)
    {{-- VALID CHOICE: Select dropdown --}}
    <div
        wire:key="{{ $rowKey }}"
        x-data="{
            selected: @js($normalizedValue),
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
        <div class="flex-1 min-w-0">
            <x-select-menu
                :options="$options"
                :multiple="$isMulti"
                :searchable="count($options) > 5"
                :value="$normalizedValue"
                :borderless="true"
                placeholder="Select..."
                @input="selected = $event.detail; updateValue($event.detail)"
            />
        </div>
        <x-import-wizard-new::value-row-actions
            :selected-column="$selectedColumn"
            :raw-value="$rawValue"
            :has-correction="$hasCorrection"
        />
    </div>
@else
    {{-- INVALID CHOICE: Warning-styled container with select to fix --}}
    <div
        wire:key="{{ $rowKey }}"
        x-data="{
            selected: @js($normalizedValue),
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
        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-4 h-4 text-warning-500 shrink-0 ml-2"/>
        <div class="flex-1 min-w-0">
            <x-select-menu
                :options="$options"
                :multiple="$isMulti"
                :searchable="count($options) > 5"
                :value="$normalizedValue"
                :borderless="true"
                placeholder="Select to fix..."
                @input="selected = $event.detail; updateValue($event.detail)"
            />
        </div>
        <x-import-wizard-new::value-row-actions
            :selected-column="$selectedColumn"
            :raw-value="$rawValue"
            :has-correction="$hasCorrection"
            :show-undo="false"
        />
    </div>
@endif
