{{-- Multi-value arbitrary field row: For email, phone, tags etc. --}}
@php
    use Relaticle\ImportWizard\Support\Validation\ValidationError;

    $storedError = $valueData->validation_error;
    $validationError = ValidationError::fromStorageFormat($storedError);
    $perValueErrors = $validationError?->getItemErrors() ?? [];
    $hasErrors = !empty($perValueErrors);

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
    $rowKey = 'multi-value-' . crc32($rawValue . '|' . $mappedValue);
@endphp

<div
    wire:key="{{ $rowKey }}"
    x-data="{ rawValue: @js($rawValue) }"
    x-on:multi-value-change="$wire.updateMappedValue(rawValue, $event.detail)"
    class="flex-1 min-w-0 flex items-center rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800"
>
    <div class="flex-1 min-w-0">
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
        :show-undo="$hasErrors || $hasCorrection"
    />
</div>
