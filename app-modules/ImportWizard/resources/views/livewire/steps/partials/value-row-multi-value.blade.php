{{-- Multi-value arbitrary field row: For email, phone, tags etc. --}}
@php
    use Relaticle\ImportWizard\Support\Validation\ValidationError;

    $storedError = $valueData->validation_error;
    $validationError = ValidationError::fromStorageFormat($storedError);
    $perValueErrors = $validationError?->getItemErrors() ?? [];
    $simpleMessage = $validationError?->getMessage();
    $hasErrors = !empty($perValueErrors) || $simpleMessage !== null;

    $inputType = match(true) {
        str_contains(strtolower($selectedColumn->target), 'email') => 'email',
        str_contains(strtolower($selectedColumn->target), 'phone') => 'tel',
        str_contains(strtolower($selectedColumn->target), 'url') || str_contains(strtolower($selectedColumn->target), 'website') => 'url',
        default => 'text',
    };
    $placeholder = match($inputType) {
        'email' => 'Add email...',
        'tel' => 'Add phone...',
        'url' => 'Add URL...',
        default => 'Add value...',
    };
    $rowKey = 'multi-value-' . crc32($rawValue);
@endphp

<div
    wire:key="{{ $rowKey }}"
    x-data="{
        rawValue: @js($rawValue),
        handleChange(detail) {
            const mvi = this.$el.querySelector('[data-multi-value-input]');
            this.$wire.updateMappedValue(this.rawValue, detail).then(errors => {
                mvi?.dispatchEvent(
                    new CustomEvent('update-errors', { detail: { errors: errors || {} } })
                );
            });
        }
    }"
    x-on:multi-value-change.debounce.300ms="handleChange($event.detail)"
    class="flex-1 min-w-0 flex items-center rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800"
>
    @if ($simpleMessage !== null)
        <span
            x-tooltip="{ content: @js($simpleMessage), theme: $store.theme }"
            class="ml-2 cursor-help shrink-0"
        >
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-4 h-4 text-warning-500"/>
        </span>
    @endif
    <div class="flex-1 min-w-0" wire:ignore>
        <x-import-wizard-new::multi-value-input
            :value="$mappedValue"
            :input-type="$inputType"
            :placeholder="$placeholder"
            :errors="$perValueErrors"
            :borderless="true"
            event-name="multi-value-change"
        />
    </div>

    <x-import-wizard-new::value-row-actions
        :selected-column="$selectedColumn"
        :raw-value="$rawValue"
        :has-correction="$hasCorrection"
        :show-undo="$hasCorrection"
    />
</div>
