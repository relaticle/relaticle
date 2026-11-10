@php
    $isModified = $this->isSavedViewModified();
    $canUpdate = $this->canUpdateActiveSavedView();
@endphp

@if ($isModified)
    <div class="fi-ta-views" wire:key="{{ $this->getId() }}.table.views">
        <span class="fi-ta-views-modified">{{ __('filament/panel.tables.views.modified') }}</span>

        <div class="fi-ta-views-group">
            <x-filament::button
                color="gray"
                size="sm"
                icon="lucide-undo-2"
                label-sr-only
                :tooltip="__('filament/panel.tables.views.revert')"
                wire:click="revertSavedView"
            >
                {{ __('filament/panel.tables.views.revert') }}
            </x-filament::button>

            @if ($canUpdate)
                <x-filament::button
                    color="gray"
                    size="sm"
                    icon="lucide-check"
                    label-sr-only
                    :tooltip="__('filament/panel.tables.views.save')"
                    class="fi-ta-views-save"
                    wire:click="mountAction('saveSavedView')"
                >
                    {{ __('filament/panel.tables.views.save') }}
                </x-filament::button>
            @endif

            <x-filament::button
                color="gray"
                size="sm"
                icon="lucide-plus"
                label-sr-only
                :tooltip="__('filament/panel.tables.views.save_as')"
                class="fi-ta-views-save-as"
                wire:click="mountAction('saveSavedViewAs')"
            >
                {{ __('filament/panel.tables.views.save_as') }}
            </x-filament::button>
        </div>
    </div>
@endif
