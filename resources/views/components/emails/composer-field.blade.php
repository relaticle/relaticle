{{-- One row of the header: a fixed label column and whatever the field is.
     `for` renders the row as a <label> so the caption focuses the control. --}}
@props(['label', 'for' => false])

<{{ $for ? 'label' : 'div' }} {{ $attributes->class(['flex items-center gap-3 py-2']) }}>
    <span class="w-14 shrink-0 text-xs font-medium uppercase tracking-wide text-gray-400">{{ $label }}</span>
    {{ $slot }}
</{{ $for ? 'label' : 'div' }}>
