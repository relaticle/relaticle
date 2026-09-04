@props([
    'disabled' => false,
    'entryId' => null,
    'value',
])

@php
    use Relaticle\EmailIntegration\Enums\EmailVisibilityEnforcement;

    $current = EmailVisibilityEnforcement::from($value);
@endphp

@if ($disabled)
    <span class="text-sm text-gray-950 dark:text-white">
        {{ $current->getLabel() }}
    </span>
@else
    <div
        x-data="{
            open: false,
            top: 0,
            left: 0,
            width: 320,
            toggle() {
                if (! this.open) {
                    const rect = this.$refs.trigger.getBoundingClientRect();

                    this.top = rect.bottom + 4;
                    this.left = rect.left;
                    this.width = Math.max(rect.width, 320);
                }

                this.open = ! this.open;
            },
            close() {
                this.open = false;
            },
        }"
        x-on:keydown.escape.window="close()"
        {{ $attributes->class(['ei-enforcement-picker']) }}
    >
        <button
            type="button"
            x-ref="trigger"
            x-on:click="toggle()"
            x-bind:aria-expanded="open"
            aria-haspopup="listbox"
            class="ei-enforcement-picker-trigger flex w-full min-w-36 items-center justify-between gap-2 rounded-lg bg-white py-1.5 ps-3 pe-3 text-start text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 transition focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/10 dark:focus:ring-primary-500"
        >
            <span class="truncate">{{ $current->getLabel() }}</span>

            <x-filament::icon
                icon="heroicon-m-chevron-up-down"
                class="h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500"
            />
        </button>

        <template x-teleport="body">
            <div
                x-cloak
                x-show="open"
                x-on:click.outside="close()"
                x-transition:enter="transition ease-out duration-100"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-75"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                x-bind:style="`position: fixed; top: ${top}px; left: ${left}px; width: ${width}px; z-index: 50;`"
                role="listbox"
                class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg dark:border-white/10 dark:bg-gray-900"
            >
                @foreach (EmailVisibilityEnforcement::cases() as $option)
                    @php
                        $isSelected = $current === $option;
                    @endphp

                    <button
                        type="button"
                        role="option"
                        aria-selected="{{ $isSelected ? 'true' : 'false' }}"
                        wire:click="updateEnforcement('{{ $entryId }}', @js($option->value))"
                        x-on:click="close()"
                        wire:key="enforcement-option-{{ $entryId }}-{{ $option->value }}"
                        @class([
                            'ei-enforcement-picker-option relative block w-full cursor-pointer px-4 py-3 text-start transition hover:bg-gray-50 dark:hover:bg-white/5',
                            'bg-gray-50 dark:bg-white/5' => $isSelected,
                            'border-b border-gray-200 dark:border-white/10' => ! $loop->last,
                        ])
                    >
                        <span class="block pe-8">
                            <span class="block text-sm font-medium text-gray-950 dark:text-white">
                                {{ $option->getLabel() }}
                            </span>

                            <span class="mt-0.5 block text-sm text-gray-500 dark:text-gray-400">
                                {{ $option->getDescription() }}
                            </span>
                        </span>

                        @if ($isSelected)
                            <x-filament::icon
                                icon="heroicon-s-check-circle"
                                class="absolute end-3 top-3 h-5 w-5 text-primary-600 dark:text-primary-400"
                            />
                        @endif
                    </button>
                @endforeach
            </div>
        </template>
    </div>
@endif
