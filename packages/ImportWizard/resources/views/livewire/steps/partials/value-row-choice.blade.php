{{-- Choice field value row: Select dropdown for single/multi choice fields --}}
@php
    $isMulti = $selectedColumn->getType()->isMultiChoiceField();
    $validOptions = $this->choiceOptions;
    $validationError = $valueData->validation_error;
    $isValid = $validationError === null;

    $canonicalOptionValue = function (string $value) use ($validOptions): string {
        foreach ($validOptions as $option) {
            if (mb_strtolower((string) $option['value']) === mb_strtolower($value)) {
                return (string) $option['value'];
            }
        }

        return $value;
    };

    // Parse values - trim handles both "a, b" and "a,b" formats
    $selectedValue = $isMulti
        ? collect(explode(',', $mappedValue))->map(fn ($v) => trim($v))->filter()->map($canonicalOptionValue)->values()->all()
        : $canonicalOptionValue($mappedValue);

    // Build options: for multi-select include invalid values first, for single-select only valid
    // Single-select: user must select valid option or skip (no deselecting invalid)
    $validValues = collect($validOptions)->pluck('value');
    $invalidOptions = $isMulti
        ? collect((array) $selectedValue)
            ->filter(fn ($v) => $v && ! $validValues->contains($v))
            ->unique()
            ->map(fn ($v) => ['value' => $v, 'label' => $v, 'invalid' => true])
            ->values()
        : collect();

    $options = $invalidOptions->merge($validOptions)->all();

    $rowKey = 'choice-' . crc32($rawValue . '|' . $mappedValue);
    $suggestion = $isValid ? null : ($this->suggestions[$rawValue] ?? null);
@endphp

<div
    wire:key="{{ $rowKey }}"
    x-data="{
        selected: @js($selectedValue),
        isMulti: @js($isMulti),
        rawValue: @js($rawValue),
        updateValue(newVal) {
            if (this.isMulti && Array.isArray(newVal) && newVal.length === 0) {
                $wire.skipValue(this.rawValue);
                return;
            }
            const value = this.isMulti && Array.isArray(newVal) ? newVal.join(', ') : (newVal ?? '');
            $wire.updateMappedValue(this.rawValue, value);
        }
    }"
    class="flex-1 min-w-0 flex items-center rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800"
>
    {{-- Show warning icon only for multi-select (single-select has badge inside) --}}
    @if (! $isValid)
        <span
            x-tooltip="{ content: @js($validationError . ' Select a valid option from the dropdown.'), theme: $store.theme }"
            class="ml-2 cursor-help"
        >
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-4 h-4 text-warning-500 shrink-0"/>
        </span>
    @endif

    <div class="flex-1 min-w-0" wire:ignore>
        <x-import-wizard-new::select-menu
            :options="$options"
            :multiple="$isMulti"
            :searchable="count($options) > 5"
            :value="$selectedValue"
            :borderless="true"
            :placeholder="$isValid ? 'Select...' : 'Select to fix...'"
            @input="selected = $event.detail; if (!isMulti) updateValue($event.detail)"
            @change="if (isMulti) updateValue($event.detail)"
        />
    </div>

    @if ($suggestion !== null)
        <x-filament::button
            size="xs"
            color="gray"
            outlined
            class="shrink-0 mx-2"
            wire:click.stop.preserve-scroll="acceptSuggestion({{ Js::from($rawValue) }})"
        >
            {{ __('import-wizard-new::review.use_suggestion', ['value' => $suggestion]) }}
        </x-filament::button>
    @endif

    <x-import-wizard-new::value-row-actions
        :selected-column="$selectedColumn"
        :raw-value="$rawValue"
        :has-correction="$hasCorrection"
        :show-undo="$hasCorrection"
    />
</div>
