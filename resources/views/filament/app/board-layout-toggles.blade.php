@use('App\Enums\BoardCardSize')
@use('App\Enums\BoardColumnWidth')
@use('Illuminate\Support\Js')

<div class="fi-board-layout-toggles">
    <div class="max-sm:hidden">
        <div class="fi-board-layout-group" role="group" aria-label="{{ __('filament/pages/boards.layout.column_width') }}">
            @foreach (BoardColumnWidth::cases() as $option)
                <button
                    type="button"
                    class="fi-board-layout-option"
                    data-board-column-width-option="{{ $option->value }}"
                    aria-pressed="{{ $columnWidth === $option ? 'true' : 'false' }}"
                    aria-label="{{ $option->label() }}"
                    x-tooltip="{ content: {{ Js::from($option->label()) }}, theme: $store.theme }"
                    wire:click="setBoardColumnWidth('{{ $option->value }}')"
                >
                    <x-filament::icon :icon="$option->icon()" class="size-menu-icon" />
                </button>
            @endforeach
        </div>
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
