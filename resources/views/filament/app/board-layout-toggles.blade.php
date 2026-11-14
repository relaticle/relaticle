@use('App\Enums\BoardCardSize')
@use('Illuminate\Support\Js')

<div class="fi-board-layout-toggles">
    <div class="fi-board-layout-group" role="group" aria-label="{{ __('filament/pages/boards.layout.columns') }}">
        @foreach (['collapsed' => 'lucide-fold-horizontal', 'expanded' => 'lucide-unfold-horizontal'] as $option => $icon)
            @php($label = __("filament/pages/boards.layout.columns_{$option}"))
            <button
                type="button"
                class="fi-board-layout-option"
                data-board-columns-option="{{ $option }}"
                aria-pressed="{{ $columnsCollapsed === ($option === 'collapsed') ? 'true' : 'false' }}"
                aria-label="{{ $label }}"
                x-tooltip="{ content: {{ Js::from($label) }}, theme: $store.theme }"
                wire:click="setBoardColumnsCollapsed({{ $option === 'collapsed' ? 'true' : 'false' }})"
            >
                <x-filament::icon :icon="$icon" class="size-menu-icon" />
            </button>
        @endforeach
    </div>

    <div class="fi-board-layout-group" role="group" aria-label="{{ __('filament/pages/boards.layout.card_size') }}">
        @foreach (BoardCardSize::cases() as $option)
            <button
                type="button"
                class="fi-board-layout-option"
                data-board-card-size-option="{{ $option->value }}"
                aria-pressed="{{ $cardSize === $option ? 'true' : 'false' }}"
                aria-label="{{ $option->label() }}"
                x-tooltip="{ content: {{ Js::from($option->label()) }}, theme: $store.theme }"
                wire:click="setBoardCardSize('{{ $option->value }}')"
            >
                <x-filament::icon :icon="$option->icon()" class="size-menu-icon" />
            </button>
        @endforeach
    </div>
</div>
