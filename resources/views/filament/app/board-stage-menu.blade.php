@use('Illuminate\Support\Js')

<div class="fi-board-stage-menu flex flex-col gap-2" data-stage-menu="{{ $id }}" x-data="{ swatches: false, syncSwitch(wrapper) { const toggle = wrapper.querySelector('[data-visible]'); if (toggle) { window.Alpine.$data(toggle).state = toggle.dataset.visible === 'true' } } }">
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
                    x-ref="colorTrigger"
                    aria-haspopup="true"
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
                    @if ($locked)
                        readonly
                        data-stage-name-locked="{{ $id }}"
                    @else
                        data-autofocus
                    @endif
                    aria-label="{{ __('filament/pages/boards.stage_menu.name', ['noun' => $noun]) }}"
                    x-on:keydown.enter.prevent="$el.blur()"
                    x-on:dropdown-escape="$el.value = $el.defaultValue"
                    x-on:blur="$el.value.trim() !== $el.defaultValue && $wire.renameBoardColumn({{ Js::from($id) }}, $el.value, $el.defaultValue)"
                />
            </div>

            <x-flowforge::modern.color-swatches
                :colors="$swatches"
                :selected="collect($swatches)->firstWhere('current', true)['value'] ?? null"
                :set-color-label="__('filament/pages/boards.stage_menu.set_color', ['color' => ':color'])"
                open="swatches"
                data-stage-swatches
                x-on:swatch-pick="swatches = false; $wire.setBoardColumnColor({{ Js::from($id) }}, $event.detail.value)"
                x-on:swatch-close="swatches = false; $nextTick(() => $refs.colorTrigger.focus())"
                x-on:click.outside="swatches = false"
            />
        </div>

        @if (filled($error))
            <p class="text-xs text-danger-600 dark:text-danger-400" role="alert" data-stage-error>{{ $error }}</p>
        @endif

        <div class="fi-board-stage-confetti">
            <div class="fi-board-switch-row" data-stage-confetti="{{ $id }}" x-on:click="$wire.setBoardColumnCelebrate({{ Js::from($id) }}, {{ $celebrate ? 'false' : 'true' }}).finally(() => syncSwitch($el))">
                <x-filament::icon icon="lucide-party-popper" />
                <span class="fi-board-switch-label">{{ __('filament/pages/boards.stage_menu.confetti') }}</span>
                <span class="fi-board-stage-switch">
                    <x-filament::toggle
                        :state="$celebrate ? 'true' : 'false'"
                        :aria-label="__('filament/pages/boards.stage_menu.confetti_label', ['noun' => $noun])"
                        data-stage-confetti-toggle
                        :data-visible="$celebrate ? 'true' : 'false'"
                    />
                </span>
            </div>
        </div>
    @else
        <div class="flex items-center gap-2.5 text-sm font-semibold text-gray-950 dark:text-white" data-stage-name>
            @if ($dot)
                <span class="size-3.5 shrink-0 rounded-full" style="{{ $dot }}; background-color: var(--color-500)"></span>
            @endif
            <span class="min-w-0 truncate">{{ $name }}</span>
        </div>
    @endif
</div>
