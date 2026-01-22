{{-- Date/DateTime value row: Bordered container with date picker --}}
@php
    $isDateTime = $this->isSelectedColumnDateTime;
    $display = $this->selectedColumnDateFormatEnum?->parseForDisplay($rawValue, $mappedValue, $hasCorrection, $isDateTime)
        ?? ['parsed' => null, 'pickerValue' => '', 'formattedDisplay' => ''];
    $parsed = $display['parsed'];
    $pickerValue = $display['pickerValue'];
    $formattedDisplay = $display['formattedDisplay'];
@endphp

<div class="flex-1 flex items-center gap-2">
    @if ($parsed !== null)
        {{-- VALID DATE: Bordered container with hidden date picker --}}
        <div
            x-data
            class="relative flex-1 flex items-center rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800"
        >
            {{-- Invisible date input positioned over clickable area --}}
            <input
                x-ref="picker"
                type="{{ $isDateTime ? 'datetime-local' : 'date' }}"
                value="{{ $pickerValue }}"
                wire:change.preserve-scroll="updateMappedValue({{ Js::from($selectedColumn) }}, {{ Js::from($rawValue) }}, $event.target.value)"
                class="absolute left-0 top-0 w-[calc(100%-4rem)] h-full opacity-0 cursor-pointer [&::-webkit-calendar-picker-indicator]:absolute [&::-webkit-calendar-picker-indicator]:left-0 [&::-webkit-calendar-picker-indicator]:top-0 [&::-webkit-calendar-picker-indicator]:w-full [&::-webkit-calendar-picker-indicator]:h-full [&::-webkit-calendar-picker-indicator]:m-0 [&::-webkit-calendar-picker-indicator]:p-0 [&::-webkit-calendar-picker-indicator]:cursor-pointer"
            />

            {{-- Visible date display --}}
            <div class="flex-1 flex items-center gap-2 px-2 py-1 pointer-events-none">
                <x-filament::icon icon="heroicon-o-calendar" class="w-4 h-4 text-gray-400 shrink-0"/>
                <span class="text-sm text-gray-900 dark:text-white">{{ $formattedDisplay }}</span>
            </div>

            <x-import-wizard-new::value-row-actions
                :selected-column="$selectedColumn"
                :raw-value="$rawValue"
                :has-correction="$hasCorrection"
            />
        </div>
    @else
        {{-- INVALID DATE: Warning-styled container with hidden date picker (same pattern as valid) --}}
        <div
            x-data
            class="relative flex-1 flex items-center rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800"
        >
            {{-- Invisible date input positioned over clickable area --}}
            <input
                x-ref="picker"
                type="{{ $isDateTime ? 'datetime-local' : 'date' }}"
                value=""
                wire:change.preserve-scroll="updateMappedValue({{ Js::from($selectedColumn) }}, {{ Js::from($rawValue) }}, $event.target.value)"
                class="absolute left-0 top-0 w-[calc(100%-4rem)] h-full opacity-0 cursor-pointer [&::-webkit-calendar-picker-indicator]:absolute [&::-webkit-calendar-picker-indicator]:left-0 [&::-webkit-calendar-picker-indicator]:top-0 [&::-webkit-calendar-picker-indicator]:w-full [&::-webkit-calendar-picker-indicator]:h-full [&::-webkit-calendar-picker-indicator]:m-0 [&::-webkit-calendar-picker-indicator]:p-0 [&::-webkit-calendar-picker-indicator]:cursor-pointer"
            />

            {{-- Visible display: warning icon + raw value --}}
            <div class="flex-1 flex items-center gap-2 px-2 py-1 pointer-events-none">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-4 h-4 text-warning-500 shrink-0"/>
                <span class="text-sm text-gray-900 dark:text-white">{{ $rawValue }}</span>
            </div>

            {{-- Actions: Fix (opens picker) → Skip --}}
            <div class="flex items-center bg-gray-50 dark:bg-gray-900 border-l border-gray-200 dark:border-gray-700 shrink-0">
                <button
                    type="button"
                    @click="$refs.picker.showPicker()"
                    class="p-1.5 text-gray-400 hover:text-primary-600 hover:bg-primary-50 dark:hover:text-primary-400 dark:hover:bg-primary-950/50 transition-colors"
                    title="Fix this value"
                >
                    <x-filament::icon icon="heroicon-o-pencil-square" class="w-4 h-4"/>
                </button>
                <div class="w-px h-4 bg-gray-200 dark:bg-gray-700"></div>
                <button
                    wire:click.stop.preserve-scroll="skipValue({{ Js::from($selectedColumn) }}, {{ Js::from($rawValue) }})"
                    class="p-1.5 text-gray-400 hover:text-warning-600 hover:bg-warning-50 dark:hover:text-warning-400 dark:hover:bg-warning-950/50 transition-colors"
                    title="Skip this value"
                >
                    <x-filament::icon icon="heroicon-o-no-symbol" class="w-4 h-4"/>
                </button>
            </div>
        </div>
    @endif
</div>
