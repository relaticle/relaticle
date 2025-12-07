<div>
    {{-- Step Progress (Clickable) --}}
    <nav class="mb-8" aria-label="Progress">
        <ol role="list" class="flex items-center">
            @foreach ($this->getStepLabels() as $step => $label)
                @php
                    $isClickable = $step <= $currentStep;
                @endphp
                <li @class(['relative', 'flex-1' => $step < 4])>
                    <div class="flex items-center">
                        <button
                            type="button"
                            @if($isClickable)
                                wire:click="goToStep({{ $step }})"
                            @endif
                            @class([
                                'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-medium transition-colors',
                                'bg-primary-600 text-white ring-2 ring-primary-600' => $currentStep === $step,
                                'bg-primary-600 text-white hover:bg-primary-700 cursor-pointer' => $currentStep > $step,
                                'border-2 border-gray-300 text-gray-500 dark:border-gray-600 dark:text-gray-400 cursor-default' => $currentStep < $step,
                            ])
                            @disabled(!$isClickable)
                        >
                            @if ($currentStep > $step)
                                <x-filament::icon icon="heroicon-s-check" class="h-4 w-4" />
                            @else
                                {{ $step }}
                            @endif
                        </button>
                        <button
                            type="button"
                            @if($isClickable)
                                wire:click="goToStep({{ $step }})"
                            @endif
                            @class([
                                'ml-3 text-sm font-medium hidden sm:block',
                                'text-gray-950 dark:text-white' => $currentStep >= $step,
                                'text-gray-500 dark:text-gray-400' => $currentStep < $step,
                                'hover:underline cursor-pointer' => $isClickable,
                                'cursor-default' => !$isClickable,
                            ])
                            @disabled(!$isClickable)
                        >
                            {{ $label }}
                        </button>
                        @if ($step < 4)
                            <div @class([
                                'ml-4 h-0.5 flex-1',
                                'bg-primary-600' => $currentStep > $step,
                                'bg-gray-200 dark:bg-gray-700' => $currentStep <= $step,
                            ])></div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </nav>

    {{-- Step Content --}}
    @switch($currentStep)
        @case(1)
            @include('livewire.import.partials.step-upload')
            @break
        @case(2)
            @include('livewire.import.partials.step-map')
            @break
        @case(3)
            @include('livewire.import.partials.step-review')
            @break
        @case(4)
            @include('livewire.import.partials.step-preview')
            @break
    @endswitch

    {{-- Value Correction Modal --}}
    @if ($showCorrectionModal)
        <div
            x-data="{ open: true }"
            x-show="open"
            x-on:keydown.escape.window="$wire.cancelCorrection()"
            class="fixed inset-0 z-50 flex items-center justify-center p-4"
        >
            {{-- Backdrop --}}
            <div
                class="fixed inset-0 bg-gray-950/50 dark:bg-gray-950/75"
                x-on:click="$wire.cancelCorrection()"
            ></div>

            {{-- Modal --}}
            <div class="relative w-full max-w-md rounded-xl bg-white shadow-xl dark:bg-gray-900">
                <div class="p-6">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                        Correct Value
                    </h3>

                    <div class="mt-4 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Original Value</label>
                            <div class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-950 dark:bg-gray-800 dark:text-white">
                                {{ $correctionOldValue ?: '(blank)' }}
                            </div>
                        </div>
                        <div>
                            <label for="correction-new-value" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">New Value</label>
                            <x-filament::input.wrapper>
                                <x-filament::input
                                    type="text"
                                    id="correction-new-value"
                                    wire:model="correctionNewValue"
                                    placeholder="Enter corrected value"
                                />
                            </x-filament::input.wrapper>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end gap-3">
                        <x-filament::button color="gray" wire:click="cancelCorrection">
                            Cancel
                        </x-filament::button>
                        <x-filament::button wire:click="saveCorrection">
                            Save
                        </x-filament::button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
