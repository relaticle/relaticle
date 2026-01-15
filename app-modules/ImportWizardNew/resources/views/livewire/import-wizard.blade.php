<div class="space-y-6">
    {{-- Step Indicator --}}
    @include('import-wizard-new::livewire.partials.step-indicator')

    {{-- Step Header --}}
    <div class="mt-10 pt-4 border-t border-gray-200 dark:border-gray-700">
        <div class="flex justify-between items-start">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                    {{ $this->getStepTitle() }}
                </h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ $this->getStepDescription() }}
                </p>
            </div>
            <x-filament::button color="gray" wire:click="cancelImport" size="sm">
                Cancel
            </x-filament::button>
        </div>
    </div>

    {{-- Step Content (Child Components) --}}
    <div class="mt-6">
        @if($currentStep === self::STEP_UPLOAD)
            <livewire:import-wizard-new.steps.upload
                :entity-type="$entityType"
                :store-id="$storeId"
                @completed="onUploadCompleted($event.detail.storeId, $event.detail.rowCount, $event.detail.columnCount)"
                :wire:key="'upload-' . ($storeId ?? 'new')"
            />
        @elseif($currentStep === self::STEP_MAP)
            <livewire:import-wizard-new.steps.mapping
                :store-id="$storeId"
                :entity-type="$entityType"
                :wire:key="'map-' . $storeId"
            />
        @elseif($currentStep === self::STEP_REVIEW)
            <livewire:import-wizard-new.steps.review
                :store-id="$storeId"
                :entity-type="$entityType"
                :wire:key="'review-' . $storeId"
            />
        @elseif($currentStep === self::STEP_PREVIEW)
            <livewire:import-wizard-new.steps.preview
                :store-id="$storeId"
                :entity-type="$entityType"
                :wire:key="'preview-' . $storeId"
            />
        @endif
    </div>
</div>
