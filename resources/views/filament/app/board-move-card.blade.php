@php
    use Illuminate\Support\Js;
    use Relaticle\Flowforge\Support\ColorResolver;
    use function Filament\Support\get_color_css_variables;
@endphp

<x-filament::dropdown.list class="-mx-2 p-0!">
    @foreach ($columns as $column)
        @php
            $isCurrent = $column->getName() === $currentColumn;
            $dotColor = ColorResolver::resolve($column->getColor());
        @endphp

        <x-filament::dropdown.list.item
            :disabled="$isCurrent"
            :badge="$isCurrent ? __('filament/pages/boards.move_to.current') : null"
            badge-color="gray"
            wire:click="callMountedAction({{ Js::from(['to' => $column->getName()]) }})"
            class="max-sm:py-3 max-sm:text-base"
        >
            <span class="flex items-center gap-3">
                <span
                    @class(['size-2 shrink-0 rounded-full', 'bg-(--color-500)' => $dotColor, 'bg-gray-400' => ! $dotColor])
                    @if ($dotColor) style="{{ get_color_css_variables($dotColor, shades: [500]) }}" @endif
                ></span>
                {{ $column->getLabel() }}
            </span>
        </x-filament::dropdown.list.item>
    @endforeach
</x-filament::dropdown.list>
