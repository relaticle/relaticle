@use('Illuminate\Support\Js')

<div class="fi-board-stage-menu flex flex-col gap-2" data-stage-menu="{{ $id }}" x-data="{ swatches: false }">
    @if ($editable)
        <div class="relative" data-stage-edit="{{ $id }}">
            <div
                class="flex h-9 items-center gap-2.5 rounded-menu border border-gray-200 px-3 transition-colors has-[input:focus]:border-primary-500 dark:border-white/10 dark:has-[input:focus]:border-primary-400"
                x-on:mousedown.self.prevent="$el.querySelector('[data-stage-name-input]').focus()"
            >
                <button
                    type="button"
                    class="size-3.5 shrink-0 rounded-full"
                    style="{{ $dot }}; background-color: var(--color-500)"
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
                    class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm font-semibold text-gray-950 outline-none focus:ring-0 dark:text-white"
                    data-stage-name-input
                    data-autofocus
                    aria-label="{{ __('filament/pages/boards.stage_menu.name', ['noun' => $noun]) }}"
                    x-on:keydown.enter.prevent="$el.blur()"
                    x-on:dropdown-escape="$el.value = $el.defaultValue"
                    x-on:blur="$el.value.trim() !== $el.defaultValue && $wire.renameBoardColumn({{ Js::from($id) }}, $el.value)"
                />
            </div>

            <div
                class="absolute top-full left-2.5 z-10 -mt-1.5 grid w-max grid-cols-4 gap-2 rounded-menu bg-white p-2 shadow-lg ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                data-stage-swatches
                x-show="swatches"
                x-cloak
                x-on:click.outside="swatches = false"
            >
                @foreach ($swatches as $swatch)
                    <button
                        type="button"
                        class="fi-board-stage-swatch size-6 rounded-md"
                        style="{{ $swatch['style'] }}; background-color: var(--color-500)"
                        data-stage-swatch="{{ $swatch['value'] }}"
                        aria-pressed="{{ $swatch['current'] ? 'true' : 'false' }}"
                        aria-label="{{ __('filament/pages/boards.stage_menu.set_color', ['color' => $swatch['label']]) }}"
                        x-on:click="swatches = false; $wire.setBoardColumnColor({{ Js::from($id) }}, {{ Js::from($swatch['value']) }})"
                    ></button>
                @endforeach
            </div>
        </div>

        @if (filled($error))
            <p class="text-xs text-danger-600 dark:text-danger-400" role="alert" data-stage-error>{{ $error }}</p>
        @endif
    @else
        <div class="flex items-center gap-2.5 text-sm font-semibold text-gray-950 dark:text-white" data-stage-name>
            @if ($dot)
                <span class="size-3.5 shrink-0 rounded-full" style="{{ $dot }}; background-color: var(--color-500)"></span>
            @endif
            <span class="min-w-0 truncate">{{ $name }}</span>
        </div>
    @endif
</div>
