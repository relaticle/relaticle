<div>
    {{-- Step Progress --}}
    @include('import-wizard-new::livewire.partials.step-indicator')

    {{-- Step Content (Child Components) --}}
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
            @completed="nextStep"
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

    {{-- Filament Action Modals --}}
    <x-filament-actions::modals />
</div>
