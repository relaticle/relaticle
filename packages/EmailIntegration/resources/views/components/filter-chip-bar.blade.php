{{-- One line of chips that scrolls sideways on a phone. A chip with an action opens its picker; a
     typed chip goes back into the search box as text when its label is pressed. --}}
@props(['chips', 'actions', 'editable'])

@php
    use Relaticle\EmailIntegration\Enums\EmailFilter;
@endphp

<div
    role="group"
    aria-label="{{ __('filament/pages/email-inbox.filters.label') }}"
    class="-mx-1 flex items-center gap-2 overflow-x-auto px-1 py-0.5 whitespace-nowrap"
    data-filter-chips
>
    @foreach (EmailFilter::cases() as $filter)
        @php
            $field = $filter->field();
            $value = $chips[$field] ?? null;
            $chipLabel = $value === null
                ? $filter->label()
                : __('filament/pages/email-inbox.filters.chip', ['filter' => $filter->label(), 'value' => $value]);
        @endphp

        @continue($value === null && ! isset($actions[$field]) && $filter !== EmailFilter::HasAttachment)

        <span class="inline-flex shrink-0 items-center gap-0.5" data-filter-chip="{{ $field }}" wire:key="filter-chip-{{ $field }}">
            @if (isset($editable[$field]))
                <x-filament::button
                    size="sm"
                    outlined
                    color="primary"
                    wire:click="editFilter('{{ $field }}')"
                    :tooltip="__('filament/pages/email-inbox.filters.edit', ['filter' => $filter->label()])"
                >
                    {{ $chipLabel }}
                </x-filament::button>
            @elseif (isset($actions[$field]))
                {{ $actions[$field] }}
            @elseif ($filter === EmailFilter::HasAttachment)
                <x-filament::button
                    size="sm"
                    outlined
                    :color="$value === null ? 'gray' : 'primary'"
                    icon="heroicon-m-paper-clip"
                    wire:click="toggleAttachmentFilter"
                    aria-pressed="{{ $value === null ? 'false' : 'true' }}"
                >
                    {{ $filter->label() }}
                </x-filament::button>
            @else
                <x-filament::button size="sm" outlined color="primary" tag="span">
                    {{ $chipLabel }}
                </x-filament::button>
            @endif

            @if ($value !== null)
                <x-filament::icon-button
                    icon="heroicon-m-x-mark"
                    size="sm"
                    color="gray"
                    :label="__('filament/pages/email-inbox.filters.remove', ['filter' => $filter->label()])"
                    wire:click="removeFilter('{{ $field }}')"
                />
            @endif
        </span>
    @endforeach

    @if (count($chips) >= 2)
        <x-filament::link tag="button" size="sm" color="gray" wire:click="clearFilters" class="shrink-0">
            {{ __('filament/pages/email-inbox.filters.clear_all') }}
        </x-filament::link>
    @endif
</div>
