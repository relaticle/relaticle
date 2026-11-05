@php
    use Illuminate\Support\Js;
    use Relaticle\Flowforge\Support\ColorResolver;
    use function Filament\Support\get_color_css_variables;
@endphp

<div role="radiogroup" class="divide-y divide-gray-950/5 border-y border-gray-950/5 dark:divide-white/10 dark:border-white/10">
    @foreach ($columns as $column)
        @php
            $isCurrent = $column->getName() === $currentColumn;
            $dotColor = ColorResolver::resolve($column->getColor());
        @endphp

        <button
            type="button"
            role="radio"
            aria-checked="{{ $isCurrent ? 'true' : 'false' }}"
            @disabled($isCurrent)
            wire:click="callMountedAction({{ Js::from(['to' => $column->getName()]) }})"
            wire:loading.attr="disabled"
            class="flex w-full items-center gap-3 px-1 py-3 text-start text-sm text-gray-950 outline-none transition hover:bg-gray-50 focus-visible:bg-gray-50 disabled:hover:bg-transparent max-sm:text-base dark:text-white dark:hover:bg-white/5 dark:focus-visible:bg-white/5"
        >
            <span
                @class(['size-2 shrink-0 rounded-full', 'bg-(--color-500)' => $dotColor, 'bg-gray-400' => ! $dotColor])
                @if ($dotColor) style="{{ get_color_css_variables($dotColor, shades: [500]) }}" @endif
            ></span>

            <span class="flex-1 truncate">{{ $column->getLabel() }}</span>

            <span
                @class([
                    'size-4 shrink-0 rounded-full border',
                    'border-[5px] border-primary-600 dark:border-primary-500' => $isCurrent,
                    'border-gray-300 dark:border-white/20' => ! $isCurrent,
                ])
            ></span>
        </button>
    @endforeach
</div>
