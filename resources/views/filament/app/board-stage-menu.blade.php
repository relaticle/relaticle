@use('App\Enums\OptionColor')
@use('Illuminate\Support\Js')

<div class="fi-board-stage-menu flex flex-col gap-1" data-stage-menu="{{ $id }}" x-data="{ swatches: false }">
    @if ($editable)
        <div class="relative" data-stage-edit="{{ $id }}">
            <div class="flex items-center gap-2 rounded-lg border border-[var(--surface-input-border)] bg-[var(--surface-input-bg)] px-2 py-1.5 focus-within:ring-2 focus-within:ring-primary-500/40">
                <button
                    type="button"
                    class="size-4 shrink-0 rounded-full"
                    style="background-color: {{ $dot }}"
                    data-stage-color-trigger
                    aria-label="{{ __('filament/pages/boards.stage_menu.color', ['noun' => $noun]) }}"
                    x-bind:aria-expanded="swatches.toString()"
                    x-on:click="swatches = ! swatches"
                ></button>
                <input
                    type="text"
                    value="{{ $name }}"
                    maxlength="255"
                    autocomplete="off"
                    class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-gray-950 outline-none focus:ring-0 dark:text-white"
                    data-stage-name-input
                    aria-label="{{ __('filament/pages/boards.stage_menu.name', ['noun' => $noun]) }}"
                    x-on:keydown.enter.prevent="$el.blur()"
                    x-on:blur="$el.value.trim() !== $el.defaultValue && $wire.renameBoardColumn({{ Js::from($id) }}, $el.value)"
                />
            </div>

            <div
                class="absolute inset-x-0 top-full z-10 mt-1 grid grid-cols-4 gap-2 rounded-lg border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)] p-3 shadow-lg"
                data-stage-swatches
                x-show="swatches"
                x-cloak
                x-on:click.outside="swatches = false"
            >
                @foreach (OptionColor::cases() as $color)
                    <button
                        type="button"
                        class="mx-auto size-6 rounded-full ring-offset-2 ring-offset-[var(--surface-block-bg)] aria-pressed:ring-2 aria-pressed:ring-gray-950 dark:aria-pressed:ring-white"
                        style="background-color: {{ $color->value }}"
                        data-stage-swatch="{{ $color->value }}"
                        aria-pressed="{{ $current === $color ? 'true' : 'false' }}"
                        aria-label="{{ __('filament/pages/boards.stage_menu.set_color', ['color' => $color->label()]) }}"
                        x-on:click="swatches = false; $wire.setBoardColumnColor({{ Js::from($id) }}, {{ Js::from($color->value) }})"
                    ></button>
                @endforeach
            </div>
        </div>

        @if (filled($error))
            <p class="px-1 text-xs text-danger-600 dark:text-danger-400" role="alert" data-stage-error>{{ $error }}</p>
        @endif
    @else
        <div class="flex items-center gap-2 px-1 py-1.5 text-sm font-medium text-gray-950 dark:text-white" data-stage-name>
            <span class="size-4 shrink-0 rounded-full" style="background-color: {{ $dot }}"></span>
            <span class="min-w-0 truncate">{{ $name }}</span>
        </div>
    @endif
</div>
