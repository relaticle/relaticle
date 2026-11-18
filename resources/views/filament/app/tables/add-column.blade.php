<span class="fi-ta-add-column">
    <x-filament::dropdown :menu="false" placement="bottom-end" width="xs" :offset="2" shift teleport size class="fi-ta-add-column-dropdown">
        <x-slot name="trigger">
            <button type="button" class="fi-ta-add-column-trigger" data-add-column-trigger>
                <x-filament::icon icon="lucide-plus" />
                <span>{{ __('filament/panel.tables.views.add_column') }}</span>
            </button>
        </x-slot>

        @include('filament.app.tables.add-column-menu')
    </x-filament::dropdown>
</span>
