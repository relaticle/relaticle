@php
    $view = $this->isSavedViewModified() ? $this->getActiveSavedView() : null;
    $mode = match (true) {
        ! $view => null,
        ! $this->canUpdateSavedView($view) => 'copy-only',
        $view->is_shared => 'shared',
        default => 'private',
    };
@endphp

@if ($mode)
    <div class="fi-view-save" data-view-save="{{ $mode }}" wire:key="{{ $this->getId() }}.table.view-save">
        <x-filament::button color="gray" size="sm" class="fi-view-save-discard" data-view-save-action="discard" wire:click="revertSavedView">
            {{ __('filament/panel.tables.views.discard') }}
        </x-filament::button>

        @if ($mode === 'copy-only')
            <x-filament::button size="sm" data-view-save-action="save-as" wire:click="mountAction('saveSavedViewAs')">
                {{ __('filament/panel.tables.views.save_as') }}
            </x-filament::button>
        @else
            <div class="fi-view-save-split">
                <x-filament::button size="sm" data-view-save-action="save" wire:click="mountAction('saveSavedView')">
                    {{ $mode === 'shared' ? __('filament/panel.tables.views.save_for_everyone') : __('filament/panel.tables.views.save') }}
                </x-filament::button>

                <x-filament::dropdown placement="bottom-end" shift>
                    <x-slot name="trigger">
                        <x-filament::button size="sm" icon="lucide-chevron-down" label-sr-only class="fi-view-save-more" :aria-label="__('filament/panel.tables.views.more_ways_to_save')">
                            {{ __('filament/panel.tables.views.more_ways_to_save') }}
                        </x-filament::button>
                    </x-slot>

                    <x-filament::dropdown.list>
                        <x-filament::dropdown.list.item icon="lucide-copy" data-view-save-action="save-as" wire:click="mountAction('saveSavedViewAs')">
                            {{ __('filament/panel.tables.views.save_as') }}
                        </x-filament::dropdown.list.item>
                    </x-filament::dropdown.list>
                </x-filament::dropdown>
            </div>
        @endif
    </div>
@endif
